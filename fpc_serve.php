<?php
/**
 * Mr. Hanf Full Page Cache v7.0.0 - Statischer Cache-Handler (Ausfallsicher)
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
 *
 * CHANGELOG v7.0.0:
 *   - Validierung: Leere/korrupte Cache-Dateien werden NICHT ausgeliefert
 *   - Mindestgroesse: Dateien < 500 Bytes werden uebersprungen
 *   - Health-Marker: Prueft auf <!-- FPC-VALID --> Marker
 *   - TTL-Check: Abgelaufene Dateien werden nicht ausgeliefert
 *   - Auto-Cleanup: Korrupte/abgelaufene Dateien werden automatisch geloescht
 *   - Fallback: Bei ungueltigem Cache -> return false -> normaler Shop-Ablauf
 *
 * CHANGELOG v7.0.3:
 *   - 304 Not Modified / ETag / Last-Modified ENTFERNT
 *   - Diese Header verursachten weisse Seiten beim Browser-Refresh
 *   - Der Artfiles-Proxy/Apache hat bei 304 content-length:0 gesendet
 *   - Cache wird jetzt IMMER vollstaendig ausgeliefert (sicherer)
 *   - Cache-Control: no-store verhindert Browser-Caching der FPC-Antwort
 *
 * @version   7.0.3
 * @date      2026-03-22
 */

// ============================================================
// KONFIGURATION
// ============================================================
$FPC_MIN_FILESIZE  = 500;      // Mindestgroesse in Bytes
$FPC_MAX_AGE       = 172800;   // Max. Alter in Sekunden (48h Fallback, falls DB nicht erreichbar)
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
    '/login',               // Login-Seite
    '/account',             // Kundenkonto
    '/shopping_cart',       // Warenkorb
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
// NEU v7.0: VALIDIERUNG VOR AUSLIEFERUNG
// ============================================================

// 1. Dateigroesse pruefen (leere/korrupte Dateien abfangen)
$filesize = filesize($cache_file);
if ($filesize === false || $filesize < $FPC_MIN_FILESIZE) {
    // Korrupte Datei -> loeschen und Fallback zum normalen Shop
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
    return false;
}

// 2. TTL-Check: Abgelaufene Dateien nicht ausliefern
$mtime = filemtime($cache_file);
$age = time() - $mtime;
if ($age > $FPC_MAX_AGE) {
    // Abgelaufene Datei -> loeschen und Fallback zum normalen Shop
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
    // Kein Health-Marker -> Datei ist korrupt oder unvollstaendig
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
    return false;
}

// ============================================================
// CACHE-DATEI AUSLIEFERN (validiert!)
// ============================================================

// HTTP-Header setzen
// WICHTIG: Kein Last-Modified, kein ETag, kein 304!
// Diese verursachten weisse Seiten beim Browser-Refresh auf Artfiles-Servern.
// Der FPC liefert IMMER den vollstaendigen HTML-Inhalt aus.
header('Content-Type: text/html; charset=utf-8');
header('X-FPC-Cache: HIT');
header('X-FPC-Version: 7.0.3');
header('X-FPC-Cached-At: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

// Browser soll die FPC-Antwort NICHT cachen (verhindert 304-Probleme)
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Datei ausgeben
readfile($cache_file);
exit;
