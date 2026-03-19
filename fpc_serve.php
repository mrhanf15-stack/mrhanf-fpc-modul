<?php
/**
 * Mr. Hanf Full Page Cache v6.0.0 - Statischer Cache-Handler
 *
 * Dieses Script wird von .htaccess aufgerufen, wenn eine gecachte
 * Version einer Seite existiert. Es liefert die statische HTML-Datei
 * direkt aus, ohne den modified eCommerce Kern zu laden.
 *
 * WICHTIG: Dieses Script darf KEINE externen Abhaengigkeiten haben!
 * Es muss so schnell wie moeglich ausfuehren (Ziel: < 10ms).
 *
 * .htaccess leitet hierher weiter wenn:
 *   1. Kein Cookie 'MODsid' gesetzt ist (= Gast-Besucher)
 *   2. REQUEST_METHOD = GET
 *   3. Eine Cache-Datei unter cache/fpc/ existiert
 */

// Nur GET-Requests cachen
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    return false;
}

// Eingeloggte Benutzer nicht cachen (Session-Cookie vorhanden)
if (isset($_COOKIE['MODsid']) || isset($_COOKIE['PHPSESSID'])) {
    return false;
}

// Request-URI bereinigen
$uri = $_SERVER['REQUEST_URI'];

// Query-String entfernen (gecachte Seiten sind ohne Parameter)
$pos = strpos($uri, '?');
if ($pos !== false) {
    $uri = substr($uri, 0, $pos);
}

// Pfad normalisieren
$uri = rtrim($uri, '/');
if ($uri === '') {
    $uri = '/';
}

// Cache-Datei Pfad berechnen
$cache_dir  = __DIR__ . '/cache/fpc';
$clean_path = trim($uri, '/');

if ($clean_path === '') {
    $cache_file = $cache_dir . '/index.html';
} else {
    $cache_file = $cache_dir . '/' . $clean_path . '/index.html';
}

// Sicherheitscheck: Pfad darf nicht aus dem Cache-Verzeichnis ausbrechen
$real_cache = realpath($cache_dir);
if ($real_cache === false) {
    return false;
}

// Cache-Datei existiert?
if (!is_file($cache_file)) {
    return false;
}

// Realpath-Check (verhindert Directory Traversal)
$real_file = realpath($cache_file);
if ($real_file === false || strpos($real_file, $real_cache) !== 0) {
    return false;
}

// Cache-Datei ausliefern
$mtime = filemtime($cache_file);

// HTTP-Caching-Header setzen
header('Content-Type: text/html; charset=utf-8');
header('X-FPC-Cache: HIT');
header('X-FPC-Cached-At: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: public, max-age=300');

// ETag fuer Conditional Requests
$etag = '"fpc-' . md5($cache_file . $mtime) . '"';
header('ETag: ' . $etag);

// 304 Not Modified wenn moeglich
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
    if ($since !== false && $since >= $mtime) {
        http_response_code(304);
        exit;
    }
}

// Datei ausgeben
readfile($cache_file);
exit;
