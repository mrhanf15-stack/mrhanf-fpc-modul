<?php
/**
 * Mr. Hanf Full Page Cache v8.0.8 - System-Modul fuer modified eCommerce
 *
 * Cron-basiertes Preloading-System:
 *   - Ein Cron-Job (fpc_preloader.php) ruft Shop-Seiten ab und speichert
 *     sie als statische HTML-Dateien unter cache/fpc/
 *   - Apache liefert gecachte Seiten DIREKT als statische Dateien aus
 *     (kein PHP-Worker noetig!)
 *   - Dieses Admin-Modul verwaltet Konfiguration, Cache-Status und Flush
 *
 * Kompatibel mit modified eCommerce v2.0.7.2 rev 14622
 *
 * CHANGELOG v8.0.8:
 *   - NEU: "Cache neu aufbauen" Button im Admin-Modul
 *     Loest fpc_preloader.php als Hintergrund-Prozess aus (nohup + &)
 *     damit der Admin-Browser nicht blockiert wird.
 *   - NEU: Status-Anzeige ob ein Rebuild gerade laeuft (PID-Datei)
 *   - NEU: Sprachkonstanten fuer alle 4 Sprachen (DE/EN/FR/ES)
 *
 * @version   8.0.8
 * @date      2026-03-27
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
        // Im Frontend darf dieses Modul NICHTS tun!
        // Der FPC wird komplett ueber .htaccess gesteuert (v8.0: ohne PHP).
        if (!$this->_isAdmin()) {
            $this->title       = 'Mr. Hanf Full Page Cache';
            $this->description = '';
            $this->sort_order  = 0;
            $this->enabled     = false;
            return;
        }

        // === Ab hier NUR im Admin-Bereich ===
        $this->title = defined('MODULE_MRHANF_FPC_TITLE')
                     ? MODULE_MRHANF_FPC_TITLE
                     : 'Mr. Hanf Full Page Cache';

        $this->description = defined('MODULE_MRHANF_FPC_DESC')
                           ? MODULE_MRHANF_FPC_DESC
                           : 'Cron-basiertes Preloading mit statischen HTML-Dateien.';

        $this->description .= $this->_getCacheStatusHtml();

        $this->sort_order = defined('MODULE_MRHANF_FPC_SORT_ORDER')
                          ? (int) MODULE_MRHANF_FPC_SORT_ORDER
                          : 0;

        $this->enabled = defined('MODULE_MRHANF_FPC_STATUS')
                      && MODULE_MRHANF_FPC_STATUS == 'True';
    }

    /**
     * Pruefen ob wir im Admin-Bereich sind
     */
    private function _isAdmin()
    {
        if (defined('IS_ADMIN_FILE') && IS_ADMIN_FILE === true) {
            return true;
        }
        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/admin') !== false) {
            return true;
        }
        if (defined('FILENAME_MODULE_EXPORT')) {
            return true;
        }
        return false;
    }

    /**
     * Cache-Status als HTML fuer die Modulbeschreibung
     */
    private function _getCacheStatusHtml()
    {
        $base = defined('DIR_FS_DOCUMENT_ROOT') ? DIR_FS_DOCUMENT_ROOT : (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '');
        $cache_dir = $base . 'cache/fpc/';
        $pid_file  = $cache_dir . 'rebuild.pid';

        if (!is_dir($cache_dir)) {
            return '<br /><br /><b>Cache-Status:</b> Verzeichnis nicht vorhanden';
        }

        // ============================================================
        // AKTIONEN VERARBEITEN
        // ============================================================

        $action_msg = '';

        // --- Aktion: Cache leeren ---
        if (isset($_GET['fpc_action']) && $_GET['fpc_action'] === 'flush') {
            $this->_flushCache($cache_dir);
            $action_msg = '<br /><div style="background:#d4edda; border:1px solid #c3e6cb; padding:10px; color:#155724; border-radius:4px; margin-top:8px;">'
                        . (defined('MODULE_MRHANF_FPC_FLUSH_SUCCESS') ? MODULE_MRHANF_FPC_FLUSH_SUCCESS : 'Cache wurde erfolgreich geleert!')
                        . '</div>';
        }

        // --- Aktion: Cache neu aufbauen ---
        if (isset($_GET['fpc_action']) && $_GET['fpc_action'] === 'rebuild') {
            $rebuild_result = $this->_triggerRebuild($base, $cache_dir, $pid_file);
            if ($rebuild_result === true) {
                $action_msg = '<br /><div style="background:#d4edda; border:1px solid #c3e6cb; padding:10px; color:#155724; border-radius:4px; margin-top:8px;">'
                            . '<b>&#10003;</b> '
                            . (defined('MODULE_MRHANF_FPC_REBUILD_STARTED') ? MODULE_MRHANF_FPC_REBUILD_STARTED : 'Cache-Rebuild wurde gestartet! Der Preloader laeuft im Hintergrund.')
                            . '</div>';
            } else {
                $action_msg = '<br /><div style="background:#f8d7da; border:1px solid #f5c6cb; padding:10px; color:#721c24; border-radius:4px; margin-top:8px;">'
                            . '<b>&#10007;</b> ' . $rebuild_result
                            . '</div>';
            }
        }

        // --- Aktion: Laufenden Rebuild stoppen ---
        if (isset($_GET['fpc_action']) && $_GET['fpc_action'] === 'stop_rebuild') {
            $this->_stopRebuild($pid_file);
            $action_msg = '<br /><div style="background:#fff3cd; border:1px solid #ffc107; padding:10px; color:#856404; border-radius:4px; margin-top:8px;">'
                        . (defined('MODULE_MRHANF_FPC_REBUILD_STOPPED') ? MODULE_MRHANF_FPC_REBUILD_STOPPED : 'Rebuild-Prozess wurde gestoppt.')
                        . '</div>';
        }

        // ============================================================
        // STATUS-INFORMATIONEN SAMMELN
        // ============================================================

        // Dateien zaehlen
        $files = $this->_countCacheFiles($cache_dir);
        $size  = $this->_getCacheDirSize($cache_dir);

        // Letzter Preloader-Lauf
        $log_file = $cache_dir . 'preloader.log';
        $last_run = defined('MODULE_MRHANF_FPC_NEVER') ? MODULE_MRHANF_FPC_NEVER : 'Noch nie';
        if (is_file($log_file)) {
            $last_lines = $this->_tailFile($log_file, 5);
            if (preg_match('/\[FPC\] Fertig: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $last_lines, $m)) {
                $last_run = $m[1];
            }
            if (preg_match('/Gecacht: (\d+) \| Uebersprungen: (\d+).*Fehler: (\d+)/', $last_lines, $m2)) {
                $last_run .= ' (Neu: ' . $m2[1] . ', Uebersprungen: ' . $m2[2] . ', Fehler: ' . $m2[3] . ')';
            }
        }

        // Rebuild-Status pruefen
        $rebuild_running = $this->_isRebuildRunning($pid_file);

        // ============================================================
        // HTML AUSGABE
        // ============================================================

        $html  = '<br /><br />';
        $html .= '<table border="0" cellpadding="4" cellspacing="0" style="background:#f8f8f8; border:1px solid #ccc; margin-top:8px; width:100%;">';
        $html .= '<tr><td colspan="2" style="background:#4a90d9; color:#fff; font-weight:bold; padding:8px;">Cache-Status (v8.0.8 - Direkte Apache-Auslieferung)</td></tr>';
        $html .= '<tr><td style="width:180px;"><b>' . (defined('MODULE_MRHANF_FPC_CACHED_PAGES') ? MODULE_MRHANF_FPC_CACHED_PAGES : 'Gecachte Seiten:') . '</b></td><td>' . $files . '</td></tr>';
        $html .= '<tr><td><b>' . (defined('MODULE_MRHANF_FPC_CACHE_SIZE') ? MODULE_MRHANF_FPC_CACHE_SIZE : 'Cache-Groesse:') . '</b></td><td>' . $this->_formatBytes($size) . '</td></tr>';
        $html .= '<tr><td><b>' . (defined('MODULE_MRHANF_FPC_LAST_RUN') ? MODULE_MRHANF_FPC_LAST_RUN : 'Letzter Cron-Lauf:') . '</b></td><td>' . $last_run . '</td></tr>';
        $html .= '<tr><td><b>Cache-Verzeichnis:</b></td><td><code>cache/fpc/</code></td></tr>';
        $html .= '<tr><td><b>Auslieferung:</b></td><td>Apache direkt (kein PHP-Worker)</td></tr>';

        // Rebuild-Status Zeile
        if ($rebuild_running) {
            $html .= '<tr><td><b>' . (defined('MODULE_MRHANF_FPC_REBUILD_STATUS') ? MODULE_MRHANF_FPC_REBUILD_STATUS : 'Rebuild-Status:') . '</b></td>'
                   . '<td><span style="color:#28a745; font-weight:bold;">&#9679; '
                   . (defined('MODULE_MRHANF_FPC_REBUILD_RUNNING') ? MODULE_MRHANF_FPC_REBUILD_RUNNING : 'Preloader laeuft...')
                   . '</span></td></tr>';
        }

        $html .= '</table>';

        // Aktions-Meldung
        $html .= $action_msg;

        // ============================================================
        // BUTTONS
        // ============================================================

        $html .= '<br /><div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">';

        // --- Button: Cache neu aufbauen ---
        if ($rebuild_running) {
            // Wenn Rebuild laeuft: Stop-Button anzeigen
            $stop_url = 'module_export.php?module=mrhanf_fpc&set=system&fpc_action=stop_rebuild';
            $html .= '<a href="' . $stop_url . '" onclick="return confirm(\''
                   . (defined('MODULE_MRHANF_FPC_STOP_CONFIRM') ? MODULE_MRHANF_FPC_STOP_CONFIRM : 'Laufenden Rebuild wirklich stoppen?')
                   . '\');" '
                   . 'style="display:inline-block; padding:8px 20px; background:#ffc107; color:#212529; text-decoration:none; border-radius:4px; font-weight:bold; font-size:13px;">'
                   . '&#9632; ' . (defined('MODULE_MRHANF_FPC_BTN_STOP') ? MODULE_MRHANF_FPC_BTN_STOP : 'Rebuild stoppen')
                   . '</a>';
        } else {
            // Normaler Rebuild-Button
            $rebuild_url = 'module_export.php?module=mrhanf_fpc&set=system&fpc_action=rebuild';
            $html .= '<a href="' . $rebuild_url . '" onclick="return confirm(\''
                   . (defined('MODULE_MRHANF_FPC_REBUILD_CONFIRM') ? MODULE_MRHANF_FPC_REBUILD_CONFIRM : 'Cache jetzt neu aufbauen? Der Preloader wird im Hintergrund gestartet.')
                   . '\');" '
                   . 'style="display:inline-block; padding:8px 20px; background:#28a745; color:#fff; text-decoration:none; border-radius:4px; font-weight:bold; font-size:13px;">'
                   . '&#8635; ' . (defined('MODULE_MRHANF_FPC_BTN_REBUILD') ? MODULE_MRHANF_FPC_BTN_REBUILD : 'Cache neu aufbauen')
                   . '</a>';
        }

        // --- Button: Cache leeren ---
        $flush_url = 'module_export.php?module=mrhanf_fpc&set=system&fpc_action=flush';
        $html .= '<a href="' . $flush_url . '" onclick="return confirm(\''
               . (defined('MODULE_MRHANF_FPC_FLUSH_CONFIRM') ? MODULE_MRHANF_FPC_FLUSH_CONFIRM : 'Cache wirklich leeren?')
               . '\');" '
               . 'style="display:inline-block; padding:8px 20px; background:#dc3545; color:#fff; text-decoration:none; border-radius:4px; font-weight:bold; font-size:13px;">'
               . '&#128465; ' . (defined('MODULE_MRHANF_FPC_BTN_FLUSH') ? MODULE_MRHANF_FPC_BTN_FLUSH : 'Cache leeren')
               . '</a>';

        $html .= '</div>';

        return $html;
    }

    // ============================================================
    // REBUILD-FUNKTIONEN (NEU v8.0.8)
    // ============================================================

    /**
     * Preloader als Hintergrund-Prozess starten.
     * Verwendet nohup + & damit der Admin-Browser nicht blockiert wird.
     * Schreibt eine PID-Datei fuer Status-Tracking.
     *
     * @param string $base      Shop-Root-Verzeichnis
     * @param string $cache_dir Cache-Verzeichnis
     * @param string $pid_file  Pfad zur PID-Datei
     * @return true|string      true bei Erfolg, Fehlertext bei Fehler
     */
    private function _triggerRebuild($base, $cache_dir, $pid_file)
    {
        // Pruefen ob Preloader existiert
        $preloader = $base . 'fpc_preloader.php';
        if (!is_file($preloader)) {
            return defined('MODULE_MRHANF_FPC_ERR_NO_PRELOADER')
                 ? MODULE_MRHANF_FPC_ERR_NO_PRELOADER
                 : 'Fehler: fpc_preloader.php nicht gefunden in ' . $base;
        }

        // Pruefen ob bereits ein Rebuild laeuft
        if ($this->_isRebuildRunning($pid_file)) {
            return defined('MODULE_MRHANF_FPC_ERR_ALREADY_RUNNING')
                 ? MODULE_MRHANF_FPC_ERR_ALREADY_RUNNING
                 : 'Ein Rebuild laeuft bereits! Bitte warten Sie bis der aktuelle Durchlauf abgeschlossen ist.';
        }

        // PHP-Binary ermitteln
        $php_bin = $this->_findPhpBinary();

        // Log-Datei fuer diesen manuellen Lauf
        $rebuild_log = $cache_dir . 'rebuild_manual.log';

        // Preloader im Hintergrund starten
        // cd zum Shop-Root (wichtig fuer includes/configure.php)
        // nohup + & = Hintergrund-Prozess der auch nach Browser-Schliessung weiterlaeuft
        $cmd = sprintf(
            'cd %s && nohup %s %s >> %s 2>&1 & echo $!',
            escapeshellarg(rtrim($base, '/')),
            escapeshellarg($php_bin),
            escapeshellarg($preloader),
            escapeshellarg($rebuild_log)
        );

        $pid = trim(shell_exec($cmd));

        if (!empty($pid) && is_numeric($pid)) {
            // PID-Datei schreiben fuer Status-Tracking
            file_put_contents($pid_file, $pid . "\n" . date('Y-m-d H:i:s'));
            return true;
        }

        return defined('MODULE_MRHANF_FPC_ERR_START_FAILED')
             ? MODULE_MRHANF_FPC_ERR_START_FAILED
             : 'Fehler: Konnte den Preloader-Prozess nicht starten. Bitte Serverrechte pruefen.';
    }

    /**
     * Pruefen ob ein Rebuild-Prozess laeuft (PID-Datei + Prozess-Check)
     */
    private function _isRebuildRunning($pid_file)
    {
        if (!is_file($pid_file)) {
            return false;
        }

        $content = file_get_contents($pid_file);
        $lines = explode("\n", trim($content));
        $pid = (int) $lines[0];

        if ($pid <= 0) {
            @unlink($pid_file);
            return false;
        }

        // Pruefen ob der Prozess noch existiert
        if (function_exists('posix_kill')) {
            // Signal 0 = nur pruefen, nicht toeten
            if (posix_kill($pid, 0)) {
                return true;
            }
        } else {
            // Fallback: /proc Filesystem pruefen
            if (is_dir('/proc/' . $pid)) {
                return true;
            }
        }

        // Prozess existiert nicht mehr - PID-Datei aufraeumen
        @unlink($pid_file);
        return false;
    }

    /**
     * Laufenden Rebuild-Prozess stoppen
     */
    private function _stopRebuild($pid_file)
    {
        if (!is_file($pid_file)) {
            return;
        }

        $content = file_get_contents($pid_file);
        $lines = explode("\n", trim($content));
        $pid = (int) $lines[0];

        if ($pid > 0) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, 15); // SIGTERM
            } else {
                @exec('kill ' . (int) $pid . ' 2>/dev/null');
            }
        }

        @unlink($pid_file);
    }

    /**
     * PHP-Binary finden (verschiedene Hosting-Umgebungen)
     */
    private function _findPhpBinary()
    {
        // Haeufige Pfade auf Shared Hosting (Artfiles etc.)
        $candidates = array(
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/usr/bin/php8.1',
            '/usr/bin/php8.2',
            '/usr/bin/php8.0',
            '/usr/bin/php7.4',
            PHP_BINDIR . '/php',
        );

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        // Fallback: php im PATH
        return 'php';
    }

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

    private function _tailFile($file, $lines = 5)
    {
        $data = file_get_contents($file);
        if ($data === false) return '';
        $arr = explode("\n", trim($data));
        return implode("\n", array_slice($arr, -$lines));
    }

    private function _formatBytes($bytes)
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' Bytes';
    }

    /**
     * v8.0.5: Cache leeren OHNE das Verzeichnis zu loeschen.
     * Loescht alle HTML-Dateien und Unterverzeichnisse,
     * behaelt aber cache/fpc/ selbst, .gitkeep und preloader.log.
     * Erstellt das Verzeichnis neu falls es fehlt.
     */
    private function _flushCache($dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            return;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } elseif ($item->getFilename() !== '.gitkeep'
                   && $item->getFilename() !== 'preloader.log'
                   && $item->getFilename() !== 'rebuild.pid'
                   && $item->getFilename() !== 'rebuild_manual.log') {
                @unlink($item->getRealPath());
            }
        }
        // v8.0.5: Sicherstellen dass Verzeichnis + .gitkeep existieren
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_file($dir . '.gitkeep')) {
            @file_put_contents($dir . '.gitkeep', '');
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
                'value' => 'checkout,login,account,shopping_cart,logoff,admin,password_double_opt,create_account,contact_us,tell_a_friend,product_reviews_write,vergleich,wishlist',
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

    /**
     * Einstellungen speichern - WICHTIG: Ohne diese Methode gibt es eine weisse Seite!
     */
    public function process($file)
    {
        // Wird von modified eCommerce beim Speichern aufgerufen
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
