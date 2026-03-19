<?php
/**
 * Mr. Hanf Full Page Cache - application_top_begin Hook
 * 
 * WICHTIG: Modified setzt MODsid bei JEDEM Seitenaufruf (auch für Gäste).
 * Daher prüfen wir NICHT ob MODsid existiert, sondern ob der Benutzer
 * EINGELOGGT ist oder einen WARENKORB hat.
 * 
 * Modified nutzt für eingeloggte User / Warenkorb zusätzliche Cookies:
 * - xtc_customer_id (eingeloggt)
 * - xtc_cart (Warenkorb vorhanden)
 * 
 * Für Gäste ohne Warenkorb: Cache ausliefern.
 */

if (defined('MODULE_MRHANF_FPC_STATUS') && MODULE_MRHANF_FPC_STATUS == 'true') {

    $fpc_cache_time = defined('MODULE_MRHANF_FPC_CACHE_TIME') ? (int)MODULE_MRHANF_FPC_CACHE_TIME : 86400;
    $fpc_cache_dir = DIR_FS_DOCUMENT_ROOT . 'cache/fpc/';

    $fpc_is_cacheable = true;

    // 1. Nur GET-Requests cachen
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $fpc_is_cacheable = false;
    }

    // 2. KORRIGIERTE Logik: Eingeloggte User oder Warenkorb-Inhaber NICHT cachen
    // Modified setzt diese Cookies NUR wenn der User eingeloggt ist oder Artikel im Warenkorb hat
    $no_cache_cookies = [
        'xtc_customer_id',   // Eingeloggter Kunde
        'xtc_cart',          // Warenkorb nicht leer
        'xtc_is_admin',      // Admin eingeloggt
    ];
    foreach ($no_cache_cookies as $cookie_name) {
        if (isset($_COOKIE[$cookie_name]) && !empty($_COOKIE[$cookie_name])) {
            $fpc_is_cacheable = false;
            break;
        }
    }

    // 3. Ausgeschlossene Seiten (Checkout, Account, Admin)
    $fpc_excluded_pages = [
        'checkout', 'login', 'account', 'shopping_cart', 'logoff',
        'admin', 'password_double_opt', 'create_account', 'contact_us',
        'tell_a_friend', 'product_reviews_write'
    ];
    $current_uri = $_SERVER['REQUEST_URI'];
    foreach ($fpc_excluded_pages as $page) {
        if (strpos($current_uri, $page) !== false) {
            $fpc_is_cacheable = false;
            break;
        }
    }

    // 4. Query-Parameter ausschließen (Sortierung, Filter etc. können gecacht werden,
    //    aber POST-Aktionen wie ?action=add_product nicht)
    if (isset($_GET['action']) && !empty($_GET['action'])) {
        $fpc_is_cacheable = false;
    }

    // 5. Cache-Logik ausführen
    if ($fpc_is_cacheable) {

        // Cache-Ordner erstellen falls nicht vorhanden
        if (!is_dir($fpc_cache_dir)) {
            @mkdir($fpc_cache_dir, 0777, true);
        }

        // Eindeutigen Dateinamen für diese URL generieren
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $full_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $fpc_cache_file = $fpc_cache_dir . md5($full_url) . '.html';

        // Wenn Cache existiert und noch gültig ist -> Ausliefern!
        if (file_exists($fpc_cache_file) && (time() - filemtime($fpc_cache_file)) < $fpc_cache_time) {

            header('X-MrHanf-Cache: HIT');
            header('Cache-Control: public, max-age=3600');

            readfile($fpc_cache_file);

            // PHP sofort beenden - kein DB-Aufruf, kein PHP-Worker belegt
            exit;
        }

        // Kein Cache vorhanden: Output Buffering starten
        ob_start();
        header('X-MrHanf-Cache: MISS');

        // Globale Variablen für application_bottom Hook
        $GLOBALS['fpc_is_cacheable'] = true;
        $GLOBALS['fpc_cache_file']   = $fpc_cache_file;
    }
}
?>
