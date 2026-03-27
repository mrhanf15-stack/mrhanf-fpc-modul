<?php
/**
 * Mr. Hanf FPC v8.2.0 - Bypass-Cookie bei buy_now Aktion
 *
 * Identisch mit add_product_before_redirect/95_fpc_bypass.php
 * Modified eCommerce hat separate Hooks fuer buy_now und add_product.
 * buy_now wird bei "Sofort kaufen" / direktem Warenkorb-Link verwendet.
 *
 * @version   8.2.0
 * @date      2026-03-27
 */

setcookie('fpc_bypass', '1', array(
    'expires'  => 0,
    'path'     => '/',
    'domain'   => '.mr-hanf.de',
    'secure'   => true,
    'httponly'  => false,
    'samesite' => 'Lax',
));
$_COOKIE['fpc_bypass'] = '1';
