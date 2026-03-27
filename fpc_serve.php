<?php
/**
 * Mr. Hanf Full Page Cache v8.1.0 - Cache-Handler
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
 * CHANGELOG v8.1.0:
 *   - NEU: Session-Initializer JavaScript wird in gecachte Seiten injiziert
 *     Loesung fuer: "Warenkorb funktioniert erst beim zweiten Klick"
 *     Ursache: Gecachte Seiten hatten keine PHP-Session, daher wurde
 *     der erste Warenkorb-POST nicht korrekt verarbeitet.
 *     Fix: Ein kleines JS-Snippet ruft /fpc_session_init.php per AJAX auf
 *     und startet die Session im Hintergrund, bevor der Besucher klickt.
 *   - v8.0.9: FIX: Redirect-Loop bei Warenkorb-Aktionen behoben
 *
 * @version   8.1.0
 * @date      2026-03-27
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

// v8.0.7: Bypass-Cookie pruefen
// Das Autoinclude 95_fpc_bypass_cookie.php setzt "fpc_bypass=1" wenn:
//   - Der Warenkorb Artikel enthaelt
//   - Der Benutzer eingeloggt ist
// HINWEIS: MODsid wird NICHT geprueft (modified setzt es bei jedem Gast).
if (isset($_COOKIE['fpc_bypass']) && $_COOKIE['fpc_bypass'] === '1') {
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
header('X-FPC-Version: 8.1.0');
header('X-FPC-Cached-At: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ============================================================
// v8.1.0: SESSION-INITIALIZER INJECTION
// ============================================================
// Lese die gecachte Datei und injiziere ein kleines JavaScript-Snippet
// direkt vor </body>. Das Snippet ruft /fpc_session_init.php per AJAX auf
// und startet die PHP-Session im Hintergrund.
//
// Warum nicht einfach readfile()?
//   - readfile() kann keinen Code injizieren
//   - Wir muessen das JS-Snippet VOR </body> einfuegen
//   - Der Overhead ist minimal (~2ms fuer file_get_contents + str_replace)
//
// Warum nicht im Preloader das JS schon einbauen?
//   - Der Preloader cached die Seite wie sie vom Shop kommt
//   - Aenderungen am JS-Snippet wuerden einen kompletten Cache-Rebuild erfordern
//   - Injection zur Laufzeit ist flexibler und sofort wirksam

$html = file_get_contents($cache_file);

// Session-Init Script - wird nur ausgefuehrt wenn noch kein MODsid Cookie existiert
$session_init_js = <<<'SESSIONJS'
<script data-fpc-session-init="1">
(function(){
    // Nur ausfuehren wenn noch keine Session existiert (kein MODsid Cookie)
    if (document.cookie.indexOf('MODsid=') !== -1) return;
    
    // AJAX-Call zum Session-Initializer
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '/fpc_session_init.php?t=' + Date.now(), true);
    xhr.withCredentials = true; // Cookies mitsenden/empfangen
    xhr.timeout = 5000; // 5s Timeout
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.ok) {
                    // Session gestartet - Formulare sind jetzt funktionsfaehig
                    document.documentElement.setAttribute('data-fpc-session', 'ready');
                }
            } catch(e) {}
        }
    };
    xhr.onerror = function() {};
    xhr.ontimeout = function() {};
    xhr.send();
})();
</script>
SESSIONJS;

// Injiziere vor </body>
$html = str_replace('</body>', $session_init_js . "\n</body>", $html);

// Ausgabe
echo $html;
exit;
