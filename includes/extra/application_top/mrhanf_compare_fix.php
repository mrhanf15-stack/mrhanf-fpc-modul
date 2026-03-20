<?php
/**
 * Mr. Hanf - Produktvergleich Cookie-Fix
 *
 * Problem: Der Produktvergleich speichert die Vergleichsliste im Browser-Cookie
 * "pc_compare_ids". Wenn ein Kunde sich abmeldet, bleibt dieser Cookie erhalten.
 * Beim naechsten Seitenaufruf stellt der cookieRestore-Mechanismus die alten
 * Produkte wieder in die Server-Session -- obwohl der Kunde abgemeldet ist.
 *
 * Loesung:
 *   1. Beim Abmelden (logoff): pc_compare_ids Cookie loeschen
 *   2. Beim Anmelden (login_success): Vergleichsliste aus der Datenbank
 *      wiederherstellen (falls der Kunde vorher Produkte gespeichert hatte)
 *
 * Installationspfad: includes/extra/application_top/mrhanf_compare_fix.php
 * Kompatibel mit: modified eCommerce v2.0.7.2+
 */

defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

// Nur ausfuehren wenn Seitenname bekannt ist
if (!isset($PHP_SELF)) {
    return;
}

$current_page = basename($PHP_SELF, '.php');

// -----------------------------------------------------------------------
// FALL 1: Abmelden — Cookie loeschen
// -----------------------------------------------------------------------
if ($current_page === 'logoff') {
    // pc_compare_ids Cookie loeschen (auf alle moeglichen Domain-Varianten)
    $cookie_params = array(
        array('path' => '/', 'domain' => ''),
        array('path' => '/', 'domain' => '.mr-hanf.de'),
        array('path' => '/', 'domain' => 'mr-hanf.de'),
        array('path' => '/', 'domain' => 'www.mr-hanf.de'),
    );

    foreach ($cookie_params as $params) {
        setcookie(
            'pc_compare_ids',
            '',
            array(
                'expires'  => time() - 3600,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => true,
                'httponly' => false, // JavaScript muss den Cookie lesen koennen
                'samesite' => 'Lax',
            )
        );
    }

    // Auch die Server-Session-Vergleichsliste leeren
    if (isset($_SESSION['compare_products'])) {
        unset($_SESSION['compare_products']);
    }
    if (isset($_SESSION['product_compare'])) {
        unset($_SESSION['product_compare']);
    }
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
