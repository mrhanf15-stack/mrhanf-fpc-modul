<?php
//
// Mr. Hanf Full Page Cache v5.0.0 - System-Modul fuer modified eCommerce
// PHP 8.3 kompatibel | Auto-Include System
//
// Aktiviert einen extrem schnellen HTML-Cache fuer Gaeste.
// Reduziert den TTFB von 2-3 Sekunden auf unter 0,1 Sekunden.
//
// v5.0.0: Komplette Neuentwicklung
//   - install() nutzt direkte mysqli-Verbindung statt xtc_db_query()
//   - Grund: modified's xtc_db_query() bricht bei INSERT mit set_function
//     still ab (kein Fehler, kein Log, aber Eintrag fehlt in der DB).
//     Betroffen: modified v2.0.7.2 rev 14622 mit PHP 8.3 auf Artfiles Hosting.
//   - check(), remove(), display() nutzen weiterhin xtc_db_query()
//   - Sprachdateien: lang/{sprache}/extra/admin/ (Auto-Include Hookpoint)
//   - Struktur: 1:1 nach uptain-connect Modul (bewaehrt auf mr-hanf.de)
//
// @see https://mr-hanf.de
// @see https://github.com/mrhanf15-stack/mrhanf-fpc-modul
//
defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

class mrhanf_fpc
{
    // Aktuelle Modulversion - hier zentral definiert
    private const VERSION = '5.0.0';

    public string $code        = 'mrhanf_fpc';
    public string $title       = '';
    public string $description = '';
    public int    $sort_order  = 0;
    public bool   $enabled     = false;

    public function __construct()
    {
        $this->title       = defined('MODULE_MRHANF_FPC_TITLE') ? constant('MODULE_MRHANF_FPC_TITLE') : 'Mr. Hanf Full Page Cache';
        $this->description = defined('MODULE_MRHANF_FPC_DESC') ? constant('MODULE_MRHANF_FPC_DESC') : 'Full Page Cache fuer Gaeste';
        $this->sort_order  = defined('MODULE_MRHANF_FPC_SORT_ORDER') ? (int) constant('MODULE_MRHANF_FPC_SORT_ORDER') : 0;
        $this->enabled     = (defined('MODULE_MRHANF_FPC_STATUS') && constant('MODULE_MRHANF_FPC_STATUS') === 'True');
    }

    public function process(): void
    {
        // Wird beim Speichern der Konfiguration aufgerufen.
    }

    public function check(): int
    {
        if (!function_exists('xtc_db_query')) {
            return 0;
        }
        $query = xtc_db_query("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_MRHANF_FPC_STATUS'");
        return (int) (xtc_db_num_rows($query) > 0);
    }

    public function keys(): array
    {
        return array(
            'MODULE_MRHANF_FPC_STATUS',
            'MODULE_MRHANF_FPC_CACHE_TIME',
            'MODULE_MRHANF_FPC_EXCLUDED_PAGES',
            'MODULE_MRHANF_FPC_SORT_ORDER',
        );
    }

    /**
     * Installation: Schreibt die Konfigurationseintraege direkt per mysqli.
     *
     * WARUM NICHT xtc_db_query()?
     * modified's xtc_db_query() Wrapper fuehrt ein zusaetzliches Escaping durch,
     * das bei set_function-Werten mit Single-Quotes (z.B. xtc_cfg_select_option)
     * zu einem stillen Fehler fuehrt: Der INSERT wird nicht ausgefuehrt, aber
     * kein Fehler geloggt. Nur Eintraege OHNE set_function werden geschrieben.
     * Dieses Verhalten wurde auf modified v2.0.7.2 rev 14622 mit PHP 8.3
     * auf Artfiles Shared Hosting reproduziert.
     *
     * Die direkte mysqli-Verbindung umgeht dieses Problem zuverlaessig.
     * Die DB-Zugangsdaten werden aus den modified-Konstanten gelesen.
     */
    public function install(): void
    {
        // DB-Verbindung direkt aufbauen ueber modified's Konstanten
        $db = null;
        if (defined('DB_SERVER') && defined('DB_SERVER_USERNAME') && defined('DB_SERVER_PASSWORD') && defined('DB_DATABASE')) {
            $db = @new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
            if ($db->connect_error) {
                $db = null;
            }
        }

        // Fallback: Wenn keine direkte Verbindung moeglich, versuche xtc_db_query
        if ($db === null) {
            $this->installViaXtc();
            return;
        }

        $cfg = array(
            array('MODULE_MRHANF_FPC_STATUS',         'True',  1, "xtc_cfg_select_option(array('True', 'False'),"),
            array('MODULE_MRHANF_FPC_CACHE_TIME',     '86400', 2, ''),
            array('MODULE_MRHANF_FPC_EXCLUDED_PAGES',  'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write', 3, ''),
            array('MODULE_MRHANF_FPC_SORT_ORDER',     '0',     4, ''),
        );

        $table = defined('TABLE_CONFIGURATION') ? TABLE_CONFIGURATION : 'configuration';

        foreach ($cfg as $c) {
            $key  = $db->real_escape_string($c[0]);
            $val  = $db->real_escape_string($c[1]);
            $sort = (int) $c[2];
            $func = $db->real_escape_string($c[3]);
            $sql = "INSERT INTO " . $table
                 . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added"
                 . ($func !== '' ? ", set_function" : "")
                 . ") VALUES ('"
                 . $key . "', '" . $val . "', 6, " . $sort . ", NOW()"
                 . ($func !== '' ? ", '" . $func . "'" : "")
                 . ")";
            $db->query($sql);
        }

        $db->close();

        // Cache-Verzeichnis anlegen
        $this->ensureCacheDir();
    }

    /**
     * Fallback-Installation ueber xtc_db_query() - fuer den Fall dass
     * die direkten DB-Konstanten nicht verfuegbar sind.
     */
    private function installViaXtc(): void
    {
        if (!function_exists('xtc_db_query')) {
            return;
        }
        $cfg = array(
            array('MODULE_MRHANF_FPC_STATUS',         'True',  1, "xtc_cfg_select_option(array('True', 'False'),"),
            array('MODULE_MRHANF_FPC_CACHE_TIME',     '86400', 2, ''),
            array('MODULE_MRHANF_FPC_EXCLUDED_PAGES',  'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write', 3, ''),
            array('MODULE_MRHANF_FPC_SORT_ORDER',     '0',     4, ''),
        );
        foreach ($cfg as $c) {
            $key  = xtc_db_input($c[0]);
            $val  = xtc_db_input($c[1]);
            $sort = (int) $c[2];
            $func = xtc_db_input($c[3]);
            $sql = "INSERT INTO " . TABLE_CONFIGURATION
                 . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added"
                 . ($func !== '' ? ", set_function" : "")
                 . ") VALUES ('"
                 . $key . "', '" . $val . "', 6, " . $sort . ", NOW()"
                 . ($func !== '' ? ", '" . $func . "'" : "")
                 . ")";
            xtc_db_query($sql);
        }
        $this->ensureCacheDir();
    }

    /**
     * Cache-Verzeichnis anlegen falls noetig.
     */
    private function ensureCacheDir(): void
    {
        if (defined('DIR_FS_DOCUMENT_ROOT')) {
            $cache_dir = DIR_FS_DOCUMENT_ROOT . 'cache/fpc/';
        } elseif (defined('DIR_FS_CATALOG')) {
            $cache_dir = DIR_FS_CATALOG . 'cache/fpc/';
        }
        if (isset($cache_dir) && !is_dir($cache_dir)) {
            @mkdir($cache_dir, 0755, true);
        }
    }

    public function remove(): void
    {
        if (!function_exists('xtc_db_query')) {
            return;
        }
        $keys = $this->keys();
        $in   = "'" . implode("', '", $keys) . "'";
        xtc_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key IN (" . $in . ")");

        // Cache-Verzeichnis leeren
        if (defined('DIR_FS_DOCUMENT_ROOT')) {
            $cache_dir = DIR_FS_DOCUMENT_ROOT . 'cache/fpc/';
        } elseif (defined('DIR_FS_CATALOG')) {
            $cache_dir = DIR_FS_CATALOG . 'cache/fpc/';
        }
        if (isset($cache_dir) && is_dir($cache_dir)) {
            $files = glob($cache_dir . '*.html');
            if (is_array($files)) {
                foreach ($files as $f) {
                    @unlink($f);
                }
            }
        }
    }

    public function display(): array
    {
        return array(
            'text' => (function_exists('xtc_button') ? xtc_button(BUTTON_SAVE) : '')
                    . ' '
                    . (function_exists('xtc_button_link')
                        ? xtc_button_link(BUTTON_CANCEL, xtc_href_link(FILENAME_MODULE_EXPORT, 'set=' . (isset($_GET['set']) ? $_GET['set'] : '') . '&module=' . $this->code))
                        : '')
        );
    }
}
