<?php

declare(strict_types=1);

/**
 * Mr. Hanf Full Page Cache — application_bottom_end Hook
 *
 * Wird von Modified automatisch ganz am Ende von application_bottom.php geladen.
 * Speichert das fertig gerenderte HTML als Cache-Datei.
 *
 * @version  1.2.0
 * @php      8.3+
 */

if (
    !isset($GLOBALS['fpc_is_cacheable'])
    || $GLOBALS['fpc_is_cacheable'] !== true
    || !isset($GLOBALS['fpc_cache_file'])
) {
    return;
}

$fpc_html = ob_get_contents();

// Nur speichern wenn sinnvoller HTML-Inhalt vorhanden (mind. 1 KB)
if (!is_string($fpc_html) || strlen($fpc_html) < 1024) {
    ob_end_flush();
    return;
}

$fpc_cache_file = $GLOBALS['fpc_cache_file'];
$fpc_timestamp  = date('Y-m-d H:i:s');

// HTML-Kommentar für Debug-Zwecke anhängen
$fpc_html .= "\n<!-- MR-HANF FPC v1.2: Cached on {$fpc_timestamp} -->\n";

// Atomar schreiben: erst in Temp-Datei, dann umbenennen (verhindert Race Conditions)
$fpc_tmp_file = $fpc_cache_file . '.tmp.' . getmypid();

if (file_put_contents($fpc_tmp_file, $fpc_html, LOCK_EX) !== false) {
    rename($fpc_tmp_file, $fpc_cache_file);
} else {
    // Schreiben fehlgeschlagen → Temp-Datei aufräumen
    @unlink($fpc_tmp_file);
}

ob_end_flush();
