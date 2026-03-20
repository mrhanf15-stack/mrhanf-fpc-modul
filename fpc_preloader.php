<?php
//
// Mr. Hanf Full Page Cache v6.1.1 - Cron Preloader
//
// Cron-Job der Shop-Seiten abruft und als statische HTML-Dateien speichert.
// Primaere URL-Quelle: sitemap.xml
// Fallback: Aktive Produkte/Kategorien aus der DB
//

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

// Cache-Verzeichnis
$cache_dir = $shop_dir . 'cache/fpc/';
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0777, true);
}

echo '[FPC] Start: ' . date('Y-m-d H:i:s') . "\n";
echo '[FPC] Shop-URL: ' . $shop_url . "\n";
echo '[FPC] Cache-TTL: ' . $cache_ttl . 's | Max: ' . $max_pages . "\n";

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

// 2. Fallback: Aktive Produkte und Kategorien aus DB (NICHT clean_seo_url!)
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

// --- Preloading ---
$cached = 0; $skipped = 0; $errors = 0;

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

    curl_setopt($ch, CURLOPT_URL, $url);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($html === false || $code != 200) {
        $errors++;
        if ($errors <= 10) {
            echo '[FPC] FEHLER: ' . $url . ' (HTTP ' . $code . ')' . "\n";
        }
        continue;
    }

    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (strpos($ct, 'text/html') === false) {
        $skipped++;
        continue;
    }

    // Session-IDs entfernen
    $html = preg_replace('/MODsid=[a-zA-Z0-9]+/', '', $html);

    // Cache-Kommentar
    $html .= "\n<!-- FPC cached: " . date('Y-m-d H:i:s') . " -->\n";

    $dir = dirname($cache_file);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

    if (file_put_contents($cache_file, $html) !== false) {
        $cached++;
    } else {
        $errors++;
    }

    // Fortschritt alle 50 Seiten
    if ($cached > 0 && $cached % 50 == 0) {
        echo '[FPC] Fortschritt: ' . $cached . ' gecacht...' . "\n";
    }

    usleep(50000); // 50ms Pause
}

curl_close($ch);
$db->close();

echo '[FPC] Fertig: ' . date('Y-m-d H:i:s') . "\n";
echo '[FPC] Gecacht: ' . $cached . ' | Uebersprungen: ' . $skipped . ' | Fehler: ' . $errors . "\n";
