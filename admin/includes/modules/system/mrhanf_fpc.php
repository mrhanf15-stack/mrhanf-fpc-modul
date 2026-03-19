<?php

declare(strict_types=1);

/**
 * Mr. Hanf Full Page Cache — System Module
 *
 * @version     2.0.0
 * @php         8.3+
 * @author      Manus AI für Mr. Hanf (mr-hanf.de)
 * @copyright   2026 Mr. Hanf
 */

defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

final class mrhanf_fpc
{
    public readonly string $prefix;
    public readonly string $code;
    public string $title;
    public string $description;
    public int    $sort_order;
    public bool   $enabled;

    private const CACHE_DIR_RELATIVE = 'cache/fpc/';

    public function __construct()
    {
        $this->prefix      = 'MODULE_MRHANF_FPC';
        $this->code        = 'mrhanf_fpc';
        $this->title       = defined($this->prefix . '_TITLE')
            ? constant($this->prefix . '_TITLE')
            : 'Mr. Hanf Full Page Cache';
        $this->description = defined($this->prefix . '_DESC')
            ? constant($this->prefix . '_DESC')
            : 'Aktiviert den Full-Page-Cache für extrem schnelle Ladezeiten (PHP 8.3 optimiert).';
        $this->sort_order  = defined($this->prefix . '_SORT_ORDER')
            ? (int) constant($this->prefix . '_SORT_ORDER')
            : 0;
        $this->enabled     = defined($this->prefix . '_STATUS')
            && constant($this->prefix . '_STATUS') === 'true';
    }

    public function process(): void
    {
        // Kein Frontend-Prozess nötig — Logik läuft über Auto-Include Hooks
    }

    public function display(): array
    {
        return [
            'text' => '<br><div align="center">'
                . xtc_button('button_save')
                . ' '
                . xtc_button_link(
                    'button_cancel',
                    xtc_href_link(
                        FILENAME_MODULE_EXPORT,
                        'set=' . $_GET['set'] . '&module=' . $this->code
                    )
                )
                . '</div>',
        ];
    }

    public function check(): int
    {
        if (!isset($this->_check)) {
            $check_query  = xtc_db_query(
                "SELECT configuration_value FROM " . TABLE_CONFIGURATION
                . " WHERE configuration_key = '" . $this->prefix . "_STATUS'"
            );
            $this->_check = xtc_db_num_rows($check_query);
        }

        return (int) $this->_check;
    }

    public function install(): void
    {
        // STATUS mit Dropdown-Auswahl
        xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, date_added)"
            . " VALUES ('"
            . xtc_db_input($this->prefix . '_STATUS') . "', 'true', '6', '1',"
            . " 'xtc_cfg_select_option(array(\'true\', \'false\'), ', now())"
        );

        // CACHE_TIME
        xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added)"
            . " VALUES ('"
            . xtc_db_input($this->prefix . '_CACHE_TIME') . "', '86400', '6', '2', now())"
        );

        // EXCLUDED_PAGES
        xtc_db_query(
            "INSERT INTO " . TABLE_CONFIGURATION
            . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added)"
            . " VALUES ('"
            . xtc_db_input($this->prefix . '_EXCLUDED_PAGES')
            . "', 'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write',"
            . " '6', '3', now())"
        );

        // Cache-Verzeichnis anlegen
        $cache_dir = DIR_FS_DOCUMENT_ROOT . self::CACHE_DIR_RELATIVE;
        if (!is_dir($cache_dir)) {
            @mkdir($cache_dir, 0755, true);
        }
    }

    public function remove(): void
    {
        xtc_db_query(
            "DELETE FROM " . TABLE_CONFIGURATION
            . " WHERE configuration_key LIKE '" . $this->prefix . "_%'"
        );
    }

    public function keys(): array
    {
        return [
            $this->prefix . '_STATUS',
            $this->prefix . '_CACHE_TIME',
            $this->prefix . '_EXCLUDED_PAGES',
        ];
    }
}
