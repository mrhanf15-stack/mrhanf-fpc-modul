<?php
/* -----------------------------------------------------------------------------------------
   Mr. Hanf Full Page Cache (FPC) — Cache-Check Hook (application_top_begin)
   -----------------------------------------------------------------------------------------
   Prueft ob ein gueltiger Cache existiert und liefert ihn sofort aus.
   Bei Cache-HIT wird der PHP-Prozess mit exit beendet.
   Bei Cache-MISS wird ob_start() gestartet fuer spaeteres Speichern.
   -----------------------------------------------------------------------------------------*/

// Nur ausfuehren wenn das Modul installiert und aktiv ist
if (defined('MODULE_MRHANF_FPC_STATUS') && MODULE_MRHANF_FPC_STATUS == 'True') {

    // Nur GET-Requests cachen
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        return;
    }

    // Keine eingeloggten Benutzer cachen
    if (isset($_COOKIE['xtc_customer_id']) || isset($_COOKIE['xtc_is_admin'])) {
        return;
    }

    // Keine Action-Parameter cachen
    if (isset($_GET['action'])) {
        return;
    }

    // Ausgeschlossene Seiten pruefen
    $mrhanf_fpc_uri = $_SERVER['REQUEST_URI'];
    if (defined('MODULE_MRHANF_FPC_EXCLUDED_PAGES') && MODULE_MRHANF_FPC_EXCLUDED_PAGES != '') {
        $mrhanf_fpc_excluded = explode(',', MODULE_MRHANF_FPC_EXCLUDED_PAGES);
        for ($i = 0; $i < count($mrhanf_fpc_excluded); $i++) {
            $mrhanf_fpc_ex = trim($mrhanf_fpc_excluded[$i]);
            if ($mrhanf_fpc_ex != '' && strpos($mrhanf_fpc_uri, $mrhanf_fpc_ex) !== false) {
                return;
            }
        }
    }

    // Cache-Verzeichnis bestimmen
    if (defined('DIR_FS_DOCUMENT_ROOT')) {
        $mrhanf_fpc_dir = DIR_FS_DOCUMENT_ROOT . 'cache/fpc/';
    } elseif (defined('DIR_FS_CATALOG')) {
        $mrhanf_fpc_dir = DIR_FS_CATALOG . 'cache/fpc/';
    } else {
        return;
    }

    // Cache-Key generieren (URL-basiert)
    $mrhanf_fpc_key = md5($mrhanf_fpc_uri);
    $mrhanf_fpc_file = $mrhanf_fpc_dir . $mrhanf_fpc_key . '.html';

    // Cache-Lebensdauer
    $mrhanf_fpc_ttl = defined('MODULE_MRHANF_FPC_CACHE_TIME') ? (int) MODULE_MRHANF_FPC_CACHE_TIME : 86400;

    // Cache-HIT pruefen
    if (is_file($mrhanf_fpc_file)) {
        $mrhanf_fpc_age = time() - filemtime($mrhanf_fpc_file);
        if ($mrhanf_fpc_age < $mrhanf_fpc_ttl) {
            // Cache-HIT — sofort ausliefern
            header('X-MrHanf-Cache: HIT');
            header('X-MrHanf-Cache-Age: ' . $mrhanf_fpc_age . 's');
            readfile($mrhanf_fpc_file);
            exit;
        } else {
            // Abgelaufen — loeschen
            @unlink($mrhanf_fpc_file);
        }
    }

    // Cache-MISS — Output-Pufferung starten
    header('X-MrHanf-Cache: MISS');
    $GLOBALS['mrhanf_fpc_file'] = $mrhanf_fpc_file;
    $GLOBALS['mrhanf_fpc_dir']  = $mrhanf_fpc_dir;
    ob_start();
}
