<?php

declare(strict_types=1);

/**
 * Mr. Hanf Full Page Cache — application_bottom_end Hook
 * v1.2.1 — ob_level-Sicherung, atomares Schreiben, Fehlerprotokoll
 *
 * @version  1.2.1
 * @php      8.3+
 */

if (
    !isset($GLOBALS['fpc_is_cacheable'])
    || $GLOBALS['fpc_is_cacheable'] !== true
    || !isset($GLOBALS['fpc_cache_file'])
) {
    return;
}

// Sicherstellen dass wir auf dem richtigen ob-Level sind
$fpc_expected_level = ($GLOBALS['fpc_ob_level'] ?? 0) + 1;
if (ob_get_level() < $fpc_expected_level) {
    // Output Buffering wurde zwischendurch beendet — kein Caching möglich
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

// HTML-Kommentar anhängen
$fpc_html_to_save = $fpc_html . "\n<!-- MR-HANF FPC v1.2.1: Cached on {$fpc_timestamp} -->\n";

// Atomar schreiben: erst Temp-Datei, dann umbenennen
$fpc_tmp = $fpc_cache_file . '.tmp.' . getmypid();

if (file_put_contents($fpc_tmp, $fpc_html_to_save, LOCK_EX) !== false) {
    rename($fpc_tmp, $fpc_cache_file);
} else {
    // Schreiben fehlgeschlagen → Fehler loggen (optional)
    @unlink($fpc_tmp);
    $fpc_log = dirname($fpc_cache_file) . '/fpc_errors.log';
    @file_put_contents(
        $fpc_log,
        date('[Y-m-d H:i:s]') . ' WRITE FAILED: ' . $fpc_cache_file . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

ob_end_flush();
