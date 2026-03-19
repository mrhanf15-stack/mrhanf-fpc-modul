<?php
/**
 * Mr. Hanf Full Page Cache — System Module
 *
 * @version     2.2.0
 * @php         8.1+
 * @author      Manus AI für Mr. Hanf (mr-hanf.de)
 * @copyright   2026 Mr. Hanf
 *
 * Changelog v2.2.0:
 *   - BUGFIX: Sprachdatei wird jetzt direkt vom Modul geladen (Fallback)
 *     Modified v2.0.7.2 lädt lang/modules/system/ nicht immer automatisch
 *   - Alle Fixes aus v2.1.0 enthalten
 */

defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

// ---------------------------------------------------------------
// Sprachdatei laden — MUSS vor der Klasse passieren,
// damit die Konstanten beim Instanziieren verfügbar sind.
// ---------------------------------------------------------------
$_mrhanf_fpc_lang_dir = defined('DIR_FS_LANGUAGES')
    ? DIR_FS_LANGUAGES
    : (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG . 'lang/' : '');

if ($_mrhanf_fpc_lang_dir !== '') {
    $_mrhanf_fpc_lang = isset($_SESSION['language']) ? $_SESSION['language'] : 'german';
    $_mrhanf_fpc_lang_file = $_mrhanf_fpc_lang_dir . $_mrhanf_fpc_lang . '/modules/system/mrhanf_fpc.php';

    if (is_file($_mrhanf_fpc_lang_file) && !defined('MODULE_MRHANF_FPC_TITLE')) {
        include_once($_mrhanf_fpc_lang_file);
    }
}
unset($_mrhanf_fpc_lang_dir, $_mrhanf_fpc_lang, $_mrhanf_fpc_lang_file);

class mrhanf_fpc
{
    /**
     * Module prefix for configuration keys.
     * @var string
     */
    public $prefix;

    /**
     * Unique module code — used by modified to identify the module.
     * @var string
     */
    public $code;

    /**
     * Module title displayed in admin module list.
     * @var string
     */
    public $title;

    /**
     * Module description displayed in admin module detail.
     * @var string
     */
    public $description;

    /**
     * Sort order for module list display.
     * @var int
     */
    public $sort_order;

    /**
     * Whether the module is currently enabled.
     * @var bool
     */
    public $enabled;

    /**
     * Internal check cache — used by check() to avoid repeated DB queries.
     * @var int|null
     */
    public $_check;

    /**
     * Relative path to the FPC cache directory (from shop root).
     */
    private $cache_dir_relative = 'cache/fpc/';

    public function __construct()
    {
        $this->prefix      = 'MODULE_MRHANF_FPC';
        $this->code        = 'mrhanf_fpc';
        $this->title       = defined($this->prefix . '_TITLE')
            ? constant($this->prefix . '_TITLE')
            : 'Mr. Hanf Full Page Cache';
        $this->description = defined($this->prefix . '_DESC')
            ? constant($this->prefix . '_DESC')
            : 'Aktiviert den Full-Page-Cache für extrem schnelle Ladezeiten.';
        $this->sort_order  = defined($this->prefix . '_SORT_ORDER')
            ? (int) constant($this->prefix . '_SORT_ORDER')
            : 0;
        $this->enabled     = (defined($this->prefix . '_STATUS')
            && constant($this->prefix . '_STATUS') == 'true');
    }

    /**
     * Frontend process hook — not needed, logic runs via auto-include hooks.
     */
    public function process()
    {
    }

    /**
     * Display the configuration form with Save/Cancel buttons.
     * Called by modified admin when editing the module settings.
     *
     * @return array
     */
    public function display()
    {
        return array(
            'text' => '<br />'
                . '<div align="center">'
                . xtc_button(BUTTON_SAVE)
                . '&nbsp;'
                . xtc_button_link(
                    BUTTON_CANCEL,
                    xtc_href_link(
                        FILENAME_MODULE_EXPORT,
                        'set=' . $_GET['set'] . '&module=' . $this->code
                    )
                )
                . '</div>',
        );
    }

    /**
     * Check if the module is installed (configuration exists in DB).
     *
     * @return int
     */
    public function check()
    {
        if (!isset($this->_check)) {
            $check_query  = xtc_db_query(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . $this->prefix . "_STATUS'"
            );
            $this->_check = xtc_db_num_rows($check_query);
        }
        return $this->_check;
    }

    /**
     * Install the module — create configuration entries and cache directory.
     */
    public function install()
    {
        // STATUS — Dropdown true/false
        xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, date_added)"
            . " VALUES ('"
            . xtc_db_input($this->prefix . '_STATUS') . "', 'true', '6', '1',"
            . " 'xtc_cfg_select_option(array(\'true\', \'false\'), ', now())"
        );

        // CACHE_TIME — Lebensdauer in Sekunden
        xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added)"
            . " VALUES ('"
            . xtc_db_input($this->prefix . '_CACHE_TIME') . "', '86400', '6', '2', now())"
        );

        // EXCLUDED_PAGES — Kommagetrennte Ausschlussliste
        xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added)"
            . " VALUES ('"
            . xtc_db_input($this->prefix . '_EXCLUDED_PAGES')
            . "', 'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write',"
            . " '6', '3', now())"
        );

        // SORT_ORDER
        xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added)"
            . " VALUES ('"
            . xtc_db_input($this->prefix . '_SORT_ORDER') . "', '0', '6', '4', now())"
        );

        // Cache-Verzeichnis anlegen
        if (defined('DIR_FS_DOCUMENT_ROOT')) {
            $cache_dir = DIR_FS_DOCUMENT_ROOT . $this->cache_dir_relative;
            if (!is_dir($cache_dir)) {
                @mkdir($cache_dir, 0755, true);
            }
        }

        // Admin-Berechtigung hinzufügen
        $this->_addAdminAccess();
    }

    /**
     * Remove the module — delete configuration entries.
     */
    public function remove()
    {
        xtc_db_query(
            "DELETE FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key LIKE '" . $this->prefix . "_%'"
        );

        // Admin-Berechtigung entfernen
        $this->_removeAdminAccess();
    }

    /**
     * Return the list of configuration keys for this module.
     * Modified uses this to render the settings form.
     *
     * @return array
     */
    public function keys()
    {
        return array(
            $this->prefix . '_STATUS',
            $this->prefix . '_CACHE_TIME',
            $this->prefix . '_EXCLUDED_PAGES',
            $this->prefix . '_SORT_ORDER',
        );
    }

    /**
     * Add admin_access column for this module.
     */
    private function _addAdminAccess()
    {
        // Prüfe ob die Spalte bereits existiert
        $result = xtc_db_query(
            "SHOW COLUMNS FROM admin_access LIKE '" . $this->code . "'"
        );
        if (xtc_db_num_rows($result) == 0) {
            xtc_db_query(
                "ALTER TABLE admin_access ADD COLUMN `" . $this->code . "` INT(1) NOT NULL DEFAULT '0'"
            );
            // Admin (customers_id = 1 oder groups_id = 1) Zugriff gewähren
            xtc_db_query(
                "UPDATE admin_access SET `" . $this->code . "` = '1' WHERE customers_id = '1'"
            );
            // Auch dem aktuellen Admin Zugriff gewähren
            if (isset($_SESSION['customer_id'])) {
                xtc_db_query(
                    "UPDATE admin_access SET `" . $this->code . "` = '1'"
                    . " WHERE customers_id = '" . (int) $_SESSION['customer_id'] . "'"
                );
            }
        }
    }

    /**
     * Remove admin_access column for this module.
     */
    private function _removeAdminAccess()
    {
        $result = xtc_db_query(
            "SHOW COLUMNS FROM admin_access LIKE '" . $this->code . "'"
        );
        if (xtc_db_num_rows($result) > 0) {
            xtc_db_query(
                "ALTER TABLE admin_access DROP COLUMN `" . $this->code . "`"
            );
        }
    }
}
