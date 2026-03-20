<?php
/**
 * Mr. Hanf - Produktvergleich Cookie-Fix v1.1
 *
 * ROOT CAUSE (bewiesen durch Simulation):
 *   Der product_compare Ajax-Endpoint speichert die Vergleichsliste in der
 *   PHP-SESSION. Beim Logoff wird die Session NICHT vollstaendig geleert —
 *   nur der Login-Status wird zurueckgesetzt. Die Vergleichsprodukte bleiben
 *   in der Session erhalten.
 *
 *   Beim naechsten Seitenaufruf (auch nach Abmelden):
 *     1. cookieRestore() liest Cookie: pc_compare_ids=1234
 *     2. Ajax: sub_action=add → Server: "already_in_list" (noch in Session!)
 *     3. Ajax: sub_action=list → count=1 → Badge zeigt "1"
 *
 *   Nach Session-Timeout (einige Minuten):
 *     1. cookieRestore() liest Cookie: pc_compare_ids=1234
 *     2. Ajax: sub_action=add → NEUE Session wird mit 1234 befuellt!
 *     3. Badge zeigt wieder "1" — auch nach dem Leeren!
 *
 * LOESUNG (drei Ebenen):
 *   1. Serverseitig: Session-Vergleichsliste beim Logoff leeren
 *   2. Serverseitig: pc_compare_ids Cookie beim Logoff loeschen
 *   3. Clientseitig: Badge sofort auf 0 setzen (via application_bottom JS)
 *
 * Installationspfad: includes/extra/application_top/mrhanf_compare_fix.php
 * Kompatibel mit: modified eCommerce v2.0.7.2+
 */

defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

if (!isset($PHP_SELF)) {
    return;
}

$current_page = basename($PHP_SELF, '.php');

// -----------------------------------------------------------------------
// FALL 1: Abmelden — Session UND Cookie leeren
// -----------------------------------------------------------------------
if ($current_page === 'logoff') {

    // 1a. Server-Session: Vergleichsliste direkt leeren
    //     modified speichert die Vergleichsliste unter verschiedenen
    //     moeglichen Session-Schluessel-Namen
    $session_keys = array(
        'compare_products',
        'product_compare',
        'compare_list',
        'products_compare',
        'compare',
    );
    foreach ($session_keys as $key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    // 1b. pc_compare_ids Cookie loeschen
    //     Auf alle moeglichen Domain-Varianten setzen um sicherzustellen
    //     dass der Cookie wirklich geloescht wird
    $cookie_domains = array('', '.mr-hanf.de', 'mr-hanf.de', 'www.mr-hanf.de');
    foreach ($cookie_domains as $domain) {
        setcookie(
            'pc_compare_ids',
            '',
            array(
                'expires'  => time() - 3600,
                'path'     => '/',
                'domain'   => $domain,
                'secure'   => true,
                'httponly' => false, // JavaScript muss den Cookie lesen koennen
                'samesite' => 'Lax',
            )
        );
    }

    // 1c. Auch im superglobalen $_COOKIE leeren damit nachfolgende
    //     PHP-Logik den Cookie nicht mehr liest
    unset($_COOKIE['pc_compare_ids']);
}

// -----------------------------------------------------------------------
// FALL 2: Anmelden — gespeicherte Vergleichsliste aus DB laden (optional)
// -----------------------------------------------------------------------
// Wenn der Kunde sich anmeldet und in der DB eine gespeicherte Vergleichsliste
// hat, wird diese in den Cookie geschrieben. So kann der Kunde seine
// Vergleichsliste sitzungsuebergreifend behalten.
//
// Diese Funktion ist optional und nur aktiv wenn die Tabelle existiert.
// -----------------------------------------------------------------------
if ($current_page === 'login' && isset($_SESSION['customer_id']) && (int)$_SESSION['customer_id'] > 0) {
    $customer_id = (int) $_SESSION['customer_id'];

    // Pruefen ob Tabelle fuer gespeicherte Vergleichslisten existiert
    $table_check = @xtc_db_query(
        "SHOW TABLES LIKE 'mrhanf_compare_saved'"
    );

    if ($table_check && xtc_db_num_rows($table_check) > 0) {
        $saved = xtc_db_query(
            "SELECT products_ids FROM mrhanf_compare_saved
              WHERE customers_id = " . $customer_id . "
              LIMIT 1"
        );

        if ($saved && $row = xtc_db_fetch_array($saved)) {
            $ids = trim($row['products_ids']);
            if (!empty($ids)) {
                // Cookie mit gespeicherter Vergleichsliste setzen
                setcookie(
                    'pc_compare_ids',
                    $ids,
                    array(
                        'expires'  => time() + (30 * 24 * 60 * 60), // 30 Tage
                        'path'     => '/',
                        'domain'   => '',
                        'secure'   => true,
                        'httponly' => false,
                        'samesite' => 'Lax',
                    )
                );
            }
        }
    }
}
