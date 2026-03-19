<?php
/**
 * Mr. Hanf Full Page Cache v6.0.0 - Cron Preloader
 *
 * Dieses Script wird per Cron aufgerufen und generiert statische
 * HTML-Dateien fuer die wichtigsten Seiten des Shops.
 *
 * Aufruf:
 *   /usr/local/bin/php /pfad/zum/shop/fpc_preloader.php
 *
 * Crontab (alle 30 Minuten):
 *   */30 * * * * cd /pfad/zum/shop && /usr/local/bin/php fpc_preloader.php >> cache/fpc/preloader.log 2>&1
 */

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

// Shop-URL ermitteln
$shop_url = defined('HTTPS_SERVER') ? rtrim(HTTPS_SERVER, '/') : '';
if (empty($shop_url)) {
    $shop_url = defined('HTTP_SERVER') ? rtrim(HTTP_SERVER, '/') : '';
}
if (empty($shop_url)) {
    $r = $db->query("SELECT configuration_value FROM configuration WHERE configuration_key = 'HTTP_SERVER' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $shop_url = rtrim($row['configuration_value'], '/');
    }
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

// URLs sammeln
$urls = array();

// 1. Sitemap versuchen
$sitemap_urls = array(
    $shop_url . '/sitemap.xml',
    $shop_url . '/sitemap_index.xml',
);

foreach ($sitemap_urls as $sitemap_url) {
    $ctx = stream_context_create(array('http' => array('timeout' => 10), 'ssl' => array('verify_peer' => false)));
    $sitemap_xml = @file_get_contents($sitemap_url, false, $ctx);
    if ($sitemap_xml !== false && strlen($sitemap_xml) > 100) {
        echo '[FPC] Sitemap gefunden: ' . $sitemap_url . "\n";
        if (strpos($sitemap_xml, '<sitemapindex') !== false) {
            preg_match_all('/<loc>(.*?)<\/loc>/i', $sitemap_xml, $matches);
            foreach ($matches[1] as $sub_url) {
                $sub_xml = @file_get_contents(trim($sub_url), false, $ctx);
                if ($sub_xml !== false) {
                    preg_match_all('/<loc>(.*?)<\/loc>/i', $sub_xml, $sub_m);
                    foreach ($sub_m[1] as $u) { $urls[] = trim($u); }
                }
            }
        } else {
            preg_match_all('/<loc>(.*?)<\/loc>/i', $sitemap_xml, $matches);
            foreach ($matches[1] as $u) { $urls[] = trim($u); }
        }
        break;
    }
}

// 2. Fallback: SEO-URLs aus DB
if (empty($urls)) {
    echo '[FPC] Keine Sitemap. Lade URLs aus DB...' . "\n";
    $r = $db->query("SELECT url_rewrite FROM seo_url WHERE url_rewrite != '' ORDER BY url_rewrite LIMIT " . ($max_pages * 2));
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $urls[] = $shop_url . '/' . ltrim($row['url_rewrite'], '/');
        }
    }
    array_unshift($urls, $shop_url . '/');
}

$urls = array_unique($urls);
echo '[FPC] ' . count($urls) . ' URLs gefunden' . "\n";

// Filtern
$filtered = array();
foreach ($urls as $url) {
    $skip = false;
    foreach ($exclude_arr as $ex) {
        if ($ex !== '' && strpos($url, $ex) !== false) { $skip = true; break; }
    }
    if (!$skip) { $filtered[] = $url; }
}
$filtered = array_slice($filtered, 0, $max_pages);
echo '[FPC] ' . count($filtered) . ' URLs nach Filter' . "\n";

// Preloading
$cached = 0; $skipped = 0; $errors = 0;

$ch = curl_init();
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'MrHanf-FPC-Preloader/6.0',
    CURLOPT_ENCODING       => '',
    CURLOPT_COOKIE         => '',
));

foreach ($filtered as $url) {
    $parsed = parse_url($url);
    $path   = isset($parsed['path']) ? $parsed['path'] : '/';
    if ($path === '') $path = '/';

    // Cache-Pfad: /samen-shop/xyz/ -> cache/fpc/samen-shop/xyz/index.html
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

    if ($html === false || $code !== 200) {
        $errors++;
        continue;
    }

    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (strpos($ct, 'text/html') === false) {
        $skipped++;
        continue;
    }

    // Session-IDs entfernen
    $html = preg_replace('/MODsid=[a-zA-Z0-9]+/', '', $html);

    // Kommentar am Ende hinzufuegen
    $html .= "\n<!-- FPC cached: " . date('Y-m-d H:i:s') . " -->\n";

    $dir = dirname($cache_file);
    if (!is_dir($dir)) { mkdir($dir, 0777, true); }

    if (file_put_contents($cache_file, $html) !== false) {
        $cached++;
    } else {
        $errors++;
    }

    usleep(50000); // 50ms Pause
}

curl_close($ch);
$db->close();

echo '[FPC] Fertig: ' . date('Y-m-d H:i:s') . "\n";
echo '[FPC] Gecacht: ' . $cached . ' | Uebersprungen: ' . $skipped . ' | Fehler: ' . $errors . "\n";
