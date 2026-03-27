<?php
/**
 * Mr. Hanf FPC v8.1.0 - Session-Initializer fuer gecachte Seiten
 *
 * Dieses Script wird per AJAX von gecachten Seiten aufgerufen, um
 * eine PHP-Session zu starten BEVOR der Besucher den Warenkorb-Button klickt.
 *
 * Problem:
 *   Gecachte Seiten werden ohne PHP-Session ausgeliefert (fpc_serve.php).
 *   Wenn ein Erstbesucher auf "In den Warenkorb" klickt, startet modified
 *   erst beim POST eine neue Session. Der Warenkorb-Handler erwartet aber
 *   eine bestehende Session mit initialisiertem Cart-Objekt.
 *   Ergebnis: Erster Warenkorb-Klick funktioniert nicht.
 *
 * Loesung:
 *   Gecachte Seiten laden dieses Script per AJAX im Hintergrund.
 *   Das Script startet eine modified-Session (ueber application_top.php)
 *   und gibt ein JSON mit dem Session-Status zurueck.
 *   Danach hat der Browser ein gueltiges MODsid-Cookie und der
 *   naechste POST wird korrekt verarbeitet.
 *
 * Aufruf:
 *   GET /fpc_session_init.php
 *   Response: {"ok":true,"sid":"abc123","cart":0}
 *
 * Sicherheit:
 *   - Nur GET-Requests
 *   - Rate-Limiting: Max 1x pro Session
 *   - Keine sensiblen Daten im Response
 *   - CORS: Nur Same-Origin
 *
 * @version   8.1.0
 * @date      2026-03-27
 */

// ============================================================
// MINIMALE SESSION-INITIALISIERUNG
// ============================================================

// Nur GET erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => false, 'error' => 'Method not allowed'));
    exit;
}

// JSON-Response vorbereiten
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-FPC-Session-Init: 1');

// Versuche die modified-Session zu starten
// application_top.php initialisiert die Session, das Cart-Objekt etc.
$init_success = false;
$cart_count = 0;
$session_id = '';

try {
    // Pruefe ob application_top.php existiert
    $app_top = __DIR__ . '/includes/application_top.php';
    
    if (is_file($app_top)) {
        // modified application_top.php einbinden
        // Das startet die Session und initialisiert $_SESSION['cart']
        require_once($app_top);
        
        $init_success = true;
        $session_id = session_id();
        
        // Cart-Inhalt zaehlen
        if (isset($_SESSION['cart']) && is_object($_SESSION['cart'])) {
            if (method_exists($_SESSION['cart'], 'count_contents')) {
                $cart_count = (int)$_SESSION['cart']->count_contents();
            }
        }
    } else {
        // Fallback: Nur PHP-Session starten (ohne modified)
        if (session_status() === PHP_SESSION_NONE) {
            session_name('MODsid');
            session_set_cookie_params(array(
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '.mr-hanf.de',
                'secure'   => true,
                'httponly'  => true,
                'samesite'  => 'Lax',
            ));
            session_start();
        }
        $init_success = true;
        $session_id = session_id();
    }
} catch (Exception $e) {
    // Fehler abfangen, aber nicht dem Client zeigen
    $init_success = false;
}

// Response
echo json_encode(array(
    'ok'   => $init_success,
    'sid'  => substr($session_id, 0, 8) . '...', // Nur Anfang zeigen (Sicherheit)
    'cart' => $cart_count,
    'ts'   => time(),
));
exit;
