<?php
/**
 * mrhanf_fpc System Module
 * 
 * @author Manus AI
 * @copyright 2026 Mr. Hanf
 */

defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

class mrhanf_fpc {
    public $prefix;
    public $code;
    public $title;
    public $description;
    public $enabled;
    public $sort_order;

    public function __construct() {
        $this->prefix = 'MODULE_MRHANF_FPC';
        $this->code = 'mrhanf_fpc';
        $this->title = defined($this->prefix . '_TITLE') ? constant($this->prefix . '_TITLE') : 'Mr. Hanf Full Page Cache';
        $this->description = defined($this->prefix . '_DESC') ? constant($this->prefix . '_DESC') : 'Aktiviert den Full-Page-Cache für extrem schnelle Ladezeiten.';
        $this->sort_order = defined($this->prefix . '_SORT_ORDER') ? constant($this->prefix . '_SORT_ORDER') : 0;
        $this->enabled = defined($this->prefix . '_STATUS') && constant($this->prefix . '_STATUS') == 'true';
    }

    public function process() {
        // Nichts zu tun im Frontend-Prozess
    }

    public function display() {
        return ['text' => '<br><div align="center">' . xtc_button('button_save') . ' ' . xtc_button_link('button_cancel', xtc_href_link(FILENAME_MODULE_EXPORT, 'set=' . $_GET['set'] . '&module=' . $this->code)) . '</div>'];
    }

    public function check() {
        if (!isset($this->_check)) {
            $check_query = xtc_db_query("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . $this->prefix . "_STATUS'");
            $this->_check = xtc_db_num_rows($check_query);
        }
        return $this->_check;
    }

    public function install() {
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, set_function, date_added) VALUES ('" . $this->prefix . "_STATUS', 'true', '6', '1', 'xtc_cfg_select_option(array(\'true\', \'false\'), ', now())");
        xtc_db_query("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added) VALUES ('" . $this->prefix . "_CACHE_TIME', '86400', '6', '2', now())");
        
        // Cache Ordner erstellen falls nicht vorhanden
        $cache_dir = DIR_FS_DOCUMENT_ROOT . 'cache/fpc/';
        if (!is_dir($cache_dir)) {
            @mkdir($cache_dir, 0777, true);
        }
    }

    public function remove() {
        xtc_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key LIKE '" . $this->prefix . "_%'");
    }

    public function keys() {
        return [
            $this->prefix . '_STATUS',
            $this->prefix . '_CACHE_TIME'
        ];
    }
}
?>
