<?php
/**
 * Mr. Hanf Full Page Cache — application_top_begin Hook
 * Kein declare(strict_types=1) — nicht erlaubt in included Dateien
 *
 * @version  2.1.0
 * @php      8.1+
 */

if (!defined('MODULE_MRHANF_FPC_STATUS') || MODULE_MRHANF_FPC_STATUS !== 'true') {
    return;
}

// -------------------------------------------------------------------------
// Konfiguration
// -------------------------------------------------------------------------
$fpc_cache_time = defined('MODULE_MRHANF_FPC_CACHE_TIME')
    ? (int) MODULE_MRHANF_FPC_CACHE_TIME
    : 86400;

$fpc_cache_dir = DIR_FS_DOCUMENT_ROOT . 'cache/fpc/';

$fpc_excluded_raw = defined('MODULE_MRHANF_FPC_EXCLUDED_PAGES')
    ? MODULE_MRHANF_FPC_EXCLUDED_PAGES
    : 'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write';

$fpc_excluded_pages = array_filter(
    array_map('trim', explode(',', $fpc_excluded_raw))
);

// -------------------------------------------------------------------------
// Cacheability-Prüfung
// -------------------------------------------------------------------------
$fpc_is_cacheable = true;

// Nur GET-Requests cachen
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $fpc_is_cacheable = false;
}

// Keine Aktionen cachen
if ($fpc_is_cacheable && isset($_GET['action']) && $_GET['action'] !== '') {
    $fpc_is_cacheable = false;
}

// Eingeloggte Kunden nicht cachen
if ($fpc_is_cacheable && isset($_COOKIE['xtc_customer_id']) && $_COOKIE['xtc_customer_id'] !== '') {
    $fpc_is_cacheable = false;
}

// Warenkorb nicht cachen
if ($fpc_is_cacheable && isset($_COOKIE['xtc_cart']) && $_COOKIE['xtc_cart'] !== '') {
    $fpc_is_cacheable = false;
}

// Admin nicht cachen
if ($fpc_is_cacheable && isset($_COOKIE['xtc_is_admin']) && $_COOKIE['xtc_is_admin'] !== '') {
    $fpc_is_cacheable = false;
}

// Ausgeschlossene Seiten prüfen
if ($fpc_is_cacheable) {
    $current_uri = $_SERVER['REQUEST_URI'];
    foreach ($fpc_excluded_pages as $page) {
        if (strpos($current_uri, $page) !== false) {
            $fpc_is_cacheable = false;
            break;
        }
    }
}

if (!$fpc_is_cacheable) {
    return;
}

// -------------------------------------------------------------------------
// Cache-Verzeichnis sicherstellen
// -------------------------------------------------------------------------
if (!is_dir($fpc_cache_dir) && !@mkdir($fpc_cache_dir, 0755, true)) {
    return;
}

// -------------------------------------------------------------------------
// Cache-Key generieren (xxh3 wenn verfügbar, sonst md5)
// -------------------------------------------------------------------------
$fpc_protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$fpc_full_url   = $fpc_protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$fpc_hash_algo  = in_array('xxh3', hash_algos(), true) ? 'xxh3' : 'md5';
$fpc_cache_file = $fpc_cache_dir . hash($fpc_hash_algo, $fpc_full_url) . '.html';

// -------------------------------------------------------------------------
// Cache HIT → sofort ausliefern
// -------------------------------------------------------------------------
if (
    is_file($fpc_cache_file)
    && (time() - filemtime($fpc_cache_file)) < $fpc_cache_time
) {
    header('X-MrHanf-Cache: HIT');
    header('Cache-Control: public, max-age=3600, stale-while-revalidate=60');
    header('Content-Type: text/html; charset=utf-8');
    readfile($fpc_cache_file);
    exit;
}

// -------------------------------------------------------------------------
// Cache MISS → Output Buffering starten
// -------------------------------------------------------------------------
$fpc_ob_level = ob_get_level();
ob_start();
header('X-MrHanf-Cache: MISS');

$GLOBALS['fpc_is_cacheable'] = true;
$GLOBALS['fpc_cache_file']   = $fpc_cache_file;
$GLOBALS['fpc_ob_level']     = $fpc_ob_level;
