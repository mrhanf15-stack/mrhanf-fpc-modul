<?php
/**
 * Mr. Hanf FPC v8.0.7 - Bypass-Cookie fuer Warenkorb
 *
 * Dieses Script wird automatisch ueber das Autoinclude-System geladen
 * (includes/extra/application_top/application_top_end/).
 *
 * Logik:
 *   - Wenn der Warenkorb Artikel enthaelt -> Cookie "fpc_bypass=1" setzen
 *   - Wenn der Warenkorb leer ist -> Cookie "fpc_bypass" loeschen
 *   - Der FPC (.htaccess + fpc_serve.php) prueft dieses Cookie und
 *     liefert nur dann die gecachte Seite, wenn KEIN fpc_bypass Cookie existiert.
 *
 * Warum nicht MODsid pruefen?
 *   modified-Shop setzt bei JEDEM Besucher (auch Gaeste ohne Warenkorb)
 *   sofort ein MODsid-Session-Cookie. Daher kann MODsid nicht als
 *   Indikator fuer "aktiver Warenkorb" verwendet werden.
 *
 * @version   8.0.7
 * @date      2026-03-25
 */

// Nur im Frontend ausfuehren (nicht im Admin)
if (defined('ADMIN_PATH_PREFIX') || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/admin') === 0)) {
    return;
}

// Pruefen ob der Warenkorb Artikel enthaelt
$cart_has_items = false;

if (isset($_SESSION['cart']) && is_object($_SESSION['cart'])) {
    // modified-Shop: $_SESSION['cart'] ist ein shoppingCart-Objekt
    if (method_exists($_SESSION['cart'], 'count_contents')) {
        $cart_has_items = ($_SESSION['cart']->count_contents() > 0);
    }
} elseif (isset($_SESSION['cart']->contents) && is_array($_SESSION['cart']->contents)) {
    $cart_has_items = (count($_SESSION['cart']->contents) > 0);
}

// Auch pruefen ob der Benutzer eingeloggt ist
$user_logged_in = false;
if (isset($_SESSION['customer_id']) && (int)$_SESSION['customer_id'] > 0) {
    $user_logged_in = true;
}

// Cookie setzen oder loeschen
$cookie_name = 'fpc_bypass';
$cookie_path = '/';
$cookie_domain = '';
$cookie_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');

if ($cart_has_items || $user_logged_in) {
    // Bypass-Cookie setzen (FPC wird umgangen)
    if (!isset($_COOKIE[$cookie_name]) || $_COOKIE[$cookie_name] !== '1') {
        setcookie($cookie_name, '1', 0, $cookie_path, $cookie_domain, $cookie_secure, false);
        $_COOKIE[$cookie_name] = '1'; // Sofort im aktuellen Request verfuegbar
    }
} else {
    // Bypass-Cookie loeschen (FPC kann wieder greifen)
    if (isset($_COOKIE[$cookie_name])) {
        setcookie($cookie_name, '', time() - 3600, $cookie_path, $cookie_domain, $cookie_secure, false);
        unset($_COOKIE[$cookie_name]);
    }
}
