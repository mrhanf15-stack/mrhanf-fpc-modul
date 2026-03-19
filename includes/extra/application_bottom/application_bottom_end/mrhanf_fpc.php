<?php
/**
 * Mr. Hanf Full Page Cache v4.0.0 – Cache-Save Hook (application_bottom_end)
 * Hookpoint: ~/includes/extra/application_bottom/application_bottom_end/
 *
 * Faengt den Output-Buffer ab und speichert ihn atomar als Cache-Datei.
 * Wird nur ausgefuehrt wenn application_top_begin einen Cache-MISS erkannt hat.
 */
if (isset($GLOBALS['mrhanf_fpc_file']) && $GLOBALS['mrhanf_fpc_file'] != '') {

    $mrhanf_fpc_html = ob_get_contents();
    ob_end_flush();

    // Nur cachen wenn tatsaechlich HTML-Inhalt vorhanden ist
    if (strlen($mrhanf_fpc_html) > 200) {

        $mrhanf_fpc_dir  = $GLOBALS['mrhanf_fpc_dir'];
        $mrhanf_fpc_file = $GLOBALS['mrhanf_fpc_file'];

        // Cache-Verzeichnis anlegen falls noetig
        if (!is_dir($mrhanf_fpc_dir)) {
            @mkdir($mrhanf_fpc_dir, 0755, true);
        }

        // HTML-Kommentar mit Zeitstempel anfuegen
        $mrhanf_fpc_html .= "\n<!-- FPC cached: " . date('Y-m-d H:i:s') . " -->\n";

        // Atomar schreiben: tmp-Datei → rename
        $mrhanf_fpc_tmp = $mrhanf_fpc_file . '.tmp.' . getmypid();
        $mrhanf_fpc_written = @file_put_contents($mrhanf_fpc_tmp, $mrhanf_fpc_html);

        if ($mrhanf_fpc_written !== false) {
            @rename($mrhanf_fpc_tmp, $mrhanf_fpc_file);
        } else {
            @unlink($mrhanf_fpc_tmp);
        }
    }

    // Globals aufraeumen
    unset($GLOBALS['mrhanf_fpc_file'], $GLOBALS['mrhanf_fpc_dir']);
}
