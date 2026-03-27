<?php
/**
 * Mr. Hanf FPC v8.0.9 - Bypass-Cookie fuer Warenkorb
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
 * CHANGELOG v8.0.9:
 *   - FIX: Cookie-Domain auf ".mr-hanf.de" gesetzt (war leer!)
 *     Leere Domain fuehrte dazu, dass das Cookie bei Redirects
 *     nicht zuverlaessig mitgesendet wurde.
 *   - FIX: SameSite=Lax Attribut hinzugefuegt (konsistent mit MODsid)
 *   - FIX: Secure=true und HttpOnly=false explizit gesetzt
 *   - FIX: Verwendet jetzt setcookie() mit Options-Array (PHP 7.3+)
 *
 * @version   8.0.9
 * @date      2026-03-27
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

// v8.0.9: Cookie-Konfiguration
// WICHTIG: Domain MUSS mit dem MODsid-Cookie uebereinstimmen (.mr-hanf.de)
// damit der Browser das Cookie bei allen Requests zuverlaessig mitsendet.
$cookie_name   = 'fpc_bypass';
$cookie_path   = '/';
$cookie_domain = '.mr-hanf.de';
$cookie_secure = true;  // Shop laeuft nur ueber HTTPS

// v8.0.9: Cookie-Options-Array (PHP 7.3+)
// SameSite=Lax: Cookie wird bei Top-Level-Navigationen (Redirects) mitgesendet
// HttpOnly=false: Cookie muss von .htaccess (mod_rewrite) lesbar sein
$cookie_options_set = array(
    'expires'  => 0,          // Session-Cookie (wird beim Browser-Schliessen geloescht)
    'path'     => $cookie_path,
    'domain'   => $cookie_domain,
    'secure'   => $cookie_secure,
    'httponly'  => false,
    'samesite'  => 'Lax',
);

$cookie_options_delete = array(
    'expires'  => time() - 3600,
    'path'     => $cookie_path,
    'domain'   => $cookie_domain,
    'secure'   => $cookie_secure,
    'httponly'  => false,
    'samesite'  => 'Lax',
);

if ($cart_has_items || $user_logged_in) {
    // Bypass-Cookie setzen (FPC wird umgangen)
    if (!isset($_COOKIE[$cookie_name]) || $_COOKIE[$cookie_name] !== '1') {
        setcookie($cookie_name, '1', $cookie_options_set);
        $_COOKIE[$cookie_name] = '1'; // Sofort im aktuellen Request verfuegbar
    }
} else {
    // Bypass-Cookie loeschen (FPC kann wieder greifen)
    if (isset($_COOKIE[$cookie_name])) {
        setcookie($cookie_name, '', $cookie_options_delete);
        unset($_COOKIE[$cookie_name]);
    }
}
