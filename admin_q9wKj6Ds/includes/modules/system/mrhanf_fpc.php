<?php
/**
 * Mr. Hanf Full Page Cache v6.0.0 - System-Modul fuer modified eCommerce
 *
 * Cron-basiertes Preloading-System:
 *   - Ein Cron-Job (fpc_preloader.php) ruft Shop-Seiten ab und speichert
 *     sie als statische HTML-Dateien unter cache/fpc/
 *   - Die .htaccess prueft ob eine statische Version existiert und liefert
 *     sie direkt aus (TTFB < 0.1s)
 *   - Dieses Admin-Modul verwaltet Konfiguration, Cache-Status und Flush
 *
 * Kompatibel mit modified eCommerce v2.0.7.2 rev 14622
 */
defined('_VALID_XTC') or die('Direct Access to this location is not allowed.');

class mrhanf_fpc
{
    public $code        = 'mrhanf_fpc';
    public $title       = '';
    public $description = '';
    public $sort_order  = 0;
    public $enabled     = false;
    public $_check      = 0;

    public function __construct()
    {
        $this->title = defined('MODULE_MRHANF_FPC_TITLE')
                     ? MODULE_MRHANF_FPC_TITLE
                     : 'Mr. Hanf Full Page Cache';

        $this->description = defined('MODULE_MRHANF_FPC_DESC')
                           ? MODULE_MRHANF_FPC_DESC
                           : 'Cron-basiertes Preloading mit statischen HTML-Dateien.';

        // Cache-Status-Info an Beschreibung anhaengen
        $this->description .= $this->_getCacheStatusHtml();

        $this->sort_order = defined('MODULE_MRHANF_FPC_SORT_ORDER')
                          ? (int) MODULE_MRHANF_FPC_SORT_ORDER
                          : 0;

        $this->enabled = defined('MODULE_MRHANF_FPC_STATUS')
                      && MODULE_MRHANF_FPC_STATUS == 'True';
    }

    /**
     * Cache-Status als HTML fuer die Modulbeschreibung
     */
    private function _getCacheStatusHtml()
    {
        $base = defined('DIR_FS_DOCUMENT_ROOT') ? DIR_FS_DOCUMENT_ROOT : (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '');
        $cache_dir = $base . 'cache/fpc/';

        if (!is_dir($cache_dir)) {
            return '<br /><br /><b>Cache-Status:</b> Verzeichnis nicht vorhanden';
        }

        // Dateien zaehlen
        $files = $this->_countCacheFiles($cache_dir);
        $size  = $this->_getCacheDirSize($cache_dir);

        // Letzter Preloader-Lauf
        $log_file = $cache_dir . 'preloader.log';
        $last_run = 'Noch nie';
        if (is_file($log_file)) {
            $last_lines = $this->_tailFile($log_file, 5);
            if (preg_match('/\[FPC\] Fertig: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $last_lines, $m)) {
                $last_run = $m[1];
            }
            // Letzte Statistik
            if (preg_match('/\[FPC\] Gecacht: (\d+) \| Uebersprungen: (\d+) \| Fehler: (\d+)/', $last_lines, $m2)) {
                $last_run .= ' (Neu: ' . $m2[1] . ', Uebersprungen: ' . $m2[2] . ', Fehler: ' . $m2[3] . ')';
            }
        }

        $html  = '<br /><br />';
        $html .= '<table border="0" cellpadding="4" cellspacing="0" style="background:#f8f8f8; border:1px solid #ccc; margin-top:8px;">';
        $html .= '<tr><td colspan="2" style="background:#4a90d9; color:#fff; font-weight:bold; padding:6px;">Cache-Status (v6.0.0)</td></tr>';
        $html .= '<tr><td><b>Gecachte Seiten:</b></td><td>' . $files . '</td></tr>';
        $html .= '<tr><td><b>Cache-Groesse:</b></td><td>' . $this->_formatBytes($size) . '</td></tr>';
        $html .= '<tr><td><b>Letzter Cron-Lauf:</b></td><td>' . $last_run . '</td></tr>';
        $html .= '<tr><td><b>Cache-Verzeichnis:</b></td><td><code>cache/fpc/</code></td></tr>';
        $html .= '</table>';

        // Flush-Button (per GET-Parameter)
        if (isset($_GET['fpc_action']) && $_GET['fpc_action'] === 'flush') {
            $this->_flushCache($cache_dir);
            $html .= '<br /><div style="background:#d4edda; border:1px solid #c3e6cb; padding:8px; color:#155724;">Cache wurde erfolgreich geleert!</div>';
        }

        $flush_url = 'module_export.php?module=mrhanf_fpc&set=system&fpc_action=flush';
        $html .= '<br /><a href="' . $flush_url . '" onclick="return confirm(\'Cache wirklich leeren?\');" '
               . 'style="display:inline-block; padding:6px 16px; background:#dc3545; color:#fff; text-decoration:none; border-radius:3px;">'
               . 'Cache leeren</a>';

        return $html;
    }

    /**
     * Alle HTML-Dateien im Cache-Verzeichnis zaehlen
     */
    private function _countCacheFiles($dir)
    {
        $count = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if ($file->isFile() && $file->getExtension() === 'html') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Gesamtgroesse des Cache-Verzeichnisses
     */
    private function _getCacheDirSize($dir)
    {
        $size = 0;
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    /**
     * Letzte N Zeilen einer Datei lesen
     */
    private function _tailFile($file, $lines = 5)
    {
        $data = file_get_contents($file);
        if ($data === false) return '';
        $arr = explode("\n", trim($data));
        return implode("\n", array_slice($arr, -$lines));
    }

    /**
     * Bytes formatieren
     */
    private function _formatBytes($bytes)
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' Bytes';
    }

    /**
     * Cache leeren
     */
    private function _flushCache($dir)
    {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } elseif ($item->getFilename() !== '.gitkeep' && $item->getFilename() !== 'preloader.log') {
                @unlink($item->getRealPath());
            }
        }
    }

    public function check()
    {
        if ($this->_check == 0) {
            $check_query = xtc_db_query(
                "SELECT configuration_value
                   FROM " . TABLE_CONFIGURATION . "
                  WHERE configuration_key = 'MODULE_MRHANF_FPC_STATUS'"
            );
            $this->_check = xtc_db_num_rows($check_query);
        }
        return $this->_check;
    }

    public function install()
    {
        $cfg = array(
            array(
                'key'   => 'MODULE_MRHANF_FPC_STATUS',
                'value' => 'True',
                'sort'  => 1,
                'func'  => "xtc_cfg_select_option(array('True', 'False'),"
            ),
            array(
                'key'   => 'MODULE_MRHANF_FPC_CACHE_TIME',
                'value' => '86400',
                'sort'  => 2,
                'func'  => ''
            ),
            array(
                'key'   => 'MODULE_MRHANF_FPC_EXCLUDED_PAGES',
                'value' => 'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write',
                'sort'  => 3,
                'func'  => ''
            ),
            array(
                'key'   => 'MODULE_MRHANF_FPC_PRELOAD_LIMIT',
                'value' => '500',
                'sort'  => 4,
                'func'  => ''
            ),
            array(
                'key'   => 'MODULE_MRHANF_FPC_SORT_ORDER',
                'value' => '0',
                'sort'  => 5,
                'func'  => ''
            ),
        );

        foreach ($cfg as $c) {
            $sql = "INSERT INTO " . TABLE_CONFIGURATION
                 . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added"
                 . ($c['func'] !== '' ? ", set_function" : "")
                 . ") VALUES ("
                 . "'" . xtc_db_input($c['key']) . "', "
                 . "'" . xtc_db_input($c['value']) . "', "
                 . "'6', "
                 . "'" . (int) $c['sort'] . "', "
                 . "NOW()"
                 . ($c['func'] !== '' ? ", '" . xtc_db_input($c['func']) . "'" : "")
                 . ")";
            xtc_db_query($sql);
        }

        // Fallback: direkte mysqli-Verbindung
        $check = xtc_db_query(
            "SELECT configuration_key FROM " . TABLE_CONFIGURATION
          . " WHERE configuration_key = 'MODULE_MRHANF_FPC_STATUS'"
        );
        if (xtc_db_num_rows($check) == 0) {
            $db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
            if (!$db->connect_error) {
                foreach ($cfg as $c) {
                    $key  = $db->real_escape_string($c['key']);
                    $val  = $db->real_escape_string($c['value']);
                    $sort = (int) $c['sort'];
                    $func = $db->real_escape_string($c['func']);
                    $sql  = "INSERT INTO configuration"
                          . " (configuration_key, configuration_value, configuration_group_id, sort_order, date_added"
                          . ($func !== '' ? ", set_function" : "")
                          . ") VALUES ('" . $key . "', '" . $val . "', 6, " . $sort . ", NOW()"
                          . ($func !== '' ? ", '" . $func . "'" : "")
                          . ")";
                    $db->query($sql);
                }
                $db->close();
            }
        }

        // Cache-Verzeichnis erstellen
        $base = defined('DIR_FS_DOCUMENT_ROOT') ? DIR_FS_DOCUMENT_ROOT : (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '');
        $cache_dir = $base . 'cache/fpc/';
        if (!is_dir($cache_dir)) {
            @mkdir($cache_dir, 0777, true);
        }

        // .gitkeep erstellen
        if (!is_file($cache_dir . '.gitkeep')) {
            @file_put_contents($cache_dir . '.gitkeep', '');
        }
    }

    public function remove()
    {
        xtc_db_query(
            "DELETE FROM " . TABLE_CONFIGURATION
          . " WHERE configuration_key LIKE 'MODULE_MRHANF_FPC_%'"
        );

        // Optional: Cache-Verzeichnis leeren (nicht loeschen)
        $base = defined('DIR_FS_DOCUMENT_ROOT') ? DIR_FS_DOCUMENT_ROOT : (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '');
        $cache_dir = $base . 'cache/fpc/';
        if (is_dir($cache_dir)) {
            $this->_flushCache($cache_dir);
        }
    }

    public function keys()
    {
        return array(
            'MODULE_MRHANF_FPC_STATUS',
            'MODULE_MRHANF_FPC_CACHE_TIME',
            'MODULE_MRHANF_FPC_EXCLUDED_PAGES',
            'MODULE_MRHANF_FPC_PRELOAD_LIMIT',
            'MODULE_MRHANF_FPC_SORT_ORDER',
        );
    }

    public function display()
    {
        return array(
            'text' => '<br />'
                    . xtc_button(BUTTON_SAVE)
                    . '&nbsp;&nbsp;'
                    . xtc_button_link(
                          xtc_href_link(FILENAME_MODULE_EXPORT, 'set=system'),
                          BUTTON_CANCEL
                      )
        );
    }
}
