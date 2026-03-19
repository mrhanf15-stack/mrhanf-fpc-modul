<?php
// Hook: application_top_begin (Wird ganz am Anfang geladen)

// Prüfen ob Modul aktiv ist
if (defined('MODULE_MRHANF_FPC_STATUS') && MODULE_MRHANF_FPC_STATUS == 'true') {

    $fpc_cache_time = defined('MODULE_MRHANF_FPC_CACHE_TIME') ? (int)MODULE_MRHANF_FPC_CACHE_TIME : 86400;
    $fpc_cache_dir = DIR_FS_DOCUMENT_ROOT . 'cache/fpc/';

    // 1. Prüfen ob Request gecacht werden darf
    $fpc_is_cacheable = true;

    // Nur GET-Requests cachen (keine Formulare/Warenkorb-Aktionen)
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $fpc_is_cacheable = false;
    }

    // 2. WICHTIG: Session-Cookie prüfen
    // Wenn MODsid existiert, ist der User eingeloggt oder hat was im Warenkorb -> NICHT CACHEN
    if (isset($_COOKIE['MODsid'])) {
        $fpc_is_cacheable = false;
    }

    // 3. Ausgeschlossene Seiten (Checkout, Account, Admin)
    $fpc_excluded_pages = [
        'checkout', 'login', 'account', 'shopping_cart', 'logoff', 
        'admin', 'password_double_opt', 'create_account', 'contact_us'
    ];
    $current_uri = $_SERVER['REQUEST_URI'];
    foreach ($fpc_excluded_pages as $page) {
        if (strpos($current_uri, $page) !== false) {
            $fpc_is_cacheable = false;
            break;
        }
    }

    // 4. Cache-Logik ausführen
    if ($fpc_is_cacheable) {
        
        // Eindeutigen Dateinamen für diese URL generieren
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $full_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $fpc_cache_file = $fpc_cache_dir . md5($full_url) . '.html';

        // Wenn Cache existiert und noch gültig ist -> Ausliefern!
        if (file_exists($fpc_cache_file) && (time() - filemtime($fpc_cache_file)) < $fpc_cache_time) {
            
            // Cache Header senden
            header('X-MrHanf-Cache: HIT');
            header('Cache-Control: public, max-age=3600');
            
            // Datei ausgeben
            readfile($fpc_cache_file);
            
            // PHP hier sofort beenden!
            exit; 
        }

        // Wenn kein Cache da ist: Output Buffering starten
        ob_start();
        header('X-MrHanf-Cache: MISS');
        
        // Variable für application_bottom setzen
        $GLOBALS['fpc_is_cacheable'] = true;
        $GLOBALS['fpc_cache_file'] = $fpc_cache_file;
    }
}
?>
