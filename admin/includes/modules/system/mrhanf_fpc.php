<?php
/* -----------------------------------------------------------------------------------------
   Mr. Hanf Full Page Cache (FPC) — System Module v3.0.0
   modified eCommerce Shopsoftware
   http://www.modified-shop.org

   Autor: Manus AI fuer Mr. Hanf (mr-hanf.de)
   -----------------------------------------------------------------------------------------
   Released under the GNU General Public License
   ---------------------------------------------------------------------------------------*/

defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

// -----------------------------------------------------------------------------------------
// SPRACHDATEI LADEN — Fallback fuer modified-Versionen die das nicht automatisch tun.
// Muss VOR der Klassendefinition stehen, damit die Konstanten beim Instanziieren da sind.
// -----------------------------------------------------------------------------------------
if (!defined('MODULE_MRHANF_FPC_TEXT_TITLE')) {
    // Versuche ueber DIR_FS_LANGUAGES (Standard-Pfad in modified)
    if (defined('DIR_FS_LANGUAGES') && isset($_SESSION['language'])) {
        $_mrhanf_fpc_lf = DIR_FS_LANGUAGES . $_SESSION['language'] . '/modules/system/mrhanf_fpc.php';
        if (is_file($_mrhanf_fpc_lf)) {
            include($_mrhanf_fpc_lf);
        }
        unset($_mrhanf_fpc_lf);
    }
}

// Absoluter Fallback: Wenn die Sprachdatei immer noch nicht geladen wurde,
// definiere die Konstanten hier direkt als Hardcoded-Fallback.
if (!defined('MODULE_MRHANF_FPC_TEXT_TITLE')) {
    define('MODULE_MRHANF_FPC_TEXT_TITLE', 'Mr. Hanf Full Page Cache');
    define('MODULE_MRHANF_FPC_TEXT_DESCRIPTION', 'Full Page Cache fuer Gaeste.');
    define('MODULE_MRHANF_FPC_STATUS_TITLE', 'Modul aktivieren');
    define('MODULE_MRHANF_FPC_STATUS_DESC', 'Soll der Cache aktiviert werden?');
    define('MODULE_MRHANF_FPC_CACHE_TIME_TITLE', 'Cache Lebensdauer (Sekunden)');
    define('MODULE_MRHANF_FPC_CACHE_TIME_DESC', 'Standard: 86400 (24 Stunden)');
    define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_TITLE', 'Ausgeschlossene Seiten');
    define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_DESC', 'Kommagetrennte Liste von URL-Teilen.');
    define('MODULE_MRHANF_FPC_SORT_ORDER_TITLE', 'Sortierreihenfolge');
    define('MODULE_MRHANF_FPC_SORT_ORDER_DESC', 'Reihenfolge der Anzeige.');
}

class mrhanf_fpc
{
    var $code, $title, $description, $sort_order, $enabled, $_check;

    function __construct()
    {
        $this->code        = 'mrhanf_fpc';
        $this->title       = MODULE_MRHANF_FPC_TEXT_TITLE;
        $this->description = MODULE_MRHANF_FPC_TEXT_DESCRIPTION;
        $this->sort_order  = defined('MODULE_MRHANF_FPC_SORT_ORDER') ? MODULE_MRHANF_FPC_SORT_ORDER : 0;
        $this->enabled     = ((defined('MODULE_MRHANF_FPC_STATUS') && MODULE_MRHANF_FPC_STATUS == 'true') ? true : false);
    }

    function process($file)
    {
    }

    function display()
    {
        return array(
            'text' => '<br /><div align="center">'
                . xtc_button(BUTTON_SAVE)
                . xtc_button_link(BUTTON_CANCEL, xtc_href_link(FILENAME_MODULE_EXPORT, 'set=' . $_GET['set'] . '&module=mrhanf_fpc'))
                . '</div>'
        );
    }

    function check()
    {
        if (!isset($this->_check)) {
            $check_query  = xtc_db_query("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_MRHANF_FPC_STATUS'");
            $this->_check = xtc_db_num_rows($check_query);
        }
        return $this->_check;
    }

    function install()
    {
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, date_added) VALUES ('MODULE_MRHANF_FPC_STATUS', 'true', '6', '1', 'xtc_cfg_select_option(array(\'true\', \'false\'), ', now())");
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) VALUES ('MODULE_MRHANF_FPC_CACHE_TIME', '86400', '6', '2', now())");
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) VALUES ('MODULE_MRHANF_FPC_EXCLUDED_PAGES', 'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write', '6', '3', now())");
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) VALUES ('MODULE_MRHANF_FPC_SORT_ORDER', '0', '6', '4', now())");

        // Cache-Verzeichnis anlegen
        if (defined('DIR_FS_DOCUMENT_ROOT')) {
            $cache_dir = DIR_FS_DOCUMENT_ROOT . 'cache/fpc/';
        } elseif (defined('DIR_FS_CATALOG')) {
            $cache_dir = DIR_FS_CATALOG . 'cache/fpc/';
        }
        if (isset($cache_dir) && !is_dir($cache_dir)) {
            @mkdir($cache_dir, 0755, true);
        }
    }

    function remove()
    {
        xtc_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key LIKE 'MODULE_MRHANF_FPC_%'");
    }

    function keys()
    {
        return array(
            'MODULE_MRHANF_FPC_STATUS',
            'MODULE_MRHANF_FPC_CACHE_TIME',
            'MODULE_MRHANF_FPC_EXCLUDED_PAGES',
            'MODULE_MRHANF_FPC_SORT_ORDER',
        );
    }
}
