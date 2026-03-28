<?php
//
// Mr. Hanf Full Page Cache v8.2.0 - Cron Preloader (Ausfallsicher + Rate-Limited)
//
// Cron-Job der Shop-Seiten abruft und als statische HTML-Dateien speichert.
// Primaere URL-Quelle: sitemap.xml
// Fallback: Aktive Produkte/Kategorien aus der DB
//
// CHANGELOG v8.0.5:
//   - FIX: Verzeichnis-Schutz - erstellt cache/fpc/ + .gitkeep automatisch
//   - FIX: Cronjob-Absicherung gegen fehlendes Verzeichnis
//
// CHANGELOG v8.0.3:
//   - NEU: Kategorie-URLs werden aus DB geladen und priorisiert
//   - NEU: Startseite + statische Seiten werden immer zuerst gecacht
//   - FIX: Kategorien fehlten im Cache weil Sitemap nur Produkte enthaelt
//
// CHANGELOG v8.0.0:
//   - NEU: Erweiterte Validierung (DOCTYPE + <html + <body Pflicht)
//   - NEU: Content-Length Validierung (HTTP vs. tatsaechlich)
//   - NEU: Doppelte Pruefung nach Atomic Write (liest zurueck und validiert)
//   - NEU: Maximale Fehlerquote - stoppt wenn > 20% Fehler (Server-Problem)
//   - VERBESSERT: PHP-Fehler-Erkennung mit Regex (v7.1 Fix beibehalten)
//
// CHANGELOG v7.1.0:
//   - Rate-Limiting (max 2 Requests/Sekunde, konfigurierbar)
//   - Server-Load-Schutz (pausiert wenn Load > Schwellwert)
//   - Adaptive Drosselung (verlangsamt bei hoher TTFB)
//   - Batch-Pausen alle 100 Seiten (30s Erholung)
//   - Maximale Laufzeit-Begrenzung (default 45 Min)
//   - HTML-Validierung: Mindestgroesse + </html> Tag pruefen vor Speichern
//   - Atomic Write: Erst .tmp schreiben, validieren, dann umbenennen
//   - Health-Marker: <!-- FPC-VALID --> wird an jede Cache-Datei angehaengt
//
// @version   8.2.0
// @date      2026-03-22

// ============================================================
// KONFIGURATION (Defaults - werden durch fpc_settings.json ueberschrieben)
// ============================================================
$FPC_DEFAULTS = array(
    // Validierung
    'min_html_size'      => 1000,    // Mindestgroesse fuer gueltiges HTML in Bytes
    'require_closing'    => true,    // Prueft ob </html> oder </body> vorhanden ist
    'require_doctype'    => true,    // Prueft ob <!DOCTYPE oder <html vorhanden
    'require_body'       => true,    // Prueft ob <body vorhanden
    'verify_after_write' => true,    // Liest Cache-Datei nach Schreiben zurueck und validiert
    'max_error_rate'     => 0.20,    // Stoppt wenn mehr als 20% der Requests fehlschlagen
    // Rate-Limiting & Server-Schutz
    'request_delay_ms'   => 500,     // Mindest-Pause zwischen Requests in Millisekunden
    'load_threshold'     => 3.0,     // Server-Load Schwellwert (pausiert wenn hoeher)
    'load_pause_sec'     => 30,      // Pause in Sekunden wenn Load zu hoch
    'batch_size'         => 100,     // Nach X Seiten eine laengere Pause einlegen
    'batch_pause_sec'    => 30,      // Pause zwischen Batches in Sekunden
    'slow_threshold_ms'  => 3000,    // Ab dieser TTFB wird die Pause verdoppelt
    'max_runtime_sec'    => 7200,    // Maximale Laufzeit (2 Stunden)
    'adaptive_enabled'   => true,    // Adaptive Drosselung ein/aus
);

// v9.1.1: Konfiguration aus JSON-Datei laden (geschrieben vom Dashboard Settings-Tab)
$FPC_SETTINGS_FILE = __DIR__ . '/cache/fpc/fpc_settings.json';
$FPC_USER_CONFIG = array();
if (is_file($FPC_SETTINGS_FILE)) {
    $json = @file_get_contents($FPC_SETTINGS_FILE);
    if ($json !== false) {
        $parsed = @json_decode($json, true);
        if (is_array($parsed) && isset($parsed['preloader'])) {
            $FPC_USER_CONFIG = $parsed['preloader'];
            echo '[FPC] Settings aus fpc_settings.json geladen' . "\n";
        }
    }
}

// Merge: User-Config ueberschreibt Defaults
function fpc_cfg($key, $defaults, $user_config) {
    if (isset($user_config[$key]) && $user_config[$key] !== '' && $user_config[$key] !== null) {
        return $user_config[$key];
    }
    return isset($defaults[$key]) ? $defaults[$key] : null;
}

$FPC_MIN_HTML_SIZE     = (int) fpc_cfg('min_html_size', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_HEALTH_MARKER     = '<!-- FPC-VALID -->';
$FPC_REQUIRE_CLOSING   = (bool) fpc_cfg('require_closing', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_REQUIRE_DOCTYPE   = (bool) fpc_cfg('require_doctype', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_REQUIRE_BODY      = (bool) fpc_cfg('require_body', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_VERIFY_AFTER_WRITE = (bool) fpc_cfg('verify_after_write', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_MAX_ERROR_RATE    = (float) fpc_cfg('max_error_rate', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_REQUEST_DELAY_MS  = (int) fpc_cfg('request_delay_ms', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_LOAD_THRESHOLD    = (float) fpc_cfg('load_threshold', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_LOAD_PAUSE_SEC    = (int) fpc_cfg('load_pause_sec', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_BATCH_SIZE        = (int) fpc_cfg('batch_size', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_BATCH_PAUSE_SEC   = (int) fpc_cfg('batch_pause_sec', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_SLOW_THRESHOLD_MS = (int) fpc_cfg('slow_threshold_ms', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_MAX_RUNTIME_SEC   = (int) fpc_cfg('max_runtime_sec', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_ADAPTIVE_ENABLED  = (bool) fpc_cfg('adaptive_enabled', $FPC_DEFAULTS, $FPC_USER_CONFIG);

$start_time = time();

$shop_dir = __DIR__ . '/';
if (!is_file($shop_dir . 'includes/configure.php')) {
    die('[FPC] FEHLER: configure.php nicht gefunden in ' . $shop_dir . "\n");
}

define('_VALID_XTC', true);
require_once($shop_dir . 'includes/configure.php');

$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($db->connect_error) {
    die('[FPC] DB-Fehler: ' . $db->connect_error . "\n");
}
$db->set_charset('utf8');

// Konfiguration aus DB laden
$fpc_config = array();
$result = $db->query("SELECT configuration_key, configuration_value FROM configuration WHERE configuration_key LIKE 'MODULE_MRHANF_FPC_%'");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $fpc_config[$row['configuration_key']] = $row['configuration_value'];
    }
}

if (empty($fpc_config['MODULE_MRHANF_FPC_STATUS']) || $fpc_config['MODULE_MRHANF_FPC_STATUS'] !== 'True') {
    echo '[FPC] Modul ist deaktiviert. Abbruch.' . "\n";
    $db->close();
    exit(0);
}

$cache_ttl   = isset($fpc_config['MODULE_MRHANF_FPC_CACHE_TIME']) ? (int) $fpc_config['MODULE_MRHANF_FPC_CACHE_TIME'] : 86400;
$max_pages   = isset($fpc_config['MODULE_MRHANF_FPC_PRELOAD_LIMIT']) ? (int) $fpc_config['MODULE_MRHANF_FPC_PRELOAD_LIMIT'] : 500;
$excluded    = isset($fpc_config['MODULE_MRHANF_FPC_EXCLUDED_PAGES']) ? $fpc_config['MODULE_MRHANF_FPC_EXCLUDED_PAGES'] : '';
$exclude_arr = array_filter(array_map('trim', explode(',', $excluded)));

// Chrome User-Agent (Reverse-Proxy blockiert unbekannte UAs)
$user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

// Shop-URL ermitteln
$shop_url = defined('HTTPS_SERVER') ? rtrim(HTTPS_SERVER, '/') : '';
if (empty($shop_url)) {
    $shop_url = defined('HTTP_SERVER') ? rtrim(HTTP_SERVER, '/') : '';
}
if (empty($shop_url)) {
    die('[FPC] FEHLER: Shop-URL konnte nicht ermittelt werden.' . "\n");
}
$shop_url = str_replace('http://', 'https://', $shop_url);

// Cache-Verzeichnis (v8.0.5: Verzeichnis-Schutz)
$cache_dir = $shop_dir . 'cache/fpc/';
if (!is_dir($cache_dir)) {
    if (!@mkdir($cache_dir, 0777, true)) {
        die('[FPC] FEHLER: Konnte cache/fpc/ nicht erstellen: ' . $cache_dir . "\n");
    }
    echo '[FPC] Verzeichnis cache/fpc/ neu erstellt.' . "\n";
}
if (!is_file($cache_dir . '.gitkeep')) {
    @file_put_contents($cache_dir . '.gitkeep', '');
}

// Log-Datei
$log_file = $cache_dir . 'preloader.log';

echo '[FPC] Start: ' . date('Y-m-d H:i:s') . "\n";
echo '[FPC] Shop-URL: ' . $shop_url . "\n";
echo '[FPC] Cache-TTL: ' . $cache_ttl . 's | Max: ' . $max_pages . "\n";
echo '[FPC] v8.0 Validierung: Min-Size=' . $FPC_MIN_HTML_SIZE . ' | DOCTYPE=' . ($FPC_REQUIRE_DOCTYPE ? 'Ja' : 'Nein') . ' | Body=' . ($FPC_REQUIRE_BODY ? 'Ja' : 'Nein') . ' | Verify-After-Write=' . ($FPC_VERIFY_AFTER_WRITE ? 'Ja' : 'Nein') . "\n";
echo '[FPC] v7.1 Rate-Limit: ' . $FPC_REQUEST_DELAY_MS . 'ms Pause | Load-Max: ' . $FPC_LOAD_THRESHOLD . ' | Batch: ' . $FPC_BATCH_SIZE . '/' . $FPC_BATCH_PAUSE_SEC . 's | Max-Runtime: ' . $FPC_MAX_RUNTIME_SEC . 's' . "\n";

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

/**
 * Server-Load pruefen (1-Minuten-Durchschnitt)
 */
function fpc_get_server_load() {
    $load = sys_getloadavg();
    return $load ? $load[0] : 0.0;
}

/**
 * Warten bis Server-Load unter Schwellwert ist
 */
function fpc_wait_for_low_load($threshold, $pause_sec, $max_wait = 300) {
    $waited = 0;
    while (fpc_get_server_load() > $threshold && $waited < $max_wait) {
        echo '[FPC] Server-Load ' . sprintf('%.2f', fpc_get_server_load()) . ' > ' . $threshold . ' - Pause ' . $pause_sec . 's...' . "\n";
        sleep($pause_sec);
        $waited += $pause_sec;
    }
    if ($waited > 0) {
        echo '[FPC] Server-Load OK: ' . sprintf('%.2f', fpc_get_server_load()) . ' - Weiter...' . "\n";
    }
    return $waited;
}

/**
 * v8.0: Erweiterte HTML-Validierung
 * Gibt true zurueck wenn HTML gueltig ist, sonst einen Fehlertext
 */
function fpc_validate_html($html, $url, $config) {
    // 1. Mindestgroesse
    if (strlen($html) < $config['min_size']) {
        return 'zu kurz (' . strlen($html) . ' Bytes)';
    }

    // 2. DOCTYPE oder <html> Tag am Anfang (erste 500 Bytes)
    if ($config['require_doctype']) {
        $head = strtolower(substr($html, 0, 500));
        if (strpos($head, '<!doctype') === false && strpos($head, '<html') === false) {
            return 'kein DOCTYPE/HTML-Tag';
        }
    }

    // 3. <body> Tag vorhanden
    if ($config['require_body']) {
        if (stripos($html, '<body') === false) {
            return 'kein <body> Tag';
        }
    }

    // 4. Closing-Tag (</html> oder </body>) am Ende
    if ($config['require_closing']) {
        $lower_tail = strtolower(substr($html, -500));
        if (strpos($lower_tail, '</html>') === false && strpos($lower_tail, '</body>') === false) {
            return 'kein </html> oder </body> Tag';
        }
    }

    // 5. PHP-Fehlermeldungen erkennen (Regex - v7.1 Fix)
    if (preg_match('/<b>(Fatal error|Parse error|Warning|Notice)<\/b>\s*:/i', $html)) {
        return 'PHP-Fehler im HTML';
    }
    if (preg_match('/Smarty error:/i', $html)) {
        return 'Smarty-Fehler im HTML';
    }

    // 6. Leere Seite erkennen (nur Whitespace + minimales HTML)
    $stripped = strip_tags($html);
    $stripped = preg_replace('/\s+/', '', $stripped);
    if (strlen($stripped) < 100) {
        return 'Seite ist leer (nur ' . strlen($stripped) . ' Zeichen Text)';
    }

    return true;
}

// --- URLs sammeln ---
$urls = array();

// 1. Sitemap laden (mit Chrome-UA wegen Reverse-Proxy)
$sitemap_url = $shop_url . '/sitemap.xml';
$ch_sitemap = curl_init($sitemap_url);
curl_setopt_array($ch_sitemap, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => $user_agent,
));
$sitemap_xml = curl_exec($ch_sitemap);
$sitemap_code = curl_getinfo($ch_sitemap, CURLINFO_HTTP_CODE);
curl_close($ch_sitemap);

if ($sitemap_xml !== false && $sitemap_code == 200 && strlen($sitemap_xml) > 100) {
    echo '[FPC] Sitemap geladen: ' . $sitemap_url . ' (' . strlen($sitemap_xml) . ' Bytes)' . "\n";

    // Pruefen ob Sitemap-Index
    if (strpos($sitemap_xml, '<sitemapindex') !== false) {
        preg_match_all('/<loc>(.*?)<\/loc>/i', $sitemap_xml, $matches);
        echo '[FPC] Sitemap-Index mit ' . count($matches[1]) . ' Sub-Sitemaps' . "\n";
        foreach ($matches[1] as $sub_url) {
            $ch_sub = curl_init(trim($sub_url));
            curl_setopt_array($ch_sub, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_USERAGENT      => $user_agent,
            ));
            $sub_xml = curl_exec($ch_sub);
            curl_close($ch_sub);
            if ($sub_xml !== false) {
                preg_match_all('/<loc>(.*?)<\/loc>/i', $sub_xml, $sub_m);
                foreach ($sub_m[1] as $u) {
                    $urls[] = trim($u);
                }
            }
        }
    } else {
        // Einfache Sitemap
        preg_match_all('/<loc>(.*?)<\/loc>/i', $sitemap_xml, $matches);
        foreach ($matches[1] as $u) {
            $urls[] = trim($u);
        }
    }
    echo '[FPC] ' . count($urls) . ' URLs aus Sitemap' . "\n";
} else {
    echo '[FPC] Sitemap nicht verfuegbar (HTTP ' . $sitemap_code . '). Lade aus DB...' . "\n";
}

// 2. Fallback: Aktive Produkte und Kategorien aus DB
if (empty($urls)) {
    echo '[FPC] Lade aktive Produkte/Kategorien aus DB...' . "\n";

    // Startseite
    $urls[] = $shop_url . '/';

    // Aktive Kategorien mit SEO-URL
    $r = $db->query("
        SELECT DISTINCT c.url_text
        FROM clean_seo_url c
        INNER JOIN categories cat ON c.id = cat.categories_id AND c.type = 'categories'
        WHERE cat.categories_status = 1
          AND c.url_text != ''
          AND c.language_id = 2
        LIMIT " . $max_pages);
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $urls[] = $shop_url . '/' . ltrim($row['url_text'], '/');
        }
    }

    // Aktive Produkte mit SEO-URL
    $remaining = $max_pages - count($urls);
    if ($remaining > 0) {
        $r = $db->query("
            SELECT DISTINCT c.url_text
            FROM clean_seo_url c
            INNER JOIN products p ON c.id = p.products_id AND c.type = 'products'
            WHERE p.products_status = 1
              AND c.url_text != ''
              AND c.language_id = 2
            LIMIT " . $remaining);
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $urls[] = $shop_url . '/' . ltrim($row['url_text'], '/');
            }
        }
    }

    echo '[FPC] ' . count($urls) . ' URLs aus DB' . "\n";
}

// ============================================================
// v8.0.3: KATEGORIE-URLs AUS DB PRIORISIEREN
// Die Sitemap enthaelt primaer Produkt-URLs. Kategorie-Seiten
// werden hier aus der DB geladen und VOR die Sitemap-URLs gesetzt,
// damit sie immer gecacht werden (auch wenn das Limit greift).
// ============================================================
$priority_urls = array();

// Startseite immer zuerst
$priority_urls[] = $shop_url . '/';

// Alle aktiven Sprachen ermitteln
$languages = array();
$r_lang = $db->query("SELECT languages_id, code FROM languages WHERE status = 1 ORDER BY sort_order");
if ($r_lang) {
    while ($row = $r_lang->fetch_assoc()) {
        $languages[] = $row;
    }
}

// Kategorie-URLs fuer alle Sprachen laden
foreach ($languages as $lang) {
    $r_cat = $db->query("
        SELECT DISTINCT c.url_text
        FROM clean_seo_url c
        INNER JOIN categories cat ON c.id = cat.categories_id AND c.type = 'categories'
        WHERE cat.categories_status = 1
          AND c.url_text != ''
          AND c.language_id = " . (int)$lang['languages_id'] . "
        ORDER BY cat.sort_order, cat.categories_id
    ");
    if ($r_cat) {
        while ($row = $r_cat->fetch_assoc()) {
            $priority_urls[] = $shop_url . '/' . ltrim($row['url_text'], '/');
        }
    }
}

// Statische Seiten (Info-Seiten, Blog, etc.)
$static_pages = array('/angebote', '/blog/', '/info/kontakt');
foreach ($static_pages as $sp) {
    $priority_urls[] = $shop_url . $sp;
}

echo '[FPC] v8.0.3: ' . count($priority_urls) . ' priorisierte URLs (Startseite + Kategorien + Statisch)' . "\n";

// Priority-URLs VOR Sitemap-URLs setzen, dann deduplizieren
$urls = array_merge($priority_urls, $urls);
$urls = array_unique($urls);

// Filtern (ausgeschlossene Seiten)
$filtered = array();
foreach ($urls as $url) {
    $skip = false;
    foreach ($exclude_arr as $ex) {
        if ($ex !== '' && strpos($url, $ex) !== false) { $skip = true; break; }
    }
    if (!$skip) { $filtered[] = $url; }
}
$filtered = array_slice($filtered, 0, $max_pages);
echo '[FPC] ' . count($filtered) . ' URLs nach Filter (max ' . $max_pages . ')' . "\n";

// --- Preloading mit Rate-Limiting ---
$cached = 0; $skipped = 0; $errors = 0; $invalid = 0;
$load_pauses = 0; $total_ttfb = 0; $ttfb_count = 0;
$current_delay = $FPC_REQUEST_DELAY_MS;
$batch_count = 0;
$total_processed = 0;

// v8.0: Validierungs-Konfiguration fuer Hilfsfunktion
$validate_config = array(
    'min_size'         => $FPC_MIN_HTML_SIZE,
    'require_doctype'  => $FPC_REQUIRE_DOCTYPE,
    'require_body'     => $FPC_REQUIRE_BODY,
    'require_closing'  => $FPC_REQUIRE_CLOSING,
);

// v7.1: Vor dem Start Server-Load pruefen
fpc_wait_for_low_load($FPC_LOAD_THRESHOLD, $FPC_LOAD_PAUSE_SEC);

$ch = curl_init();
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => $user_agent,
    CURLOPT_ENCODING       => '',
    CURLOPT_COOKIE         => '',
));

foreach ($filtered as $i => $url) {

    // v7.1: Maximale Laufzeit pruefen
    if ((time() - $start_time) > $FPC_MAX_RUNTIME_SEC) {
        echo '[FPC] Maximale Laufzeit (' . $FPC_MAX_RUNTIME_SEC . 's) erreicht. Stoppe.' . "\n";
        break;
    }

    $parsed = parse_url($url);
    $path   = isset($parsed['path']) ? $parsed['path'] : '/';
    if ($path === '') $path = '/';

    $clean = trim($path, '/');
    if ($clean === '') {
        $cache_file = $cache_dir . 'index.html';
    } else {
        $cache_file = $cache_dir . $clean . '/index.html';
    }

    // Cache noch gueltig?
    if (is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        $skipped++;
        continue;
    }

    // v7.1: Server-Load pruefen vor jedem 10. Request
    if ($batch_count > 0 && $batch_count % 10 == 0) {
        $load = fpc_get_server_load();
        if ($load > $FPC_LOAD_THRESHOLD) {
            $load_pauses++;
            fpc_wait_for_low_load($FPC_LOAD_THRESHOLD, $FPC_LOAD_PAUSE_SEC);
        }
    }

    // v7.1: Batch-Pause alle X Seiten
    if ($batch_count > 0 && $batch_count % $FPC_BATCH_SIZE == 0) {
        echo '[FPC] Batch-Pause: ' . $FPC_BATCH_PAUSE_SEC . 's Erholung nach ' . $batch_count . ' Requests (Load: ' . sprintf('%.2f', fpc_get_server_load()) . ')' . "\n";
        sleep($FPC_BATCH_PAUSE_SEC);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ttfb = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
    $ttfb_ms = (int)($ttfb * 1000);

    // ==========================================================
    // v8.0.9: REDIRECT-ERKENNUNG
    // Wenn der Server einen Redirect macht (301/302), cached der Preloader
    // die Seite unter der FINALEN URL statt der Original-URL.
    // Ohne diesen Fix wird Content unter einer URL gespeichert die
    // eigentlich redirected werden sollte -> Redirect-Loop fuer Besucher!
    // ==========================================================
    $redirect_count = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    if ($redirect_count > 0) {
        $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $final_parsed = parse_url($final_url);
        $final_path = isset($final_parsed['path']) ? $final_parsed['path'] : '/';
        if ($final_path === '') $final_path = '/';

        $final_clean = trim($final_path, '/');
        if ($final_clean === '') {
            $cache_file = $cache_dir . 'index.html';
        } else {
            $cache_file = $cache_dir . $final_clean . '/index.html';
        }

        echo '[FPC] REDIRECT: ' . $url . ' -> ' . $final_url . ' (Cache unter finaler URL)' . "\n";
    }

    $batch_count++;
    $total_processed++;

    // v7.1: TTFB tracken fuer adaptive Drosselung
    if ($ttfb_ms > 0) {
        $total_ttfb += $ttfb_ms;
        $ttfb_count++;
    }

    // v7.1: Adaptive Drosselung
    if ($FPC_ADAPTIVE_ENABLED && $ttfb_ms > $FPC_SLOW_THRESHOLD_MS) {
        $current_delay = min($current_delay * 2, 5000);
        echo '[FPC] Langsame Antwort (' . $ttfb_ms . 'ms) - Pause erhoeht auf ' . $current_delay . 'ms' . "\n";
    } elseif ($FPC_ADAPTIVE_ENABLED && $ttfb_ms < 1000 && $current_delay > $FPC_REQUEST_DELAY_MS) {
        $current_delay = $FPC_REQUEST_DELAY_MS;
    }

    // HTTP-Fehler oder cURL-Fehler
    if ($html === false || $code != 200) {
        $errors++;
        if ($errors <= 10) {
            echo '[FPC] FEHLER: ' . $url . ' (HTTP ' . $code . ')' . "\n";
        }
        usleep($current_delay * 2 * 1000);

        // v8.0: Fehlerquote pruefen
        if ($total_processed > 20 && ($errors / $total_processed) > $FPC_MAX_ERROR_RATE) {
            echo '[FPC] ABBRUCH: Fehlerquote ' . round(($errors / $total_processed) * 100) . '% > ' . ($FPC_MAX_ERROR_RATE * 100) . '% - Server-Problem?' . "\n";
            break;
        }
        continue;
    }

    // Content-Type pruefen
    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (strpos($ct, 'text/html') === false) {
        $skipped++;
        continue;
    }

    // ==========================================================
    // v8.0: ERWEITERTE HTML-VALIDIERUNG
    // ==========================================================
    $validation = fpc_validate_html($html, $url, $validate_config);
    if ($validation !== true) {
        $invalid++;
        if ($invalid <= 10) {
            echo '[FPC] UNGUELTIG (' . $validation . '): ' . $url . "\n";
        }
        // v8.0: NIEMALS ungueltige Inhalte cachen - bestehende Datei behalten
        continue;
    }

    // === HTML ist gueltig! ===

    // Session-IDs entfernen
    $html = preg_replace('/MODsid=[a-zA-Z0-9]+/', '', $html);

    // Health-Marker und Cache-Kommentar anhaengen
    $html .= "\n" . $FPC_HEALTH_MARKER . "\n";
    $html .= '<!-- FPC cached: ' . date('Y-m-d H:i:s') . ' | v8.0 -->' . "\n";

    // ==========================================================
    // ATOMIC WRITE (Rename-Pattern)
    // ==========================================================
    $dir = dirname($cache_file);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

    $tmp_file = $cache_file . '.tmp.' . getmypid();
    $bytes_written = @file_put_contents($tmp_file, $html);

    if ($bytes_written === false || $bytes_written < $FPC_MIN_HTML_SIZE) {
        @unlink($tmp_file);
        $errors++;
        if ($errors <= 10) {
            echo '[FPC] SCHREIBFEHLER: ' . $cache_file . "\n";
        }
        continue;
    }

    if (!@rename($tmp_file, $cache_file)) {
        @unlink($tmp_file);
        $errors++;
        continue;
    }

    // ==========================================================
    // v8.0: VERIFY AFTER WRITE - Cache-Datei zuruecklesen und pruefen
    // ==========================================================
    if ($FPC_VERIFY_AFTER_WRITE) {
        $verify_content = @file_get_contents($cache_file);
        if ($verify_content === false || strlen($verify_content) < $FPC_MIN_HTML_SIZE) {
            // Geschriebene Datei ist korrupt - sofort loeschen!
            @unlink($cache_file);
            $errors++;
            if ($errors <= 10) {
                echo '[FPC] VERIFY-FEHLER (Datei korrupt nach Schreiben): ' . $cache_file . "\n";
            }
            continue;
        }
        // Health-Marker pruefen
        if (strpos($verify_content, $FPC_HEALTH_MARKER) === false) {
            @unlink($cache_file);
            $errors++;
            if ($errors <= 10) {
                echo '[FPC] VERIFY-FEHLER (kein Health-Marker): ' . $cache_file . "\n";
            }
            continue;
        }
    }

    $cached++;

    // Fortschritt alle 50 Seiten
    if ($cached > 0 && $cached % 50 == 0) {
        $avg_ttfb = $ttfb_count > 0 ? (int)($total_ttfb / $ttfb_count) : 0;
        $runtime = time() - $start_time;
        echo '[FPC] Fortschritt: ' . $cached . ' gecacht | Avg-TTFB: ' . $avg_ttfb . 'ms | Delay: ' . $current_delay . 'ms | Load: ' . sprintf('%.2f', fpc_get_server_load()) . ' | Runtime: ' . $runtime . 's' . "\n";
    }

    // v7.1: Rate-Limiting Pause zwischen Requests
    usleep($current_delay * 1000);
}

curl_close($ch);
$db->close();

$runtime = time() - $start_time;
$avg_ttfb = $ttfb_count > 0 ? (int)($total_ttfb / $ttfb_count) : 0;

$summary = '[FPC] Fertig: ' . date('Y-m-d H:i:s') . "\n"
         . '[FPC] v8.0 | Gecacht: ' . $cached . ' | Uebersprungen: ' . $skipped
         . ' | Ungueltig: ' . $invalid . ' | Fehler: ' . $errors . "\n"
         . '[FPC] Laufzeit: ' . $runtime . 's | Avg-TTFB: ' . $avg_ttfb . 'ms | Load-Pausen: ' . $load_pauses
         . ' | Requests: ' . $batch_count . "\n";
echo $summary;

// Log schreiben
@file_put_contents($log_file, date('Y-m-d H:i:s') . ' ' . $summary, FILE_APPEND);
