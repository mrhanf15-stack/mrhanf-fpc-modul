<?php
/**
 * Mr. Hanf FPC v8.2.0 - Bypass-Cookie SOFORT bei Warenkorb-Add setzen
 *
 * KRITISCHER FIX: Dieses Script wird vom modified-Hook
 *   "add_product_before_redirect" aufgerufen - also NACHDEM der Artikel
 *   zum Warenkorb hinzugefuegt wurde, aber BEVOR der 302-Redirect
 *   zurueck zur Produktseite passiert.
 *
 * Ohne dieses Script:
 *   1. Besucher klickt "In den Warenkorb"
 *   2. modified fuegt Artikel hinzu
 *   3. modified macht 302-Redirect zurueck zur Produktseite
 *   4. Browser folgt Redirect -> GET /produkt-seite (ohne Query-String)
 *   5. fpc_serve.php liefert gecachte Seite (mit leerem Warenkorb-Badge!)
 *   6. Besucher sieht: Warenkorb leer (obwohl Artikel drin ist)
 *   7. Erst nach manuellem Reload wird der Warenkorb korrekt angezeigt
 *
 * Mit diesem Script:
 *   1. Besucher klickt "In den Warenkorb"
 *   2. modified fuegt Artikel hinzu
 *   3. >>> DIESES SCRIPT setzt fpc_bypass=1 Cookie <<<
 *   4. modified macht 302-Redirect (Cookie ist im Response-Header!)
 *   5. Browser folgt Redirect -> GET /produkt-seite MIT fpc_bypass Cookie
 *   6. fpc_serve.php erkennt fpc_bypass=1 -> return false -> PHP uebernimmt
 *   7. Seite wird dynamisch gerendert mit aktuellem Warenkorb-Inhalt
 *
 * Platzierung:
 *   includes/extra/cart_actions/add_product_before_redirect/95_fpc_bypass.php
 *
 * @version   8.2.0
 * @date      2026-03-27
 */

// Bypass-Cookie sofort setzen damit der nachfolgende Redirect
// NICHT aus dem FPC-Cache bedient wird
setcookie('fpc_bypass', '1', array(
    'expires'  => 0,
    'path'     => '/',
    'domain'   => '.mr-hanf.de',
    'secure'   => true,
    'httponly'  => false,
    'samesite' => 'Lax',
));
$_COOKIE['fpc_bypass'] = '1';
