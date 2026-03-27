<?php
/**
 * Mr. Hanf FPC v8.2.0 - Bypass-Cookie fuer Warenkorb
 *
 * Dieses Script wird automatisch ueber das Autoinclude-System geladen
 * (includes/extra/application_top/application_top_end/).
 *
 * Logik:
 *   - Wenn der Warenkorb Artikel enthaelt -> Cookie "fpc_bypass=1" setzen
 *   - Wenn der Warenkorb leer ist UND nicht eingeloggt -> Cookie loeschen
 *   - Der FPC (fpc_serve.php) prueft dieses Cookie und liefert nur dann
 *     die gecachte Seite, wenn KEIN fpc_bypass Cookie existiert.
 *
 * HINWEIS: Dieses Script allein reicht NICHT fuer den Warenkorb-Fix!
 *   Der buy_now/add_product Redirect passiert VOR application_top_end.
 *   Daher wird das Cookie zusaetzlich in:
 *     - shoproot/includes/extra/cart_actions/add_product_before_redirect/95_fpc_bypass.php
 *   gesetzt (wird VOR dem Redirect ausgefuehrt).
 *
 *   Dieses Script hier dient als Sicherheitsnetz fuer:
 *     - Eingeloggte Benutzer
 *     - Benutzer die bereits Artikel im Warenkorb haben
 *     - Cookie-Cleanup wenn Warenkorb geleert wird
 *
 * @version   8.2.0
 * @date      2026-03-27
 */

// Nur im Frontend ausfuehren (nicht im Admin)
if (defined('ADMIN_PATH_PREFIX') || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/admin') === 0)) {
    return;
}

// Pruefen ob der Warenkorb Artikel enthaelt
$cart_has_items = false;

if (isset($_SESSION['cart']) && is_object($_SESSION['cart'])) {
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

// Cookie-Konfiguration
$cookie_name   = 'fpc_bypass';
$cookie_path   = '/';
$cookie_domain = '.mr-hanf.de';
$cookie_secure = true;

$cookie_options_set = array(
    'expires'  => 0,
    'path'     => $cookie_path,
    'domain'   => $cookie_domain,
    'secure'   => $cookie_secure,
    'httponly'  => false,
    'samesite' => 'Lax',
);

$cookie_options_delete = array(
    'expires'  => time() - 3600,
    'path'     => $cookie_path,
    'domain'   => $cookie_domain,
    'secure'   => $cookie_secure,
    'httponly'  => false,
    'samesite' => 'Lax',
);

if ($cart_has_items || $user_logged_in) {
    if (!isset($_COOKIE[$cookie_name]) || $_COOKIE[$cookie_name] !== '1') {
        setcookie($cookie_name, '1', $cookie_options_set);
        $_COOKIE[$cookie_name] = '1';
    }
} else {
    if (isset($_COOKIE[$cookie_name])) {
        setcookie($cookie_name, '', $cookie_options_delete);
        unset($_COOKIE[$cookie_name]);
    }
}
