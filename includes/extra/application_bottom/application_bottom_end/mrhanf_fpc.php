<?php
// Hook: application_bottom_end (Wird ganz am Ende geladen)

if (isset($GLOBALS['fpc_is_cacheable']) && $GLOBALS['fpc_is_cacheable'] === true) {
    // Das generierte HTML aus dem Buffer holen
    $fpc_html = ob_get_contents();
    
    // Nur speichern, wenn auch wirklich HTML generiert wurde
    if (!empty($fpc_html) && strlen($fpc_html) > 1000) {
        
        $fpc_cache_file = $GLOBALS['fpc_cache_file'];
        
        // HTML in Cache-Datei schreiben (mit LOCK_EX)
        file_put_contents($fpc_cache_file, $fpc_html, LOCK_EX);
        
        // Einen HTML-Kommentar ans Ende hängen
        $fpc_timestamp = date('Y-m-d H:i:s');
        echo "\n<!-- MR-HANF FPC: Cached on {$fpc_timestamp} -->";
    }
    
    // Output Buffering beenden und an Browser senden
    ob_end_flush();
}
?>
