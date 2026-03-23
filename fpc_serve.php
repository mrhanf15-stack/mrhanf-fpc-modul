<?php
/**
 * Mr. Hanf Full Page Cache v8.0.4 - Cache-Handler
 *
 * Dieses Script wird von Apache via RewriteRule [END] aufgerufen
 * und liefert gecachte HTML-Dateien per readfile() aus.
 *
 * Warum PHP statt direkter Apache-Auslieferung?
 *   - Artfiles cache/.htaccess blockiert .html mit 403
 *   - Direkte Auslieferung verursacht Redirect-Loop mit CLEAN SEO URL
 *   - PHP-Overhead: ~5ms (validiert + readfile + exit)
 *   - Zusaetzliche Validierung zur Laufzeit benoetigt wird
 *
 * Um auf den Fallback umzuschalten, aendern Sie in .htaccess:
 *   RewriteRule ^(.+)$ cache/fpc/$1/index.html [L,T=text/html]
 * zu:
 *   RewriteRule ^(.+)$ fpc_serve.php [L,QSA]
 *
 * @version   8.0.4
 * @date      2026-03-22
 */

// ============================================================
// KONFIGURATION
// ============================================================
$FPC_MIN_FILESIZE  = 500;      // Mindestgroesse in Bytes
$FPC_MAX_AGE       = 172800;   // Max. Alter in Sekunden (48h Fallback)
$FPC_HEALTH_MARKER = '<!-- FPC-VALID -->';  // Pflicht-Marker im HTML
$FPC_AUTO_DELETE   = true;     // Korrupte Dateien automatisch loeschen

// ============================================================
// SICHERHEITSCHECKS
// ============================================================

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

// --- Zweite Sicherheitsstufe: URL-basierte Ausschlussliste ---
$excluded_paths = array(
    '/vergleich',           // Produktvergleich (sessionabhaengig)
    '/wishlist',            // Merkzettel (sessionabhaengig)
    '/checkout',            // Bestellprozess
    '/kasse',               // Kasse/Checkout (SEO-URL) - v8.0.1
    '/login',               // Login-Seite
    '/account',             // Kundenkonto
    '/shopping_cart',       // Warenkorb (alt)
    '/warenkorb',           // Warenkorb (SEO-URL) - v8.0.1
    '/logoff',              // Abmelden
    '/password_double_opt', // Passwort-Opt-In
    '/create_account',      // Registrierung
    '/contact_us',          // Kontaktformular
    '/tell_a_friend',       // Weiterempfehlen
    '/product_reviews_write', // Bewertung schreiben
    '/admin',               // Admin-Bereich
);

foreach ($excluded_paths as $excluded) {
    if ($uri === $excluded || strpos($uri, $excluded . '/') === 0 || strpos($uri, $excluded . '?') === 0) {
        return false;
    }
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

// ============================================================
// VALIDIERUNG VOR AUSLIEFERUNG
// ============================================================

// 1. Dateigroesse pruefen (leere/korrupte Dateien abfangen)
$filesize = filesize($cache_file);
if ($filesize === false || $filesize < $FPC_MIN_FILESIZE) {
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
    return false;
}

// 2. TTL-Check: Abgelaufene Dateien nicht ausliefern
$mtime = filemtime($cache_file);
$age = time() - $mtime;
if ($age > $FPC_MAX_AGE) {
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
    return false;
}

// 3. Health-Marker pruefen (schnell: nur letzte 200 Bytes lesen)
$fp = fopen($cache_file, 'r');
if ($fp === false) {
    return false;
}
$seek_pos = max(0, $filesize - 200);
fseek($fp, $seek_pos);
$tail = fread($fp, 200);
fclose($fp);

if (strpos($tail, $FPC_HEALTH_MARKER) === false) {
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
    return false;
}

// ============================================================
// CACHE-DATEI AUSLIEFERN (validiert!)
// ============================================================

header('Content-Type: text/html; charset=utf-8');
header('X-FPC-Cache: HIT');
header('X-FPC-Version: 8.0.4');
header('X-FPC-Cached-At: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($cache_file);
exit;
