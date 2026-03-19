<?php

declare(strict_types=1);

/**
 * Mr. Hanf Full Page Cache — application_top_begin Hook
 *
 * Wird von Modified automatisch ganz am Anfang von application_top.php geladen.
 * Prüft ob eine gecachte Version der Seite existiert und liefert sie aus,
 * BEVOR die Datenbank oder PHP-Logik gestartet wird.
 *
 * @version  1.2.0
 * @php      8.3+
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

// Ausgeschlossene Seiten aus Konfiguration lesen (oder Fallback)
$fpc_excluded_raw = defined('MODULE_MRHANF_FPC_EXCLUDED_PAGES')
    ? MODULE_MRHANF_FPC_EXCLUDED_PAGES
    : 'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write';

$fpc_excluded_pages = array_filter(
    array_map('trim', explode(',', $fpc_excluded_raw))
);

// -------------------------------------------------------------------------
// Cacheability-Prüfung
// -------------------------------------------------------------------------
$fpc_is_cacheable = match(true) {
    // Nur GET-Requests cachen
    $_SERVER['REQUEST_METHOD'] !== 'GET'                   => false,
    // Aktionen (z.B. ?action=add_product) nie cachen
    isset($_GET['action']) && $_GET['action'] !== ''       => false,
    // Eingeloggte Kunden nie cachen
    isset($_COOKIE['xtc_customer_id'])
        && $_COOKIE['xtc_customer_id'] !== ''              => false,
    // Gefüllter Warenkorb nie cachen
    isset($_COOKIE['xtc_cart'])
        && $_COOKIE['xtc_cart'] !== ''                     => false,
    // Admin-Session nie cachen
    isset($_COOKIE['xtc_is_admin'])
        && $_COOKIE['xtc_is_admin'] !== ''                 => false,
    default                                                => true,
};

// Ausgeschlossene Seiten prüfen
if ($fpc_is_cacheable) {
    $current_uri = $_SERVER['REQUEST_URI'];
    foreach ($fpc_excluded_pages as $page) {
        if (str_contains($current_uri, $page)) {
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
if (!is_dir($fpc_cache_dir) && !@mkdir($fpc_cache_dir, 0o755, true)) {
    // Verzeichnis konnte nicht erstellt werden → kein Caching, aber kein Absturz
    return;
}

// -------------------------------------------------------------------------
// Cache-Datei bestimmen
// -------------------------------------------------------------------------
$fpc_protocol   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$fpc_full_url   = $fpc_protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$fpc_cache_file = $fpc_cache_dir . hash('xxh3', $fpc_full_url) . '.html';

// -------------------------------------------------------------------------
// Cache HIT → sofort ausliefern und PHP beenden
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
// Cache MISS → Output Buffering starten, HTML am Ende speichern
// -------------------------------------------------------------------------
ob_start();
header('X-MrHanf-Cache: MISS');

$GLOBALS['fpc_is_cacheable'] = true;
$GLOBALS['fpc_cache_file']   = $fpc_cache_file;
