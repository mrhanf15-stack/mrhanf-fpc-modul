<?php
//
// Mr. Hanf Full Page Cache v10.7.0 - Cron Preloader (GA4 Priority + Unlimited)
//
// Cron-Job der Shop-Seiten abruft und als statische HTML-Dateien speichert.
// Primaere URL-Quelle: sitemap.xml
// Fallback: Aktive Produkte/Kategorien aus der DB
//
// MODI:
//   php fpc_preloader.php              # Normal: Sitemap durchgehen, neue/abgelaufene cachen
//   php fpc_preloader.php --refresh    # Smart Refresh: Nur bestehende abgelaufene Cache-Dateien erneuern
//   php fpc_preloader.php --priority   # Nur priorisierte URLs (Startseite, Kategorien, Top-Seiten)
//   php fpc_preloader.php --ga4        # GA4 Priority: Top-Seiten aus GA4 zuerst, dann Rest
//   php fpc_preloader.php --full       # Full: ALLE URLs ohne Limit in einem Lauf
//
// CHANGELOG v10.7.0:
//   - NEU: --ga4 Modus - GA4 Top-Pages (letzte 30 Tage) werden zuerst gecacht
//   - NEU: --full Modus - Alle URLs ohne Batch-Limit in einem Lauf
//   - NEU: max_pages=0 bedeutet UNLIMITED (alle URLs)
//   - NEU: Checkpoint alle 100 URLs (Resume bei Abbruch auch mitten im Lauf)
//   - NEU: Optimierte Defaults: 300ms Delay, 15s Batch-Pause, 4h Runtime
//   - NEU: Stale-Only-Refresh im Normal-Modus (bereits gecachte werden uebersprungen)
//   - NEU: Fortschritts-Prozent und ETA-Anzeige
//   - NEU: Lock-File verhindert parallele Laeufe
//   - VERBESSERT: Fehlerquote erst nach 50 Requests pruefen (statt 20)
//   - VERBESSERT: GA4-Cache wird wiederverwendet (kein API-Call wenn < 6h alt)
//
// CHANGELOG v10.4.0:
//   - NEU: --refresh Modus - scannt cache/fpc/ Ordner und erneuert nur abgelaufene Dateien
//   - NEU: --priority Modus - nur Startseite + Kategorien + statische Seiten
//   - NEU: Prioritaets-Queue basierend auf Dateialter (aelteste zuerst)
//   - NEU: Stale-Schutz: Dateien die > 2x TTL alt sind werden priorisiert
//   - NEU: Statistik-Ausgabe am Ende (frisch/abgelaufen/stale Verteilung)
//   - VERBESSERT: Resume-Mechanismus funktioniert auch im Refresh-Modus
//
// CHANGELOG v8.3.0:
//   - NEU: Resume-Mechanismus - Preloader merkt sich wo er aufgehoert hat
//   - NEU: max_pages ist jetzt Batch-Limit pro Lauf (nicht Gesamt-Limit)
//   - NEU: Alle URLs werden ueber mehrere Laeufe hinweg gecacht
//   - NEU: Resume-Position wird in api/fpc/preloader_resume.json gespeichert
//   - NEU: Default max_pages auf 2000 erhoeht (statt 500)
//   - NEU: Automatischer Reset wenn alle URLs gecacht sind
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
// @version   10.7.0
// @date      2026-03-29

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
    // Rate-Limiting & Server-Schutz (v10.7.0: optimierte Defaults)
    'request_delay_ms'   => 300,     // Mindest-Pause zwischen Requests in Millisekunden (war 500)
    'load_threshold'     => 3.0,     // Server-Load Schwellwert (pausiert wenn hoeher)
    'load_pause_sec'     => 20,      // Pause in Sekunden wenn Load zu hoch (war 30)
    'batch_size'         => 100,     // Nach X Seiten eine laengere Pause einlegen
    'batch_pause_sec'    => 15,      // Pause zwischen Batches in Sekunden (war 30)
    'slow_threshold_ms'  => 3000,    // Ab dieser TTFB wird die Pause verdoppelt
    'max_runtime_sec'    => 14400,   // Maximale Laufzeit 4 Stunden (war 2h)
    'adaptive_enabled'   => true,    // Adaptive Drosselung ein/aus
    // v10.7.0: GA4 Einstellungen
    'ga4_top_pages_days' => 30,      // GA4 Top-Pages der letzten X Tage
    'ga4_top_pages_limit'=> 5000,    // Maximale Anzahl GA4 Top-Pages
    'ga4_cache_ttl'      => 21600,   // GA4-Cache TTL: 6 Stunden
);

// v10.3.0: Config-Dateien in geschuetztem config-Ordner
$FPC_CONFIG_DIR = __DIR__ . '/api/fpc/';
if (!is_dir($FPC_CONFIG_DIR)) @mkdir($FPC_CONFIG_DIR, 0755, true);

// Migration: Alte Settings uebernehmen (cache/fpc_config/ oder cache/fpc/)
$FPC_SETTINGS_FILE = $FPC_CONFIG_DIR . 'fpc_settings.json';
if (!is_file($FPC_SETTINGS_FILE)) {
    if (is_file(__DIR__ . '/cache/fpc_config/fpc_settings.json')) {
        @copy(__DIR__ . '/cache/fpc_config/fpc_settings.json', $FPC_SETTINGS_FILE);
        echo '[FPC] Settings von cache/fpc_config/ nach api/fpc/ migriert' . "\n";
    } elseif (is_file(__DIR__ . '/cache/fpc/fpc_settings.json')) {
        @copy(__DIR__ . '/cache/fpc/fpc_settings.json', $FPC_SETTINGS_FILE);
        echo '[FPC] Settings von cache/fpc/ nach api/fpc/ migriert' . "\n";
    }
}
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
$FPC_GA4_DAYS          = (int) fpc_cfg('ga4_top_pages_days', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_GA4_LIMIT         = (int) fpc_cfg('ga4_top_pages_limit', $FPC_DEFAULTS, $FPC_USER_CONFIG);
$FPC_GA4_CACHE_TTL     = (int) fpc_cfg('ga4_cache_ttl', $FPC_DEFAULTS, $FPC_USER_CONFIG);

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
$max_pages   = isset($fpc_config['MODULE_MRHANF_FPC_PRELOAD_LIMIT']) ? (int) $fpc_config['MODULE_MRHANF_FPC_PRELOAD_LIMIT'] : 2000;
// v10.7.0: max_pages=0 bedeutet UNLIMITED
// Schutz gegen zu kleine Werte (aber 0 = unlimited erlauben)
if ($max_pages > 0 && $max_pages < 100) $max_pages = 2000;
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

// ============================================================
// v10.7.0: LOCK-FILE (verhindert parallele Laeufe)
// ============================================================
$lock_file = $cache_dir . 'preloader.lock';
if (is_file($lock_file)) {
    $lock_data = @json_decode(@file_get_contents($lock_file), true);
    if ($lock_data && isset($lock_data['pid'])) {
        // Pruefen ob der Prozess noch laeuft
        if (function_exists('posix_kill') && @posix_kill((int)$lock_data['pid'], 0)) {
            $lock_age = time() - (isset($lock_data['started']) ? (int)$lock_data['started'] : 0);
            echo '[FPC] WARNUNG: Preloader laeuft bereits (PID ' . $lock_data['pid'] . ', seit ' . $lock_age . 's). Abbruch.' . "\n";
            $db->close();
            exit(0);
        }
        // Prozess ist tot, Lock-File aufraemen
        echo '[FPC] Altes Lock-File gefunden (PID ' . $lock_data['pid'] . ' ist tot). Raeume auf.' . "\n";
    }
}
// Lock setzen
@file_put_contents($lock_file, json_encode(array(
    'pid' => getmypid(),
    'started' => time(),
    'mode' => isset($argv[1]) ? $argv[1] : 'normal',
)));

// Lock bei Beendigung aufraemen
function fpc_cleanup_lock() {
    global $lock_file;
    @unlink($lock_file);
}
register_shutdown_function('fpc_cleanup_lock');

// ============================================================
// v10.7.0: MODUS ERKENNUNG (erweitert)
// ============================================================
$preloader_mode = 'normal';  // normal, refresh, priority, ga4, full
if (isset($argv[1])) {
    switch ($argv[1]) {
        case '--refresh':  $preloader_mode = 'refresh'; break;
        case '--priority': $preloader_mode = 'priority'; break;
        case '--ga4':      $preloader_mode = 'ga4'; break;
        case '--full':     $preloader_mode = 'full'; break;
        case '--help':
            echo "Mr. Hanf FPC v10.7.0 - Smart Preloader (GA4 Priority + Unlimited)\n";
            echo "Verwendung:\n";
            echo "  php fpc_preloader.php              Normal: Sitemap mit Resume (Batch-Limit)\n";
            echo "  php fpc_preloader.php --refresh    Smart Refresh: Nur abgelaufene Cache-Dateien\n";
            echo "  php fpc_preloader.php --priority   Nur Startseite + Kategorien + Statisch\n";
            echo "  php fpc_preloader.php --ga4        GA4 Priority: Top-Seiten zuerst, dann Rest\n";
            echo "  php fpc_preloader.php --full       Alle URLs ohne Limit (kann Stunden dauern)\n";
            echo "\nEmpfohlene Cron-Konfiguration:\n";
            echo "  Alle 2h:   php fpc_preloader.php --refresh    (erneuert abgelaufene Seiten)\n";
            echo "  Alle 4h:   php fpc_preloader.php --ga4        (Top-Seiten immer frisch)\n";
            echo "  Naechtlich: php fpc_preloader.php --full       (kompletter Durchlauf)\n";
            echo "  Taeglich:  php fpc_flush.php --expired        (loescht uralte Dateien)\n";
            exit(0);
    }
}

// v10.7.0: Im --full Modus immer unlimited
if ($preloader_mode === 'full') {
    $max_pages = 0;
    echo '[FPC] v10.7.0: FULL-Modus - Kein Batch-Limit, alle URLs werden gecacht.' . "\n";
}

echo '[FPC] Start: ' . date('Y-m-d H:i:s') . ' | Modus: ' . strtoupper($preloader_mode) . ' | PID: ' . getmypid() . "\n";
echo '[FPC] Shop-URL: ' . $shop_url . "\n";
echo '[FPC] Cache-TTL: ' . $cache_ttl . 's | Max: ' . ($max_pages === 0 ? 'UNLIMITED' : $max_pages) . "\n";
echo '[FPC] v10.7.0 Rate-Limit: ' . $FPC_REQUEST_DELAY_MS . 'ms Pause | Load-Max: ' . $FPC_LOAD_THRESHOLD . ' | Batch: ' . $FPC_BATCH_SIZE . '/' . $FPC_BATCH_PAUSE_SEC . 's | Max-Runtime: ' . $FPC_MAX_RUNTIME_SEC . 's' . "\n";

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

/**
 * v10.4.0: Cache-Datei-Pfad zu URL konvertieren
 */
function fpc_cache_path_to_url($cache_file, $cache_dir, $shop_url) {
    $relative = str_replace($cache_dir, '', $cache_file);
    $relative = preg_replace('#/index\.html$#', '', $relative);
    $relative = preg_replace('#^index\.html$#', '', $relative);
    if ($relative === '' || $relative === '/') {
        return $shop_url . '/';
    }
    return $shop_url . '/' . ltrim($relative, '/') . '/';
}

/**
 * v10.7.0: ETA berechnen
 */
function fpc_format_eta($seconds) {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return (int)($seconds / 60) . 'min';
    return (int)($seconds / 3600) . 'h ' . (int)(($seconds % 3600) / 60) . 'min';
}

/**
 * v10.7.0: GA4 Top-Pages laden (cached)
 */
function fpc_load_ga4_top_pages($shop_dir, $days, $limit, $cache_ttl) {
    // GA4-Klasse laden
    $ga4_file = $shop_dir . 'fpc_ga4.php';
    if (!is_file($ga4_file)) {
        echo '[FPC] GA4: fpc_ga4.php nicht gefunden. Ueberspringe GA4-Priorisierung.' . "\n";
        return array();
    }

    // GA4-Konfiguration laden
    $config_dir = $shop_dir . 'api/fpc/';
    $ga4_config_file = $config_dir . 'ga4_config.json';
    if (!is_file($ga4_config_file)) {
        echo '[FPC] GA4: ga4_config.json nicht gefunden. Ueberspringe GA4-Priorisierung.' . "\n";
        return array();
    }

    $ga4_config = @json_decode(@file_get_contents($ga4_config_file), true);
    if (!$ga4_config || empty($ga4_config['property_id']) || empty($ga4_config['service_account_file'])) {
        echo '[FPC] GA4: Konfiguration unvollstaendig. Ueberspringe GA4-Priorisierung.' . "\n";
        return array();
    }

    // Gecachte GA4-Daten pruefen (vermeidet unnoetige API-Calls)
    $ga4_cache_file = $shop_dir . 'cache/fpc/ga4/preloader_top_pages.json';
    if (is_file($ga4_cache_file) && (time() - filemtime($ga4_cache_file)) < $cache_ttl) {
        $cached = @json_decode(@file_get_contents($ga4_cache_file), true);
        if ($cached && !empty($cached['pages'])) {
            echo '[FPC] GA4: ' . count($cached['pages']) . ' Top-Pages aus Cache geladen (' . (int)((time() - filemtime($ga4_cache_file)) / 60) . ' Min alt)' . "\n";
            return $cached['pages'];
        }
    }

    // GA4-API aufrufen
    require_once($ga4_file);
    try {
        $sa_file = $ga4_config['service_account_file'];
        // Relativen Pfad aufloesen
        if (!is_file($sa_file) && is_file($shop_dir . $sa_file)) {
            $sa_file = $shop_dir . $sa_file;
        }

        $ga4 = new FPC_GoogleAnalytics4(
            $sa_file,
            $ga4_config['property_id'],
            $shop_dir . 'cache/fpc/ga4/',
            $cache_ttl
        );

        $result = $ga4->getTopPages($days, $limit);
        if (!$result || isset($result['error']) || empty($result['rows'])) {
            echo '[FPC] GA4: Keine Daten erhalten. Ueberspringe GA4-Priorisierung.' . "\n";
            return array();
        }

        $pages = array();
        foreach ($result['rows'] as $row) {
            if (isset($row['dimensionValues'][0]['value'])) {
                $path = $row['dimensionValues'][0]['value'];
                $views = isset($row['metricValues'][0]['value']) ? (int)$row['metricValues'][0]['value'] : 0;
                $pages[] = array('path' => $path, 'views' => $views);
            }
        }

        // Cache speichern
        $ga4_cache_dir = dirname($ga4_cache_file);
        if (!is_dir($ga4_cache_dir)) @mkdir($ga4_cache_dir, 0777, true);
        @file_put_contents($ga4_cache_file, json_encode(array(
            'pages' => $pages,
            'fetched' => date('Y-m-d H:i:s'),
            'days' => $days,
            'count' => count($pages),
        ), JSON_PRETTY_PRINT));

        echo '[FPC] GA4: ' . count($pages) . ' Top-Pages von API geladen (letzte ' . $days . ' Tage)' . "\n";
        if (!empty($pages)) {
            echo '[FPC] GA4: Top 5: ' . implode(', ', array_map(function($p) {
                return $p['path'] . ' (' . $p['views'] . ' Views)';
            }, array_slice($pages, 0, 5))) . "\n";
        }

        return $pages;
    } catch (Exception $e) {
        echo '[FPC] GA4: Fehler - ' . $e->getMessage() . "\n";
        return array();
    }
}

/**
 * v10.7.0: Checkpoint speichern (alle 100 URLs)
 */
function fpc_save_checkpoint($resume_file, $offset, $total, $cached, $skipped, $errors, $resume_data) {
    $checkpoint = array(
        'next_offset' => $offset,
        'total_urls' => $total,
        'last_run' => date('Y-m-d H:i:s'),
        'last_checkpoint' => date('Y-m-d H:i:s'),
        'last_batch_cached' => $cached,
        'last_batch_skipped' => $skipped,
        'last_batch_errors' => $errors,
        'runs_completed' => (isset($resume_data['runs_completed']) ? (int)$resume_data['runs_completed'] : 0),
        'total_cached_all_runs' => (isset($resume_data['total_cached_all_runs']) ? (int)$resume_data['total_cached_all_runs'] : 0) + $cached,
    );
    @file_put_contents($resume_file, json_encode($checkpoint, JSON_PRETTY_PRINT));
}

// ============================================================
// v10.4.0: SMART REFRESH MODUS
// ============================================================
if ($preloader_mode === 'refresh') {
    echo '[FPC] v10.4.0: SMART REFRESH - Scanne Cache-Verzeichnis nach abgelaufenen Dateien...' . "\n";

    $expired_files = array();
    $fresh_count = 0;
    $stale_count = 0;
    $total_cache_files = 0;
    $now = time();
    $stale_threshold = $cache_ttl * 2;

    $protected_dirs = array('seo', 'gsc', 'ga4', 'sistrix', 'tracker', 'logs');

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iter as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'html') continue;

        $real_path = $file->getRealPath();
        $relative = str_replace($cache_dir, '', $real_path);
        $top_dir = explode('/', $relative)[0];
        if (in_array($top_dir, $protected_dirs)) continue;

        $total_cache_files++;
        $age = $now - $file->getMTime();

        if ($age > $cache_ttl) {
            $expired_files[] = array(
                'path' => $real_path,
                'age'  => $age,
                'mtime' => $file->getMTime(),
            );
            if ($age > $stale_threshold) {
                $stale_count++;
            }
        } else {
            $fresh_count++;
        }
    }

    usort($expired_files, function($a, $b) {
        return $b['age'] - $a['age'];
    });

    echo '[FPC] v10.4.0: Cache-Statistik:' . "\n";
    echo '[FPC]   Gesamt Cache-Dateien: ' . $total_cache_files . "\n";
    echo '[FPC]   Frisch (< TTL):       ' . $fresh_count . "\n";
    echo '[FPC]   Abgelaufen (> TTL):   ' . count($expired_files) . "\n";
    echo '[FPC]   Stale (> 2x TTL):     ' . $stale_count . ' (KRITISCH)' . "\n";

    if (empty($expired_files)) {
        echo '[FPC] v10.4.0: Keine abgelaufenen Dateien gefunden. Cache ist aktuell!' . "\n";
        $db->close();
        @file_put_contents($log_file, date('Y-m-d H:i:s') . ' [REFRESH] Keine abgelaufenen Dateien. Cache aktuell. (' . $total_cache_files . ' Dateien)' . "\n", FILE_APPEND);
        exit(0);
    }

    // v10.7.0: Batch-Limit (0 = unlimited)
    if ($max_pages > 0) {
        $refresh_batch = array_slice($expired_files, 0, $max_pages);
    } else {
        $refresh_batch = $expired_files;
    }
    echo '[FPC] v10.7.0: Erneuere ' . count($refresh_batch) . ' von ' . count($expired_files) . ' abgelaufenen Dateien' . ($max_pages > 0 ? ' (Limit: ' . $max_pages . ')' : ' (UNLIMITED)') . "\n";

    $batch_urls = array();
    foreach ($refresh_batch as $entry) {
        $url = fpc_cache_path_to_url($entry['path'], $cache_dir, $shop_url);
        $skip = false;
        foreach ($exclude_arr as $ex) {
            if ($ex !== '' && strpos($url, $ex) !== false) { $skip = true; break; }
        }
        if (!$skip) {
            $batch_urls[] = $url;
        }
    }

    echo '[FPC] v10.7.0: ' . count($batch_urls) . ' URLs nach Filter' . "\n";

    $total_urls = count($batch_urls);
    $resume_offset = 0;
    $resume_data = array();
    $resume_file = $FPC_CONFIG_DIR . 'preloader_resume_refresh.json';

} elseif ($preloader_mode === 'priority') {
    // ============================================================
    // v10.4.0: PRIORITY MODUS
    // ============================================================
    echo '[FPC] v10.4.0: PRIORITY MODUS - Nur priorisierte URLs' . "\n";

    $batch_urls = array();
    $batch_urls[] = $shop_url . '/';

    $languages = array();
    $r_lang = $db->query("SELECT languages_id, code FROM languages WHERE status = 1 ORDER BY sort_order");
    if ($r_lang) {
        while ($row = $r_lang->fetch_assoc()) {
            $languages[] = $row;
        }
    }

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
                $batch_urls[] = $shop_url . '/' . ltrim($row['url_text'], '/');
            }
        }
    }

    $static_pages = array('/angebote', '/blog/', '/info/kontakt');
    foreach ($static_pages as $sp) {
        $batch_urls[] = $shop_url . $sp;
    }

    $batch_urls = array_unique($batch_urls);
    echo '[FPC] v10.4.0: ' . count($batch_urls) . ' priorisierte URLs' . "\n";

    $total_urls = count($batch_urls);
    $resume_offset = 0;
    $resume_data = array();
    $resume_file = $FPC_CONFIG_DIR . 'preloader_resume_priority.json';

} elseif ($preloader_mode === 'ga4') {
    // ============================================================
    // v10.7.0: GA4 PRIORITY MODUS
    // ============================================================
    // Reihenfolge:
    //   1. Startseite
    //   2. GA4 Top-Pages (nach Pageviews sortiert)
    //   3. Kategorien (alle Sprachen)
    //   4. Rest aus Sitemap
    // Nur abgelaufene/fehlende Cache-Dateien werden erneuert.
    echo '[FPC] v10.7.0: GA4 PRIORITY MODUS - Top-Seiten aus Google Analytics zuerst' . "\n";

    $priority_urls = array();

    // 1. Startseite immer zuerst
    $priority_urls[] = $shop_url . '/';

    // 2. GA4 Top-Pages laden
    $ga4_pages = fpc_load_ga4_top_pages($shop_dir, $FPC_GA4_DAYS, $FPC_GA4_LIMIT, $FPC_GA4_CACHE_TTL);
    $ga4_count = 0;
    foreach ($ga4_pages as $page) {
        $path = $page['path'];
        // Nur interne Pfade (kein http://)
        if (strpos($path, 'http') === 0) continue;
        // Pfad normalisieren
        $path = '/' . ltrim($path, '/');
        $url = $shop_url . $path;
        // Trailing Slash sicherstellen fuer Verzeichnis-URLs
        if (substr($url, -1) !== '/' && strpos(basename($path), '.') === false) {
            $url .= '/';
        }
        $priority_urls[] = $url;
        $ga4_count++;
    }
    echo '[FPC] v10.7.0: ' . $ga4_count . ' GA4 Top-Pages als Prioritaet hinzugefuegt' . "\n";

    // 3. Kategorien (alle Sprachen)
    $languages = array();
    $r_lang = $db->query("SELECT languages_id, code FROM languages WHERE status = 1 ORDER BY sort_order");
    if ($r_lang) {
        while ($row = $r_lang->fetch_assoc()) {
            $languages[] = $row;
        }
    }

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

    // 4. Statische Seiten
    $static_pages = array('/angebote', '/blog/', '/info/kontakt');
    foreach ($static_pages as $sp) {
        $priority_urls[] = $shop_url . $sp;
    }

    // 5. Rest aus Sitemap laden
    $sitemap_urls = array();
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
        if (strpos($sitemap_xml, '<sitemapindex') !== false) {
            preg_match_all('/<loc>(.*?)<\/loc>/i', $sitemap_xml, $matches);
            echo '[FPC] GA4: Sitemap-Index mit ' . count($matches[1]) . ' Sub-Sitemaps' . "\n";
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
                        $sitemap_urls[] = trim($u);
                    }
                }
            }
        } else {
            preg_match_all('/<loc>(.*?)<\/loc>/i', $sitemap_xml, $matches);
            foreach ($matches[1] as $u) {
                $sitemap_urls[] = trim($u);
            }
        }
        echo '[FPC] GA4: ' . count($sitemap_urls) . ' URLs aus Sitemap als Fallback' . "\n";
    }

    // Zusammenfuegen: Priority zuerst, dann Sitemap-Rest
    $all_urls = array_merge($priority_urls, $sitemap_urls);
    $all_urls = array_unique($all_urls);

    // Filtern
    $filtered = array();
    foreach ($all_urls as $url) {
        $skip = false;
        foreach ($exclude_arr as $ex) {
            if ($ex !== '' && strpos($url, $ex) !== false) { $skip = true; break; }
        }
        if (!$skip) { $filtered[] = $url; }
    }

    // v10.7.0: Im GA4-Modus kein Batch-Limit (alle URLs)
    $batch_urls = $filtered;
    $total_urls = count($batch_urls);
    $resume_offset = 0;
    $resume_data = array();
    $resume_file = $FPC_CONFIG_DIR . 'preloader_resume_ga4.json';

    // Resume laden
    if (is_file($resume_file)) {
        $resume_data = @json_decode(file_get_contents($resume_file), true);
        if (is_array($resume_data) && isset($resume_data['next_offset'])) {
            $resume_offset = (int) $resume_data['next_offset'];
            if ($resume_offset >= $total_urls) {
                echo '[FPC] v10.7.0: GA4 - Alle URLs wurden durchlaufen! Starte von vorne.' . "\n";
                $resume_offset = 0;
            } else {
                echo '[FPC] v10.7.0: GA4 Resume ab Position ' . $resume_offset . ' von ' . $total_urls . "\n";
            }
        }
    }

    // Ab Resume-Offset
    $batch_urls = array_slice($filtered, $resume_offset);

    echo '[FPC] v10.7.0: GA4 Priority - ' . count($batch_urls) . ' URLs ab Position ' . $resume_offset . ' (von ' . $total_urls . ' gesamt)' . "\n";
    echo '[FPC] v10.7.0: Reihenfolge: Startseite > GA4 Top ' . $ga4_count . ' > Kategorien > Sitemap-Rest' . "\n";

} else {
    // ============================================================
    // NORMALER MODUS (Original-Logik mit Sitemap + Resume)
    // ============================================================

    $urls = array();

    // 1. Sitemap laden
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
        $urls[] = $shop_url . '/';

        $r = $db->query("
            SELECT DISTINCT c.url_text
            FROM clean_seo_url c
            INNER JOIN categories cat ON c.id = cat.categories_id AND c.type = 'categories'
            WHERE cat.categories_status = 1
              AND c.url_text != ''
              AND c.language_id = 2
            LIMIT " . ($max_pages > 0 ? $max_pages : 50000));
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $urls[] = $shop_url . '/' . ltrim($row['url_text'], '/');
            }
        }

        $remaining = ($max_pages > 0 ? $max_pages : 50000) - count($urls);
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

    // v10.7.0: GA4 Top-Pages auch im Normal-Modus als Prioritaet
    $ga4_pages = fpc_load_ga4_top_pages($shop_dir, $FPC_GA4_DAYS, $FPC_GA4_LIMIT, $FPC_GA4_CACHE_TTL);
    $ga4_priority = array();
    $ga4_priority[] = $shop_url . '/';
    foreach ($ga4_pages as $page) {
        $path = $page['path'];
        if (strpos($path, 'http') === 0) continue;
        $path = '/' . ltrim($path, '/');
        $url = $shop_url . $path;
        if (substr($url, -1) !== '/' && strpos(basename($path), '.') === false) {
            $url .= '/';
        }
        $ga4_priority[] = $url;
    }

    // Kategorien als Prioritaet
    $languages = array();
    $r_lang = $db->query("SELECT languages_id, code FROM languages WHERE status = 1 ORDER BY sort_order");
    if ($r_lang) {
        while ($row = $r_lang->fetch_assoc()) {
            $languages[] = $row;
        }
    }

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
                $ga4_priority[] = $shop_url . '/' . ltrim($row['url_text'], '/');
            }
        }
    }

    $static_pages = array('/angebote', '/blog/', '/info/kontakt');
    foreach ($static_pages as $sp) {
        $ga4_priority[] = $shop_url . $sp;
    }

    echo '[FPC] v10.7.0: ' . count($ga4_priority) . ' priorisierte URLs (GA4 + Kategorien + Statisch)' . "\n";

    // Priority-URLs VOR Sitemap-URLs setzen, dann deduplizieren
    $urls = array_merge($ga4_priority, $urls);
    $urls = array_unique($urls);

    // Filtern
    $filtered = array();
    foreach ($urls as $url) {
        $skip = false;
        foreach ($exclude_arr as $ex) {
            if ($ex !== '' && strpos($url, $ex) !== false) { $skip = true; break; }
        }
        if (!$skip) { $filtered[] = $url; }
    }
    $total_urls = count($filtered);
    echo '[FPC] ' . $total_urls . ' URLs nach Filter (gesamt)' . "\n";

    // Resume-Mechanismus
    $resume_file = $FPC_CONFIG_DIR . 'preloader_resume.json';
    if (!is_file($resume_file)) {
        if (is_file(__DIR__ . '/cache/fpc_config/preloader_resume.json')) {
            @copy(__DIR__ . '/cache/fpc_config/preloader_resume.json', $resume_file);
        } elseif (is_file($cache_dir . 'preloader_resume.json')) {
            @copy($cache_dir . 'preloader_resume.json', $resume_file);
        }
    }
    $resume_offset = 0;
    $resume_data = array();
    if (is_file($resume_file)) {
        $resume_data = @json_decode(file_get_contents($resume_file), true);
        if (is_array($resume_data) && isset($resume_data['next_offset'])) {
            $resume_offset = (int) $resume_data['next_offset'];
            if ($resume_offset >= $total_urls) {
                echo '[FPC] v8.3.0: Alle URLs wurden durchlaufen! Starte von vorne.' . "\n";
                $resume_offset = 0;
            } else {
                echo '[FPC] v8.3.0: Resume ab Position ' . $resume_offset . ' von ' . $total_urls . ' (letzter Lauf: ' . (isset($resume_data['last_run']) ? $resume_data['last_run'] : '?') . ')' . "\n";
            }
        }
    }

    // Batch fuer diesen Lauf
    if ($max_pages > 0) {
        $batch_urls = array_slice($filtered, $resume_offset, $max_pages);
    } else {
        // v10.7.0: Unlimited - alle ab Resume-Offset
        $batch_urls = array_slice($filtered, $resume_offset);
    }
    echo '[FPC] v10.7.0: Batch ' . ($resume_offset + 1) . '-' . ($resume_offset + count($batch_urls)) . ' von ' . $total_urls . ' (' . count($batch_urls) . ' URLs in diesem Lauf' . ($max_pages === 0 ? ', UNLIMITED' : ', max ' . $max_pages) . ')' . "\n";
}

// ============================================================
// GEMEINSAME PRELOADING-SCHLEIFE (fuer alle Modi)
// ============================================================
$cached = 0; $skipped = 0; $errors = 0; $invalid = 0;
$load_pauses = 0; $total_ttfb = 0; $ttfb_count = 0;
$current_delay = $FPC_REQUEST_DELAY_MS;
$batch_count = 0;
$total_processed = 0;
$checkpoint_counter = 0;

// v8.0: Validierungs-Konfiguration
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

$batch_total = count($batch_urls);

foreach ($batch_urls as $i => $url) {

    // v7.1: Maximale Laufzeit pruefen
    if ((time() - $start_time) > $FPC_MAX_RUNTIME_SEC) {
        echo '[FPC] Maximale Laufzeit (' . $FPC_MAX_RUNTIME_SEC . 's / ' . fpc_format_eta($FPC_MAX_RUNTIME_SEC) . ') erreicht. Stoppe.' . "\n";
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

    // Cache noch gueltig? Ueberspringe frische Dateien
    if (is_file($cache_file) && (time() - filemtime($cache_file)) < $cache_ttl) {
        $skipped++;
        $total_processed++;
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

    // v8.0.9: REDIRECT-ERKENNUNG
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
    $checkpoint_counter++;

    // v7.1: TTFB tracken
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

        // v10.7.0: Fehlerquote erst nach 50 Requests pruefen (statt 20)
        if ($total_processed > 50 && ($errors / $total_processed) > $FPC_MAX_ERROR_RATE) {
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

    // v8.0: ERWEITERTE HTML-VALIDIERUNG
    $validation = fpc_validate_html($html, $url, $validate_config);
    if ($validation !== true) {
        $invalid++;
        if ($invalid <= 10) {
            echo '[FPC] UNGUELTIG (' . $validation . '): ' . $url . "\n";
        }
        continue;
    }

    // === HTML ist gueltig! ===

    // Session-IDs entfernen
    $html = preg_replace('/MODsid=[a-zA-Z0-9]+/', '', $html);

    // Health-Marker und Cache-Kommentar anhaengen
    $html .= "\n" . $FPC_HEALTH_MARKER . "\n";
    $html .= '<!-- FPC cached: ' . date('Y-m-d H:i:s') . ' | v10.7.0 | mode:' . $preloader_mode . ' -->' . "\n";

    // ATOMIC WRITE
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

    // v8.0: VERIFY AFTER WRITE
    if ($FPC_VERIFY_AFTER_WRITE) {
        $verify_content = @file_get_contents($cache_file);
        if ($verify_content === false || strlen($verify_content) < $FPC_MIN_HTML_SIZE) {
            @unlink($cache_file);
            $errors++;
            if ($errors <= 10) {
                echo '[FPC] VERIFY-FEHLER (Datei korrupt nach Schreiben): ' . $cache_file . "\n";
            }
            continue;
        }
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

    // v10.7.0: Fortschritt mit Prozent und ETA alle 50 Seiten
    if ($cached > 0 && $cached % 50 == 0) {
        $avg_ttfb = $ttfb_count > 0 ? (int)($total_ttfb / $ttfb_count) : 0;
        $runtime = time() - $start_time;
        $percent = $batch_total > 0 ? round(($total_processed / $batch_total) * 100, 1) : 0;
        $rate = $runtime > 0 ? round($total_processed / $runtime, 1) : 0;
        $remaining = $batch_total - $total_processed;
        $eta = $rate > 0 ? (int)($remaining / $rate) : 0;
        echo '[FPC] Fortschritt: ' . $percent . '% | ' . $cached . ' gecacht | ' . $total_processed . '/' . $batch_total . ' | TTFB: ' . $avg_ttfb . 'ms | ETA: ' . fpc_format_eta($eta) . ' | Load: ' . sprintf('%.2f', fpc_get_server_load()) . "\n";
    }

    // v10.7.0: Checkpoint alle 100 URLs (fuer sicheres Resume)
    if ($checkpoint_counter >= 100) {
        $checkpoint_counter = 0;
        if (isset($resume_file)) {
            fpc_save_checkpoint($resume_file, $resume_offset + $total_processed, $total_urls, $cached, $skipped, $errors, $resume_data);
        }
    }

    // v7.1: Rate-Limiting Pause zwischen Requests
    usleep($current_delay * 1000);
}

curl_close($ch);
$db->close();

$runtime = time() - $start_time;
$avg_ttfb = $ttfb_count > 0 ? (int)($total_ttfb / $ttfb_count) : 0;

$mode_label = strtoupper($preloader_mode);
$summary = '[FPC] Fertig: ' . date('Y-m-d H:i:s') . ' | Modus: ' . $mode_label . "\n"
         . '[FPC] v10.7.0 | Gecacht: ' . $cached . ' | Uebersprungen: ' . $skipped
         . ' | Ungueltig: ' . $invalid . ' | Fehler: ' . $errors . "\n"
         . '[FPC] Laufzeit: ' . fpc_format_eta($runtime) . ' (' . $runtime . 's) | Avg-TTFB: ' . $avg_ttfb . 'ms | Load-Pausen: ' . $load_pauses
         . ' | Requests: ' . $batch_count . "\n";

if ($preloader_mode === 'normal' || $preloader_mode === 'ga4' || $preloader_mode === 'full') {
    $summary = '[FPC] Fertig: ' . date('Y-m-d H:i:s') . ' | Modus: ' . $mode_label . "\n"
             . '[FPC] v10.7.0 | Batch ' . ($resume_offset + 1) . '-' . ($resume_offset + $total_processed) . '/' . $total_urls . ' | Gecacht: ' . $cached . ' | Uebersprungen: ' . $skipped
             . ' | Ungueltig: ' . $invalid . ' | Fehler: ' . $errors . "\n"
             . '[FPC] Laufzeit: ' . fpc_format_eta($runtime) . ' (' . $runtime . 's) | Avg-TTFB: ' . $avg_ttfb . 'ms | Load-Pausen: ' . $load_pauses
             . ' | Requests: ' . $batch_count . "\n";
}
echo $summary;

// Resume-Position speichern
if ($preloader_mode === 'normal' || $preloader_mode === 'ga4' || $preloader_mode === 'full') {
    $next_offset = $resume_offset + $total_processed;
    $resume_save = array(
        'next_offset' => $next_offset,
        'total_urls' => $total_urls,
        'last_run' => date('Y-m-d H:i:s'),
        'last_batch_cached' => $cached,
        'last_batch_skipped' => $skipped,
        'last_batch_errors' => $errors,
        'runs_completed' => (isset($resume_data['runs_completed']) ? (int)$resume_data['runs_completed'] : 0) + 1,
        'total_cached_all_runs' => (isset($resume_data['total_cached_all_runs']) ? (int)$resume_data['total_cached_all_runs'] : 0) + $cached,
        'mode' => $preloader_mode,
        'runtime_sec' => $runtime,
    );
    if ($next_offset >= $total_urls) {
        $resume_save['next_offset'] = 0;
        $resume_save['completed_full_cycle'] = date('Y-m-d H:i:s');
        echo '[FPC] v10.7.0: Alle URLs durchlaufen! Naechster Lauf startet von vorne.' . "\n";
    }
    @file_put_contents($resume_file, json_encode($resume_save, JSON_PRETTY_PRINT));
    echo '[FPC] v10.7.0: Resume-Position gespeichert: ' . $resume_save['next_offset'] . '/' . $total_urls . ' | Gesamt gecacht (alle Laeufe): ' . $resume_save['total_cached_all_runs'] . "\n";
}

// Refresh-Modus: Statistik speichern
if ($preloader_mode === 'refresh') {
    $refresh_stats = array(
        'last_run' => date('Y-m-d H:i:s'),
        'total_cache_files' => $total_cache_files,
        'expired_found' => count($expired_files),
        'stale_found' => $stale_count,
        'fresh_found' => $fresh_count,
        'refreshed' => $cached,
        'skipped' => $skipped,
        'errors' => $errors,
        'runtime_sec' => $runtime,
    );
    @file_put_contents($resume_file, json_encode($refresh_stats, JSON_PRETTY_PRINT));
    echo '[FPC] v10.7.0: Refresh-Statistik gespeichert.' . "\n";
}

// Log schreiben
@file_put_contents($log_file, date('Y-m-d H:i:s') . ' ' . $summary, FILE_APPEND);
