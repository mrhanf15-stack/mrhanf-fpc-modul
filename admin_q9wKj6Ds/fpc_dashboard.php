<?php
/**
 * Mr. Hanf FPC Control Center v10.1.0
 *
 * Enterprise-Level Dashboard for the Full Page Cache System.
 *
 * Tabs:
 *   1.  Dashboard    - System traffic light, KPIs, Quick-Actions
 *   2.  Performance  - Hit/Miss, TTFB, Load times, Requests/min
 *   3.  Coverage     - Sitemap vs Cache, Top uncached, Pagination-Debug
 *   4.  Control      - Cache flush/rebuild, URL caching, Live progress
 *   5.  URLs         - Browse cached URLs, filter, Pagination-Fix
 *   6.  Preloader    - Progress, Queue, Speed, Hot-Categories
 *   7.  Errors       - Top Error-URLs, Miss-Reasons, Slowest Pages
 *   8.  SEO          - Bot-Requests, Bot Hit Rate, noindex, Coverage
 *   9.  Inspector    - Live Request Inspector, Header Debug, Session Leakage
 *   10. Health       - Score, SSL, htaccess, Layer overview
 *   11. Statistics   - Visitors, Bounce Rate, Duration, Devices
 *   12. Alerts       - Thresholds, Notifications, History
 *   13. Settings     - All FPC configuration in one place
 *   14. GSC          - Google Search Console: Indexing, Clicks, Queries
 *   15. Analytics    - Google Analytics 4: Traffic, Devices, Sources
 *   16. SISTRIX      - Visibility Index, Rankings, Competitors
 *
 * v9.1.1 NEW:
 *   - FIX: Preloader reads settings from fpc_settings.json -> preloader key
 *   - FIX: Settings save writes preloader config under 'preloader' key
 *   - FIX: Default max_runtime_sec changed from 2700 (45min) to 7200 (2h)
 *   - FIX: All preloader limits now configurable via Settings tab
 *
 * v9.1.0 NEW:
 *   - NEW: Remote Management API (fpc_api.php) with token auth
 *   - NEW: Google Search Console tab (Tab 14) - Indexing, Clicks, Queries
 *   - NEW: Google Analytics 4 tab (Tab 15) - Traffic, Devices, Sources
 *   - NEW: SISTRIX tab (Tab 16) - Visibility, Rankings, Competitors
 *   - NEW: API credentials config in Settings tab
 *
 * v9.0.8 FIXES:
 *   - FIX: Health Check tab now renders actual healthcheck.json structure
 *     (latest.results[] array instead of non-existent latest.checks object)
 *   - NEW: Health Check shows SSL info, KPI summary, sortable results table
 *   - FIX: Correct field names (total_cached, errors, hit_rate, avg_ttfb)
 *
 * v9.0.7 FIXES:
 *   - CRITICAL FIX: JavaScript syntax errors in health-check section
 *     that prevented ALL tabs from loading (broken string concatenation,
 *     merged statements on line 1920)
 *
 * v9.0.6 FIXES:
 *   - NEW: Settings tab (Tab 13) with all FPC configuration options
 *   - FIX: Progress bar now matches actual preloader log format
 *   - FIX: Speed display (URLs/sec) in preloader progress
 *   - FIX: All UI text translated to English
 *
 * v9.0.5 FIXES:
 *   - FIX: Request-Log reads daily files from cache/fpc/logs/requests_*.log
 *   - FIX: Sitemap parser uses /sitemap.xml with sitemapindex support
 *   - FIX: Statistics tab reads .json tracker files (not .jsonl)
 *   - FIX: User-Agent changed to real Chrome browser (403 fix)
 *   - FIX: All UI text in English
 * @version   9.1.4
 * @date      2026-03-27
 */

define('_VALID_XTC', true);
$current_page = 'fpc_dashboard.php';
require('includes/application_top.php');

// ============================================================
// KONFIGURATION
// ============================================================
$base_dir       = defined('DIR_FS_DOCUMENT_ROOT') ? DIR_FS_DOCUMENT_ROOT : (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '');
$cache_dir      = $base_dir . 'cache/fpc/';
$config_dir     = $base_dir . 'api/fpc/';
if (!is_dir($config_dir)) @mkdir($config_dir, 0755, true);
$pid_file       = $cache_dir . 'rebuild.pid';
$log_file       = $cache_dir . 'preloader.log';
$rebuild_log    = $cache_dir . 'rebuild_manual.log';
$monitor_log    = $cache_dir . 'monitor.json';
$custom_urls_file = $cache_dir . 'custom_urls.txt';
$healthcheck_file = $cache_dir . 'healthcheck.json';
$tracker_dir    = $cache_dir . 'tracker/';
$log_dir        = $cache_dir . 'logs/';
$request_log    = $log_dir;  // v9.0.5: Directory, not single file - reads daily files
$alerts_config  = $cache_dir . 'alerts_config.json';
$alerts_log     = $cache_dir . 'alerts_history.json';
$shop_url       = 'https://mr-hanf.de';

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$allowed_tabs = array('dashboard','performance','coverage','steuerung','urls','preloader','fehler','seo','inspector','health','statistik','alerts','settings','gsc','analytics','sistrix');
if (!in_array($active_tab, $allowed_tabs)) $active_tab = 'dashboard';

// ============================================================
// AJAX-ENDPUNKTE
// ============================================================
if (isset($_GET['ajax'])) {
    // v10.2.2: Error Suppression fuer saubere JSON-Antworten
    error_reporting(0);
    ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');

    /**
     * v10.2.2: Sichere JSON-Ausgabe Funktion
     * Faengt PHP-Warnungen ab und gibt immer sauberes JSON zurueck
     */
    function fpc_json_exit($data) {
        // Alles was vorher (versehentlich) ausgegeben wurde, verwerfen
        if (ob_get_level() > 0) ob_end_clean();
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Output Buffer starten um PHP-Warnungen/Notices abzufangen
    ob_start();

    switch ($_GET['ajax']) {

        case 'status':
            echo json_encode(fpc_get_status($cache_dir, $pid_file, $log_file, $healthcheck_file, $request_log));
            exit;

        case 'log':
            $type = isset($_GET['type']) ? $_GET['type'] : 'preloader';
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
            $allowed_logs = array('preloader' => $log_file, 'rebuild' => $rebuild_log, 'healthcheck' => $cache_dir . 'healthcheck_cron.log');
            $file = isset($allowed_logs[$type]) ? $allowed_logs[$type] : $log_file;
            echo json_encode(array('content' => fpc_tail_file($file, $lines), 'file' => basename($file)));
            exit;

        case 'urls':
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            echo json_encode(fpc_list_cached_urls($cache_dir, $search, $page, 50));
            exit;

        case 'cache_url':
            $url = isset($_POST['url']) ? trim($_POST['url']) : '';
            if (empty($url)) { echo json_encode(array('ok' => false, 'msg' => 'No URL provided')); exit; }
            echo json_encode(fpc_cache_single_url($url, $cache_dir, $base_dir));
            exit;

        case 'remove_url':
            $path = isset($_POST['path']) ? trim($_POST['path']) : '';
            if (empty($path)) { echo json_encode(array('ok' => false, 'msg' => 'No path provided')); exit; }
            echo json_encode(fpc_remove_cached_url($cache_dir, $path));
            exit;

        case 'recache_url':
            $path = isset($_POST['path']) ? trim($_POST['path']) : '';
            if (empty($path)) { echo json_encode(array('ok' => false, 'msg' => 'No path provided')); exit; }
            fpc_remove_cached_url($cache_dir, $path);
            echo json_encode(fpc_cache_single_url($shop_url . $path, $cache_dir, $base_dir));
            exit;

        case 'flush':
            fpc_flush_cache($cache_dir);
            echo json_encode(array('ok' => true, 'msg' => 'Cache flushed successfully'));
            exit;

        case 'rebuild':
            echo json_encode(fpc_trigger_rebuild($base_dir, $cache_dir, $pid_file));
            exit;

        case 'stop':
            fpc_stop_rebuild($pid_file);
            echo json_encode(array('ok' => true, 'msg' => 'Rebuild stopped'));
            exit;

        case 'rebuild_progress':
            echo json_encode(fpc_get_rebuild_progress($cache_dir, $pid_file, $rebuild_log));
            exit;

        case 'monitor_data':
            echo json_encode(fpc_get_monitor_data($monitor_log));
            exit;

        case 'run_monitor':
            $count = isset($_POST['count']) ? (int)$_POST['count'] : 20;
            echo json_encode(fpc_run_monitor_test($cache_dir, $monitor_log, $base_dir, $count));
            exit;

        case 'add_custom_url':
            $url = isset($_POST['url']) ? trim($_POST['url']) : '';
            if (empty($url)) { echo json_encode(array('ok' => false, 'msg' => 'No URL provided')); exit; }
            echo json_encode(fpc_add_custom_url($custom_urls_file, $url));
            exit;

        case 'custom_urls':
            echo json_encode(fpc_get_custom_urls($custom_urls_file));
            exit;

        case 'remove_custom_url':
            $url = isset($_POST['url']) ? trim($_POST['url']) : '';
            echo json_encode(fpc_remove_custom_url($custom_urls_file, $url));
            exit;

        case 'healthcheck':
            echo json_encode(fpc_get_healthcheck_data($healthcheck_file));
            exit;

        case 'run_healthcheck':
            echo json_encode(fpc_trigger_healthcheck($base_dir));
            exit;

        case 'visitor_stats':
            $days = isset($_GET['days']) ? min(90, max(1, (int)$_GET['days'])) : 30;
            echo json_encode(fpc_get_visitor_stats($tracker_dir, $days));
            exit;

        case 'error_log':
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
            $filter = isset($_GET['filter']) ? $_GET['filter'] : '';
            echo json_encode(fpc_get_error_log($lines, $filter));
            exit;

        case 'export_urls':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="fpc_urls_' . date('Y-m-d') . '.csv"');
            fpc_export_urls_csv($cache_dir);
            exit;

        case 'validate_htaccess':
            echo json_encode(fpc_validate_htaccess($base_dir));
            exit;

        // --- v9.0.0: Neue Endpoints ---

        case 'performance':
            echo json_encode(fpc_get_performance_data($request_log));
            exit;

        case 'coverage':
            echo json_encode(fpc_get_coverage_data($cache_dir, $base_dir, $shop_url));
            exit;

        case 'seo_data':
            echo json_encode(fpc_get_seo_data($request_log, $cache_dir));
            exit;

        case 'inspector':
            $count = isset($_GET['count']) ? (int)$_GET['count'] : 100;
            $filter = isset($_GET['filter']) ? $_GET['filter'] : '';
            echo json_encode(fpc_get_inspector_data($request_log, $count, $filter));
            exit;

        case 'miss_reasons':
            echo json_encode(fpc_get_miss_reasons($request_log));
            exit;

        case 'slowest_pages':
            echo json_encode(fpc_get_slowest_pages($request_log, 20));
            exit;

        case 'error_urls':
            echo json_encode(fpc_get_error_urls($request_log, 50));
            exit;

        case 'alerts_config':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $cfg = json_decode(file_get_contents('php://input'), true);
                file_put_contents($alerts_config, json_encode($cfg, JSON_PRETTY_PRINT));
                echo json_encode(array('ok' => true, 'msg' => 'Alerts saved'));
            } else {
                echo json_encode(fpc_get_alerts_config($alerts_config));
            }
            exit;

        case 'alerts_history':
            echo json_encode(fpc_get_alerts_history($alerts_log));
            exit;

        case 'preloader_status':
            echo json_encode(fpc_get_preloader_status($cache_dir, $pid_file, $log_file, $rebuild_log));
            exit;

        // v9.0.6: Settings Tab
        case 'settings_load':
            echo json_encode(fpc_load_settings($config_dir));
            exit;

        case 'settings_save':
            $cfg = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_save_settings($cfg, $config_dir));
            exit;

        // v9.2.0: Google Search Console (extended)
        case 'gsc_data':
            $gsc_days = isset($_GET['days']) ? intval($_GET['days']) : 28;
            echo json_encode(fpc_get_gsc_data($cache_dir, $base_dir, $gsc_days));
            exit;

        case 'gsc_inspect':
            $urls = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_get_gsc_inspection($cache_dir, $base_dir, $urls ?: []));
            exit;

        // v9.2.0: Google Analytics 4 (extended)
        case 'ga4_data':
            $ga4_days = isset($_GET['days']) ? intval($_GET['days']) : 30;
            echo json_encode(fpc_get_ga4_data($cache_dir, $base_dir, $ga4_days));
            exit;

        // v9.1.0: SISTRIX
        case 'sistrix_data':
            echo json_encode(fpc_get_sistrix_data($cache_dir, $base_dir));
            exit;

        // v9.1.0: Save API credentials
        case 'save_api_credentials':
            $creds = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_save_api_credentials($config_dir, $creds));
            exit;

        // v9.1.0: Load API credentials
        case 'load_api_credentials':
            echo json_encode(fpc_load_api_credentials($config_dir));
            exit;

        // v10.0.0: SEO Engine Endpoints
        case 'seo_overview':
            echo json_encode(fpc_seo_overview($base_dir));
            exit;

        case 'seo_redirects':
            echo json_encode(fpc_seo_redirects($base_dir, $_GET));
            exit;

        case 'seo_redirect_add':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_seo_redirect_add($base_dir, $data));
            exit;

        case 'seo_redirect_update':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_seo_redirect_update($base_dir, $data));
            exit;

        case 'seo_redirect_delete':
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            echo json_encode(fpc_seo_redirect_delete($base_dir, $id));
            exit;

        case 'seo_canonicals':
            echo json_encode(fpc_seo_canonicals($base_dir));
            exit;

        case 'seo_canonical_add':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_seo_canonical_add($base_dir, $data));
            exit;

        case 'seo_canonical_delete':
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            echo json_encode(fpc_seo_canonical_delete($base_dir, $id));
            exit;

        case 'seo_404_log':
            echo json_encode(fpc_seo_404_log($base_dir, $_GET));
            exit;

        case 'seo_404_resolve':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_seo_404_resolve($base_dir, $data));
            exit;

        case 'seo_404_dismiss':
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            echo json_encode(fpc_seo_404_dismiss($base_dir, $id));
            exit;

        // v10.2.6: URL pruefen - HTTP-Status und Redirect-Kette
        case 'seo_check_url':
            $url = isset($_GET['url']) ? $_GET['url'] : '';
            echo json_encode(fpc_seo_check_url($url));
            exit;

        // v10.2.6: System-URLs aus 404-Log bereinigen
        case 'seo_404_cleanup':
            echo json_encode(fpc_seo_404_cleanup($base_dir));
            exit;

        case 'seo_scan':
            $mode = isset($_GET['mode']) ? $_GET['mode'] : 'fast';
            echo json_encode(fpc_seo_scan($base_dir, $mode));
            exit;

        case 'seo_scan_results':
            echo json_encode(fpc_seo_scan_results($base_dir, $_GET));
            exit;

        case 'seo_problems':
            echo json_encode(fpc_seo_problems($base_dir, $cache_dir));
            exit;

        case 'seo_export_csv':
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename=redirects_export.csv');
            echo fpc_seo_export_csv($base_dir);
            exit;

        case 'seo_import_csv':
            $csv = file_get_contents('php://input');
            echo json_encode(fpc_seo_import_csv($base_dir, $csv));
            exit;

        // v10.0.0: AI Analyzer Endpoints
        case 'ai_analysis':
            $force = isset($_GET['force']) && $_GET['force'] === '1';
            fpc_json_exit(fpc_ai_analysis($base_dir, $force));

        case 'ai_chat':
            $data = json_decode(file_get_contents('php://input'), true);
            fpc_json_exit(fpc_ai_chat($base_dir, $data));

        case 'ai_chat_history':
            fpc_json_exit(fpc_ai_chat_history($base_dir));

        case 'ai_chat_clear':
            fpc_json_exit(fpc_ai_chat_clear($base_dir));

        case 'ai_quick_summary':
            fpc_json_exit(fpc_ai_quick_summary($base_dir));

        // v10.2.1: AI Prompt Management
        case 'ai_prompt_load':
            fpc_json_exit(fpc_ai_prompt_load($base_dir));

        case 'ai_prompt_save':
            $data = json_decode(file_get_contents('php://input'), true);
            fpc_json_exit(fpc_ai_prompt_save($base_dir, $data));

        case 'ai_prompt_reset':
            fpc_json_exit(fpc_ai_prompt_reset($base_dir));

        // v10.4.0: KI-Redirect-Vorschlaege
        case 'ai_redirect_suggest':
            $data = json_decode(file_get_contents('php://input'), true);
            $urls = isset($data['urls']) ? $data['urls'] : array();
            fpc_json_exit(fpc_ai_redirect_suggest($base_dir, $urls));

        // v10.4.0: Bulk-Redirect (mehrere auf einmal anlegen, z.B. alle Sprachen)
        case 'seo_redirect_bulk_add':
            $data = json_decode(file_get_contents('php://input'), true);
            $redirects = isset($data['redirects']) ? $data['redirects'] : array();
            fpc_json_exit(fpc_seo_redirect_bulk_add($base_dir, $redirects));

        // v10.0.1: File Editor (htaccess, robots.txt)
        case 'file_read':
            $file = isset($_GET['file']) ? $_GET['file'] : '';
            echo json_encode(fpc_file_read($base_dir, $file));
            exit;

        case 'file_save':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_file_save($base_dir, $data));
            exit;

        case 'file_backups':
            $file = isset($_GET['file']) ? $_GET['file'] : '';
            echo json_encode(fpc_file_backups($base_dir, $file));
            exit;

        case 'file_restore':
            $data = json_decode(file_get_contents('php://input'), true);
            echo json_encode(fpc_file_restore($base_dir, $data));
            exit;
    }
    exit;
}

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

// v10.0.0: SEO Engine Backend Functions
function fpc_seo_init($base_dir) {
    require_once $base_dir . 'fpc_seo.php';
    return new FpcSeo($base_dir);
}

function fpc_seo_overview($base_dir) {
    $seo = fpc_seo_init($base_dir);
    return $seo->getIstZustand();
}

function fpc_seo_redirects($base_dir, $params) {
    $seo = fpc_seo_init($base_dir);
    return $seo->getRedirects($params);
}

function fpc_seo_redirect_add($base_dir, $data) {
    $seo = fpc_seo_init($base_dir);
    return $seo->addRedirect(
        isset($data['source']) ? $data['source'] : '',
        isset($data['target']) ? $data['target'] : '',
        isset($data['type']) ? $data['type'] : '301',
        isset($data['is_regex']) ? $data['is_regex'] : false,
        isset($data['note']) ? $data['note'] : ''
    );
}

function fpc_seo_redirect_update($base_dir, $data) {
    $seo = fpc_seo_init($base_dir);
    $id = isset($data['id']) ? intval($data['id']) : 0;
    return $seo->updateRedirect($id, $data);
}

function fpc_seo_redirect_delete($base_dir, $id) {
    $seo = fpc_seo_init($base_dir);
    return $seo->deleteRedirect($id);
}

function fpc_seo_canonicals($base_dir) {
    $seo = fpc_seo_init($base_dir);
    return $seo->getCanonicals();
}

function fpc_seo_canonical_add($base_dir, $data) {
    $seo = fpc_seo_init($base_dir);
    return $seo->addCanonical(
        isset($data['page_url']) ? $data['page_url'] : '',
        isset($data['canonical_url']) ? $data['canonical_url'] : '',
        isset($data['note']) ? $data['note'] : ''
    );
}

function fpc_seo_canonical_delete($base_dir, $id) {
    $seo = fpc_seo_init($base_dir);
    return $seo->deleteCanonical($id);
}

function fpc_seo_404_log($base_dir, $params) {
    $seo = fpc_seo_init($base_dir);
    return $seo->get404Log($params);
}

function fpc_seo_404_resolve($base_dir, $data) {
    $seo = fpc_seo_init($base_dir);
    $id = isset($data['id']) ? intval($data['id']) : 0;
    $target = isset($data['target']) ? $data['target'] : '';
    return $seo->resolve404($id, $target);
}

function fpc_seo_404_dismiss($base_dir, $id) {
    $seo = fpc_seo_init($base_dir);
    return $seo->dismiss404($id);
}

// v10.2.6: URL pruefen - HTTP-Status und Redirect-Kette
function fpc_seo_check_url($url) {
    // v10.2.7: URLs die bereits mit http beginnen nicht nochmal prefixen
    $full_url = (strpos($url, 'http') === 0) ? $url : 'https://mr-hanf.de' . $url;
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $full_url,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_RETURNTRANSFER => true,
    ));
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirect_to = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    $result = array('url' => $url, 'status' => $status);

    // Wenn Redirect, auch Ziel pruefen
    if ($status >= 300 && $status < 400 && !empty($redirect_to)) {
        $result['redirect_to'] = str_replace('https://mr-hanf.de', '', $redirect_to);
        $ch2 = curl_init();
        curl_setopt_array($ch2, array(
            CURLOPT_URL => $redirect_to,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_RETURNTRANSFER => true,
        ));
        curl_exec($ch2);
        $result['final_status'] = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $result['final_url'] = str_replace('https://mr-hanf.de', '', curl_getinfo($ch2, CURLINFO_EFFECTIVE_URL));
        curl_close($ch2);
    }

    return $result;
}

// v10.2.6: System-URLs aus 404-Log bereinigen
function fpc_seo_404_cleanup($base_dir) {
    $seo = fpc_seo_init($base_dir);
    $log = $seo->get404Log();
    $removed = 0;
    foreach ($log as $entry) {
        if (FpcSeo::isSystemUrl($entry['url'])) {
            $seo->dismiss404($entry['id']);
            $removed++;
        }
    }
    return array('ok' => true, 'msg' => $removed . ' System-URLs bereinigt', 'removed' => $removed);
}

function fpc_seo_scan($base_dir, $mode) {
    $seo = fpc_seo_init($base_dir);
    set_time_limit(300);
    return $seo->runAutoScan($mode, $mode === 'fast' ? 100 : 500);
}

function fpc_seo_scan_results($base_dir, $params) {
    $seo = fpc_seo_init($base_dir);
    return $seo->getScanResults($params);
}

function fpc_seo_problems($base_dir, $cache_dir) {
    $seo = fpc_seo_init($base_dir);
    // Try to get GSC and GA4 data for cross-API correlation
    $gsc_data = null;
    $ga4_data = null;
    try {
        $creds = fpc_load_api_credentials($config_dir);
        if (!empty($creds['gsc_service_account']) && is_file($base_dir . $creds['gsc_service_account'])) {
            require_once $base_dir . 'fpc_gsc.php';
            $gsc = new FpcGsc($base_dir . $creds['gsc_service_account'], $creds['gsc_site_url']);
            $gsc_data = $gsc->getOverview(28);
        }
    } catch (Exception $e) {}
    try {
        $creds = fpc_load_api_credentials($config_dir);
        if (!empty($creds['ga4_service_account']) && !empty($creds['ga4_property_id'])) {
            require_once $base_dir . 'fpc_ga4.php';
            $ga4 = new FpcGa4($base_dir . $creds['ga4_service_account'], $creds['ga4_property_id']);
            $ga4_data = $ga4->getOverview(30);
        }
    } catch (Exception $e) {}
    return $seo->getCrossApiProblems($gsc_data, $ga4_data, null);
}

function fpc_seo_export_csv($base_dir) {
    $seo = fpc_seo_init($base_dir);
    return $seo->exportRedirectsCsv();
}

function fpc_seo_import_csv($base_dir, $csv) {
    $seo = fpc_seo_init($base_dir);
    return $seo->importRedirectsCsv($csv);
}

// v10.0.0: AI Analyzer Backend Functions
function fpc_ai_init($base_dir) {
    require_once $base_dir . 'fpc_ai.php';
    return new FpcAi($base_dir);
}

function fpc_ai_analysis($base_dir, $force = false) {
    try {
        $ai = fpc_ai_init($base_dir);
        set_time_limit(120);
        return $ai->runAnalysis($force);
    } catch (Exception $e) {
        return array('error' => true, 'msg' => 'AI Analyse Fehler: ' . $e->getMessage());
    } catch (Error $e) {
        return array('error' => true, 'msg' => 'AI Analyse Fatal: ' . $e->getMessage());
    }
}

function fpc_ai_chat($base_dir, $data) {
    try {
        $ai = fpc_ai_init($base_dir);
        set_time_limit(120);
        $msg = isset($data['message']) ? $data['message'] : '';
        if (empty($msg)) return array('error' => true, 'msg' => 'Nachricht darf nicht leer sein');
        return $ai->chat($msg);
    } catch (Exception $e) {
        return array('error' => true, 'msg' => 'AI Chat Fehler: ' . $e->getMessage());
    } catch (Error $e) {
        return array('error' => true, 'msg' => 'AI Chat Fatal: ' . $e->getMessage());
    }
}

function fpc_ai_chat_history($base_dir) {
    $ai = fpc_ai_init($base_dir);
    return $ai->getChatHistory();
}

function fpc_ai_chat_clear($base_dir) {
    $ai = fpc_ai_init($base_dir);
    return $ai->clearChatHistory();
}

function fpc_ai_quick_summary($base_dir) {
    $ai = fpc_ai_init($base_dir);
    return $ai->getQuickSummary();
}

// v10.2.1: AI Prompt Management Functions
function fpc_ai_prompt_load($base_dir) {
    $ai = fpc_ai_init($base_dir);
    return array(
        'ok' => true,
        'prompt' => $ai->getSystemPrompt(),
        'default_prompt' => $ai->getDefaultSystemPrompt(),
        'is_custom' => $ai->getSystemPrompt() !== $ai->getDefaultSystemPrompt(),
        'length' => strlen($ai->getSystemPrompt()),
    );
}

function fpc_ai_prompt_save($base_dir, $data) {
    $ai = fpc_ai_init($base_dir);
    $prompt = isset($data['prompt']) ? $data['prompt'] : '';
    return $ai->saveSystemPrompt($prompt);
}

function fpc_ai_prompt_reset($base_dir) {
    $ai = fpc_ai_init($base_dir);
    return $ai->saveSystemPrompt(''); // Leerer String = Reset auf Default
}

// v10.4.0: KI-Redirect-Vorschlaege Backend
function fpc_ai_redirect_suggest($base_dir, $urls) {
    try {
        $ai = fpc_ai_init($base_dir);
        set_time_limit(120);
        return $ai->suggestRedirects($urls);
    } catch (Exception $e) {
        return array('error' => true, 'msg' => 'KI-Fehler: ' . $e->getMessage());
    }
}

// v10.4.0: Bulk-Redirect - mehrere Redirects auf einmal anlegen
function fpc_seo_redirect_bulk_add($base_dir, $redirects) {
    $seo = fpc_seo_init($base_dir);
    $added = 0;
    $errors = array();
    foreach ($redirects as $r) {
        $source = isset($r['source']) ? $r['source'] : '';
        $target = isset($r['target']) ? $r['target'] : '';
        $type = isset($r['type']) ? $r['type'] : '301';
        $note = isset($r['note']) ? $r['note'] : 'KI-Vorschlag';
        if (empty($source) || empty($target)) {
            $errors[] = 'Leere Source/Target: ' . $source;
            continue;
        }
        $result = $seo->addRedirect($source, $target, $type, false, $note);
        if (isset($result['ok']) && $result['ok']) {
            $added++;
        } else {
            $errors[] = isset($result['msg']) ? $result['msg'] : 'Fehler bei ' . $source;
        }
    }
    return array(
        'ok' => true,
        'added' => $added,
        'errors' => $errors,
        'msg' => $added . ' Redirects angelegt' . (count($errors) > 0 ? ', ' . count($errors) . ' Fehler' : ''),
    );
}

// v10.0.1: File Editor Backend Functions
function fpc_file_allowed() {
    return array(
        'htaccess' => '.htaccess',
        'robots'   => 'robots.txt',
    );
}

function fpc_file_read($base_dir, $file_key) {
    $allowed = fpc_file_allowed();
    if (!isset($allowed[$file_key])) return array('error' => true, 'msg' => 'Unbekannte Datei: ' . $file_key);
    $filepath = $base_dir . $allowed[$file_key];
    if (!is_file($filepath)) return array('error' => true, 'msg' => 'Datei nicht gefunden: ' . $allowed[$file_key], 'content' => '');
    $content = @file_get_contents($filepath);
    if ($content === false) return array('error' => true, 'msg' => 'Datei konnte nicht gelesen werden', 'content' => '');
    return array(
        'ok' => true,
        'file' => $allowed[$file_key],
        'content' => $content,
        'size' => strlen($content),
        'modified' => date('Y-m-d H:i:s', filemtime($filepath)),
        'writable' => is_writable($filepath),
    );
}

function fpc_file_save($base_dir, $data) {
    $allowed = fpc_file_allowed();
    $file_key = isset($data['file']) ? $data['file'] : '';
    $content = isset($data['content']) ? $data['content'] : '';
    if (!isset($allowed[$file_key])) return array('error' => true, 'msg' => 'Unbekannte Datei');
    $filepath = $base_dir . $allowed[$file_key];
    if (is_file($filepath) && !is_writable($filepath)) return array('error' => true, 'msg' => 'Datei ist nicht beschreibbar. Pruefe Dateiberechtigungen.');
    // Backup erstellen
    $backup_dir = $base_dir . 'cache/fpc/seo/backups/';
    if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);
    if (is_file($filepath)) {
        $backup_name = $file_key . '_' . date('Y-m-d_H-i-s') . '.bak';
        @copy($filepath, $backup_dir . $backup_name);
        // Max 20 Backups pro Datei behalten
        $backups = glob($backup_dir . $file_key . '_*.bak');
        if ($backups && count($backups) > 20) {
            sort($backups);
            $to_delete = array_slice($backups, 0, count($backups) - 20);
            foreach ($to_delete as $old) @unlink($old);
        }
    }
    $result = @file_put_contents($filepath, $content);
    if ($result === false) return array('error' => true, 'msg' => 'Fehler beim Speichern der Datei');
    return array('ok' => true, 'msg' => $allowed[$file_key] . ' gespeichert (' . strlen($content) . ' Bytes). Backup erstellt.');
}

function fpc_file_backups($base_dir, $file_key) {
    $allowed = fpc_file_allowed();
    if (!isset($allowed[$file_key])) return array();
    $backup_dir = $base_dir . 'cache/fpc/seo/backups/';
    $backups = glob($backup_dir . $file_key . '_*.bak');
    if (!$backups) return array();
    rsort($backups);
    $result = array();
    foreach ($backups as $b) {
        $result[] = array(
            'name' => basename($b),
            'date' => date('Y-m-d H:i:s', filemtime($b)),
            'size' => filesize($b),
        );
    }
    return $result;
}

function fpc_file_restore($base_dir, $data) {
    $allowed = fpc_file_allowed();
    $file_key = isset($data['file']) ? $data['file'] : '';
    $backup_name = isset($data['backup']) ? $data['backup'] : '';
    if (!isset($allowed[$file_key])) return array('error' => true, 'msg' => 'Unbekannte Datei');
    // Sicherheitscheck: backup_name darf nur alphanumerisch + Unterstrich + Punkt + Bindestrich sein
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $backup_name)) return array('error' => true, 'msg' => 'Ungueltiger Backup-Name');
    $backup_dir = $base_dir . 'cache/fpc/seo/backups/';
    $backup_path = $backup_dir . $backup_name;
    if (!is_file($backup_path)) return array('error' => true, 'msg' => 'Backup nicht gefunden');
    $filepath = $base_dir . $allowed[$file_key];
    // Aktuellen Stand als Backup sichern bevor Restore
    if (is_file($filepath)) {
        $pre_restore = $file_key . '_pre-restore_' . date('Y-m-d_H-i-s') . '.bak';
        @copy($filepath, $backup_dir . $pre_restore);
    }
    $content = @file_get_contents($backup_path);
    if ($content === false) return array('error' => true, 'msg' => 'Backup konnte nicht gelesen werden');
    $result = @file_put_contents($filepath, $content);
    if ($result === false) return array('error' => true, 'msg' => 'Restore fehlgeschlagen');
    return array('ok' => true, 'msg' => $allowed[$file_key] . ' wiederhergestellt aus ' . $backup_name);
}

// v9.0.5: Helper to read daily request log files from logs/ directory
function fpc_read_request_logs($log_dir, $max_bytes = 2000000, $days = 7) {
    $entries = array();
    if (!is_dir($log_dir)) return $entries;
    $files = glob($log_dir . 'requests_*.log');
    if (!$files) return $entries;
    // Sort by date descending (newest first)
    rsort($files);
    // Only read last N days
    $files = array_slice($files, 0, $days);
    $total_read = 0;
    foreach ($files as $file) {
        $fp = @fopen($file, 'r');
        if (!$fp) continue;
        $fsize = filesize($file);
        if ($fsize > $max_bytes - $total_read) {
            fseek($fp, max(0, $fsize - ($max_bytes - $total_read)));
            fgets($fp); // skip partial line
        }
        while (($line = fgets($fp)) !== false) {
            $r = @json_decode(trim($line), true);
            if ($r && isset($r['ts'])) $entries[] = $r;
        }
        fclose($fp);
        $total_read += $fsize;
        if ($total_read >= $max_bytes) break;
    }
    return $entries;
}

// v9.0.5: Read today's log file only (for real-time status)
function fpc_read_todays_log($log_dir, $max_bytes = 500000) {
    $file = $log_dir . 'requests_' . date('Y-m-d') . '.log';
    $entries = array();
    if (!is_file($file)) return $entries;
    $fp = @fopen($file, 'r');
    if (!$fp) return $entries;
    $fsize = filesize($file);
    if ($fsize > $max_bytes) {
        fseek($fp, $fsize - $max_bytes);
        fgets($fp); // skip partial line
    }
    while (($line = fgets($fp)) !== false) {
        $r = @json_decode(trim($line), true);
        if ($r && isset($r['ts'])) $entries[] = $r;
    }
    fclose($fp);
    return $entries;
}

function fpc_get_status($cache_dir, $pid_file, $log_file, $healthcheck_file, $request_log) {
    $files = 0; $size = 0; $oldest = PHP_INT_MAX; $newest = 0;
    $categories = array();
    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'html') {
                $files++; $size += $f->getSize();
                $mt = $f->getMTime();
                if ($mt < $oldest) $oldest = $mt;
                if ($mt > $newest) $newest = $mt;
                $rel = str_replace($cache_dir, '', $f->getPath());
                $parts = explode('/', trim($rel, '/'));
                $cat = !empty($parts[0]) ? $parts[0] : 'startseite';
                if (!isset($categories[$cat])) $categories[$cat] = 0;
                $categories[$cat]++;
            }
        }
    }
    $last_run = null; $last_stats = null;
    if (is_file($log_file)) {
        $tail = fpc_tail_file($log_file, 10);
        if (preg_match('/\[FPC\] Fertig: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $tail, $m)) $last_run = $m[1];
        if (preg_match('/Gecacht: (\d+) \| Uebersprungen: (\d+).*Fehler: (\d+)/', $tail, $m2))
            $last_stats = array('cached' => (int)$m2[1], 'skipped' => (int)$m2[2], 'errors' => (int)$m2[3]);
    }
    $rebuild_running = false; $rebuild_started = null;
    if (is_file($pid_file)) {
        $content = file_get_contents($pid_file);
        $lines = explode("\n", trim($content));
        $pid = (int)$lines[0];
        if ($pid > 0) {
            $running = function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir('/proc/' . $pid);
            if ($running) { $rebuild_running = true; $rebuild_started = isset($lines[1]) ? $lines[1] : null; }
            else @unlink($pid_file);
        }
    }
    $health_score = null; $health_grade = null;
    if (is_file($healthcheck_file)) {
        $hc = @json_decode(file_get_contents($healthcheck_file), true);
        if (isset($hc['latest']['summary'])) {
            $health_score = $hc['latest']['summary']['health_score'];
            $health_grade = $hc['latest']['summary']['health_grade'];
        }
    }
    // v9.0.5: Hit/Miss from daily request log files
    $hit_rate = 0; $total_requests = 0; $hits = 0; $errors_1h = 0;
    $one_hour_ago = time() - 3600;
    $today_entries = fpc_read_todays_log($request_log);
    foreach ($today_entries as $r) {
        if ($r['ts'] >= $one_hour_ago) {
            $total_requests++;
            if (isset($r['status']) && $r['status'] === 'HIT') $hits++;
            if (isset($r['http_code']) && $r['http_code'] >= 500) $errors_1h++;
        }
    }
    $hit_rate = $total_requests > 0 ? round(($hits / $total_requests) * 100, 1) : 0;
    // OPCache
    $opcache = null;
    if (function_exists('opcache_get_status')) {
        $oc = @opcache_get_status(false);
        if ($oc) $opcache = array(
            'enabled' => $oc['opcache_enabled'],
            'hit_rate' => round($oc['opcache_statistics']['opcache_hit_rate'], 1),
            'memory_used' => round($oc['memory_usage']['used_memory'] / 1048576, 1),
            'memory_free' => round($oc['memory_usage']['free_memory'] / 1048576, 1),
        );
    }
    arsort($categories);
    return array(
        'files' => $files, 'size' => $size, 'size_formatted' => fpc_format_bytes($size),
        'oldest' => $oldest < PHP_INT_MAX ? date('Y-m-d H:i', $oldest) : null,
        'newest' => $newest > 0 ? date('Y-m-d H:i', $newest) : null,
        'last_run' => $last_run, 'last_stats' => $last_stats,
        'rebuild_running' => $rebuild_running, 'rebuild_started' => $rebuild_started,
        'categories' => array_slice($categories, 0, 15, true),
        'health_score' => $health_score, 'health_grade' => $health_grade,
        'hit_rate' => $hit_rate, 'total_requests_1h' => $total_requests,
        'hits_1h' => $hits, 'errors_1h' => $errors_1h, 'opcache' => $opcache,
    );
}

function fpc_tail_file($file, $lines = 100) {
    if (!is_file($file)) return '(File not found: ' . basename($file) . ')';
    $result = array();
    $fp = @fopen($file, 'r');
    if (!$fp) return '(File not readable)';
    $buffer = array();
    while (($line = fgets($fp)) !== false) {
        $buffer[] = rtrim($line);
        if (count($buffer) > $lines) array_shift($buffer);
    }
    fclose($fp);
    return implode("\n", $buffer);
}

function fpc_format_bytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function fpc_list_cached_urls($cache_dir, $search, $page, $per_page) {
    $all = array();
    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'html') continue;
            $rel = str_replace($cache_dir, '', $f->getPathname());
            $path = '/' . str_replace('/index.html', '/', $rel);
            $path = str_replace('//', '/', $path);
            if ($search && stripos($path, $search) === false) continue;
            $all[] = array('path' => $path, 'size' => $f->getSize(), 'cached' => date('Y-m-d H:i', $f->getMTime()), 'age_h' => round((time() - $f->getMTime()) / 3600, 1));
        }
    }
    usort($all, function($a, $b) { return strcmp($a['path'], $b['path']); });
    $offset = ($page - 1) * $per_page;
    return array('total' => count($all), 'page' => $page, 'pages' => max(1, ceil(count($all) / $per_page)), 'urls' => array_slice($all, $offset, $per_page));
}

function fpc_cache_single_url($url, $cache_dir, $base_dir) {
    if (strpos($url, 'http') !== 0) $url = 'https://mr-hanf.de' . (substr($url, 0, 1) !== '/' ? '/' : '') . $url;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_COOKIE => 'fpc_bypass=1',
        CURLOPT_HTTPHEADER => array('Accept: text/html,application/xhtml+xml', 'Accept-Language: de-DE,de;q=0.9'),
    ));
    $html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200) return array('ok' => false, 'msg' => 'HTTP ' . $code . ' - Page could not be loaded (' . strlen($html) . ' Bytes)');
    if (strlen($html) < 500) return array('ok' => false, 'msg' => 'Response too small (' . strlen($html) . ' Bytes)');
    $parsed = parse_url($url);
    $path = isset($parsed['path']) ? $parsed['path'] : '/';
    if (substr($path, -1) !== '/') $path .= '/';
    $file_path = $cache_dir . ltrim($path, '/') . 'index.html';
    $dir = dirname($file_path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($file_path, $html);
    return array('ok' => true, 'msg' => 'Cached: ' . $path . ' (' . fpc_format_bytes(strlen($html)) . ')');
}

function fpc_remove_cached_url($cache_dir, $path) {
    $file = $cache_dir . ltrim($path, '/') . 'index.html';
    if (is_file($file)) { @unlink($file); return array('ok' => true, 'msg' => 'Removed: ' . $path); }
    return array('ok' => false, 'msg' => 'Not found: ' . $path);
}

function fpc_flush_cache($cache_dir) {
    if (!is_dir($cache_dir)) return;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iter as $f) {
        if ($f->isFile() && $f->getExtension() === 'html') @unlink($f->getPathname());
    }
}

function fpc_trigger_rebuild($base_dir, $cache_dir, $pid_file) {
    if (is_file($pid_file)) {
        $content = file_get_contents($pid_file);
        $pid = (int)explode("\n", trim($content))[0];
        $running = $pid > 0 && (function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir('/proc/' . $pid));
        if ($running) return array('ok' => false, 'msg' => 'Rebuild laeuft bereits (PID: ' . $pid . ')');
        @unlink($pid_file);
    }
    $log = $cache_dir . 'rebuild_manual.log';
    $cmd = 'cd ' . escapeshellarg($base_dir) . ' && /usr/local/bin/php fpc_preloader.php > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
    $pid = trim(shell_exec($cmd));
    file_put_contents($pid_file, $pid . "\n" . date('Y-m-d H:i:s'));
    return array('ok' => true, 'msg' => 'Rebuild gestartet (PID: ' . $pid . ')', 'pid' => (int)$pid);
}

function fpc_stop_rebuild($pid_file) {
    if (!is_file($pid_file)) return;
    $pid = (int)explode("\n", trim(file_get_contents($pid_file)))[0];
    if ($pid > 0) { if (function_exists('posix_kill')) posix_kill($pid, 15); else @exec('kill ' . $pid); }
    @unlink($pid_file);
}

function fpc_get_rebuild_progress($cache_dir, $pid_file, $rebuild_log) {
    $running = false; $pid = 0; $started = null;
    if (is_file($pid_file)) {
        $content = file_get_contents($pid_file);
        $lines = explode("\n", trim($content));
        $pid = (int)$lines[0]; $started = isset($lines[1]) ? $lines[1] : null;
        if ($pid > 0) {
            $running = function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir('/proc/' . $pid);
            if (!$running) { @unlink($pid_file); $pid = 0; }
        }
    }
    $total = 0; $done = 0; $errors = 0; $skipped = 0; $current_url = ''; $last_lines = array(); $speed = 0;
    if (is_file($rebuild_log)) {
        $fp = @fopen($rebuild_log, 'r');
        if ($fp) {
            $runtime_sec = 0;
            while (($line = fgets($fp)) !== false) {
                $line = trim($line);
                // v9.0.6: Match actual preloader log format
                // "[FPC] 37500 URLs aus Sitemap" or "[FPC] 37500 URLs nach Filter (max 50000)"
                if (preg_match('/\[FPC\]\s+(\d+)\s+URLs\s+(aus|nach)/', $line, $m)) {
                    $total = (int)$m[1];
                }
                // "[FPC] Fortschritt: 100 gecacht | Avg-TTFB: 485ms | ... | Runtime: 101s"
                if (preg_match('/Fortschritt:\s*(\d+)\s*gecacht/', $line, $m)) {
                    $done = (int)$m[1];
                    $current_url = $line;
                }
                // Extract runtime for speed calculation
                if (preg_match('/Runtime:\s*(\d+)s/', $line, $m)) {
                    $runtime_sec = (int)$m[1];
                }
                // "[FPC] FEHLER:" or "[FPC] SCHREIBFEHLER:" or "[FPC] VERIFY-FEHLER:"
                if (preg_match('/FEHLER|Error|UNGUELTIG/', $line)) $errors++;
                // "Uebersprungen" or "Skip" or skipped in summary
                if (preg_match('/Uebersprungen|Skip/', $line)) $skipped++;
                // "[FPC] v8.0 | Gecacht: 1000 | Uebersprungen: 50 | Ungueltig: 5 | Fehler: 3"
                if (preg_match('/Gecacht:\s*(\d+)\s*\|\s*Uebersprungen:\s*(\d+).*Fehler:\s*(\d+)/', $line, $m)) {
                    $done = (int)$m[1]; $skipped = (int)$m[2]; $errors = (int)$m[3];
                }
                $last_lines[] = $line; if (count($last_lines) > 8) array_shift($last_lines);
            }
            fclose($fp);
            if ($runtime_sec > 0 && $done > 0) $speed = round($done / $runtime_sec, 1);
        }
    }
    $percent = ($total > 0) ? min(100, round(($done / $total) * 100, 1)) : 0;
    return array('running' => $running, 'pid' => $pid, 'started' => $started, 'total' => $total, 'done' => $done, 'errors' => $errors, 'skipped' => $skipped, 'percent' => $percent, 'speed' => $speed, 'current_url' => $current_url, 'last_lines' => $last_lines);
}

function fpc_get_monitor_data($monitor_log) {
    if (!is_file($monitor_log)) return array('entries' => array(), 'latest' => null);
    $data = @json_decode(file_get_contents($monitor_log), true);
    if (!$data) return array('entries' => array(), 'latest' => null);
    $entries = isset($data['history']) ? array_slice($data['history'], -50) : array();
    return array('entries' => $entries, 'latest' => !empty($entries) ? end($entries) : null);
}

function fpc_run_monitor_test($cache_dir, $monitor_log, $base_dir, $count) {
    $urls = array(); $results = array();
    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'html') {
                $rel = str_replace($cache_dir, '', $f->getPathname());
                $urls[] = '/' . str_replace('/index.html', '/', $rel);
            }
        }
    }
    if (empty($urls)) return array('ok' => false, 'msg' => 'No cached URLs found');
    shuffle($urls); $urls = array_slice($urls, 0, $count);
    $hits = 0; $misses = 0; $ttfb_sum = 0;
    foreach ($urls as $u) {
        $ch = curl_init('https://mr-hanf.de' . $u);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'));
        curl_exec($ch);
        $ttfb = round(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $is_hit = $ttfb < 100;
        if ($is_hit) $hits++; else $misses++;
        $ttfb_sum += $ttfb;
        $results[] = array('url' => $u, 'ttfb' => $ttfb, 'hit' => $is_hit, 'code' => $code);
    }
    $entry = array('timestamp' => date('Y-m-d H:i:s'), 'tested' => count($urls), 'hits' => $hits, 'misses' => $misses,
        'hit_rate' => count($urls) > 0 ? round(($hits / count($urls)) * 100, 1) : 0,
        'avg_ttfb' => count($urls) > 0 ? round($ttfb_sum / count($urls)) : 0);
    $history = array();
    if (is_file($monitor_log)) { $d = @json_decode(file_get_contents($monitor_log), true); if (isset($d['history'])) $history = $d['history']; }
    $history[] = $entry; if (count($history) > 100) $history = array_slice($history, -100);
    file_put_contents($monitor_log, json_encode(array('history' => $history), JSON_PRETTY_PRINT));
    return array('ok' => true, 'msg' => 'Test fertig: ' . $hits . ' HITs, ' . $misses . ' MISSes', 'entry' => $entry, 'details' => $results);
}

function fpc_add_custom_url($file, $url) {
    $urls = is_file($file) ? array_filter(array_map('trim', file($file))) : array();
    if (in_array($url, $urls)) return array('ok' => false, 'msg' => 'URL existiert bereits');
    $urls[] = $url;
    file_put_contents($file, implode("\n", $urls) . "\n");
    return array('ok' => true, 'msg' => 'URL hinzugefuegt: ' . $url);
}

function fpc_get_custom_urls($file) {
    if (!is_file($file)) return array('urls' => array());
    return array('urls' => array_values(array_filter(array_map('trim', file($file)))));
}

function fpc_remove_custom_url($file, $url) {
    if (!is_file($file)) return array('ok' => false, 'msg' => 'File not found');
    $urls = array_filter(array_map('trim', file($file)));
    $urls = array_values(array_diff($urls, array($url)));
    file_put_contents($file, implode("\n", $urls) . "\n");
    return array('ok' => true, 'msg' => 'Removed: ' . $url);
}

function fpc_get_healthcheck_data($file) {
    if (!is_file($file)) return array('available' => false);
    $data = @json_decode(file_get_contents($file), true);
    if (!$data) return array('available' => false);
    return array('available' => true, 'data' => $data);
}

function fpc_trigger_healthcheck($base_dir) {
    $script = $base_dir . 'fpc_healthcheck.php';
    if (!is_file($script)) return array('ok' => false, 'msg' => 'fpc_healthcheck.php nicht gefunden');
    $cmd = 'cd ' . escapeshellarg($base_dir) . ' && /usr/local/bin/php fpc_healthcheck.php 2>&1';
    $output = shell_exec($cmd);
    return array('ok' => true, 'msg' => 'Health-Check ausgefuehrt', 'output' => $output);
}

function fpc_get_visitor_stats($tracker_dir, $days) {
    // v9.0.5: Reads .json files from fpc_tracker.php (NOT .jsonl)
    // Each file is a JSON object with: pageviews, visitors, hours, pages, referrers, devices, sessions
    $result = array('total_pageviews' => 0, 'total_visitors' => 0, 'avg_duration' => 0, 'bounce_rate' => 0, 'daily' => array(), 'hours' => array_fill(0, 24, 0), 'devices' => array('desktop' => 0, 'mobile' => 0, 'tablet' => 0), 'top_pages' => array(), 'top_referrers' => array());
    if (!is_dir($tracker_dir)) return $result;
    $all_durations = array(); $total_bounces = 0; $total_sessions = 0;
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $file = $tracker_dir . $date . '.json';
        if (!is_file($file)) continue;
        $data = @json_decode(file_get_contents($file), true);
        if (!$data || !is_array($data)) continue;
        $day_pv = isset($data['pageviews']) ? (int)$data['pageviews'] : 0;
        $day_visitors = isset($data['visitors']) ? (int)$data['visitors'] : 0;
        $day_bounces = 0;
        $day_sessions_count = 0;
        // Process sessions for bounce rate and duration
        if (!empty($data['sessions'])) {
            foreach ($data['sessions'] as $sid => $session) {
                $day_sessions_count++;
                $total_sessions++;
                $pages = isset($session['pages']) ? (int)$session['pages'] : 1;
                if ($pages <= 1) { $day_bounces++; $total_bounces++; }
                $dur = 0;
                if (isset($session['duration']) && $session['duration'] > 0) $dur = $session['duration'];
                elseif (isset($session['last_time']) && isset($session['start_time'])) $dur = $session['last_time'] - $session['start_time'];
                if ($dur > 0 && $dur < 3600) $all_durations[] = $dur;
            }
        }
        $result['total_pageviews'] += $day_pv;
        $result['total_visitors'] += $day_visitors;
        // Hours
        if (!empty($data['hours'])) {
            foreach ($data['hours'] as $h => $cnt) { $result['hours'][(int)$h] += (int)$cnt; }
        }
        // Pages
        if (!empty($data['pages'])) {
            foreach ($data['pages'] as $p => $cnt) {
                if (!isset($result['top_pages'][$p])) $result['top_pages'][$p] = 0;
                $result['top_pages'][$p] += (int)$cnt;
            }
        }
        // Referrers
        if (!empty($data['referrers'])) {
            foreach ($data['referrers'] as $r => $cnt) {
                if (!isset($result['top_referrers'][$r])) $result['top_referrers'][$r] = 0;
                $result['top_referrers'][$r] += (int)$cnt;
            }
        }
        // Devices
        if (!empty($data['devices'])) {
            foreach ($data['devices'] as $d => $cnt) {
                $d = strtolower($d);
                if (isset($result['devices'][$d])) $result['devices'][$d] += (int)$cnt;
            }
        }
        $result['daily'][] = array(
            'date' => $date, 'pageviews' => $day_pv, 'visitors' => $day_visitors,
            'bounce_rate' => $day_sessions_count > 0 ? round(($day_bounces / $day_sessions_count) * 100, 1) : 0
        );
    }
    $result['avg_duration'] = !empty($all_durations) ? round(array_sum($all_durations) / count($all_durations)) : 0;
    $result['bounce_rate'] = $total_sessions > 0 ? round(($total_bounces / $total_sessions) * 100, 1) : 0;
    arsort($result['top_pages']); $result['top_pages'] = array_slice($result['top_pages'], 0, 20, true);
    arsort($result['top_referrers']); $result['top_referrers'] = array_slice($result['top_referrers'], 0, 10, true);
    return $result;
}

function fpc_get_error_log($lines, $filter) {
    $log_paths = array('/var/log/php_errors.log', '/tmp/php_errors.log', ini_get('error_log'));
    $log_file = null;
    foreach ($log_paths as $p) { if ($p && is_file($p) && is_readable($p)) { $log_file = $p; break; } }
    if (!$log_file) return array('content' => '(No PHP error log found)', 'entries' => array());
    $all = array(); $fp = @fopen($log_file, 'r');
    if (!$fp) return array('content' => '(Log not readable)', 'entries' => array());
    $buffer = array();
    while (($line = fgets($fp)) !== false) { $buffer[] = rtrim($line); if (count($buffer) > $lines) array_shift($buffer); }
    fclose($fp);
    foreach ($buffer as $line) {
        if ($filter && stripos($line, $filter) === false) continue;
        $severity = 'info'; $fpc = false;
        if (stripos($line, 'fatal') !== false || stripos($line, 'critical') !== false) $severity = 'critical';
        elseif (stripos($line, 'error') !== false) $severity = 'error';
        elseif (stripos($line, 'warning') !== false) $severity = 'warning';
        elseif (stripos($line, 'notice') !== false || stripos($line, 'deprecated') !== false) $severity = 'notice';
        if (stripos($line, 'fpc') !== false || stripos($line, 'cache') !== false) $fpc = true;
        $all[] = array('line' => $line, 'severity' => $severity, 'fpc_related' => $fpc);
    }
    return array('entries' => $all);
}

function fpc_export_urls_csv($cache_dir) {
    echo "Path;Size;Cached;Age_Hours\n";
    if (!is_dir($cache_dir)) return;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'html') continue;
        $path = '/' . str_replace('/index.html', '/', str_replace($cache_dir, '', $f->getPathname()));
        echo $path . ';' . $f->getSize() . ';' . date('Y-m-d H:i', $f->getMTime()) . ';' . round((time() - $f->getMTime()) / 3600, 1) . "\n";
    }
}

function fpc_validate_htaccess($base_dir) {
    $file = $base_dir . '.htaccess';
    if (!is_file($file)) return array('ok' => false, 'msg' => '.htaccess nicht gefunden', 'checks' => array(), 'score' => 0);
    $content = file_get_contents($file);
    $checks = array();
    $checks[] = array('name' => 'FPC RewriteRule', 'ok' => strpos($content, 'fpc_serve.php') !== false);
    $checks[] = array('name' => 'POST-Bypass', 'ok' => (bool)preg_match('/RewriteCond.*REQUEST_METHOD.*(=GET|!POST)/i', $content));
    $checks[] = array('name' => 'Query-String Bypass', 'ok' => (bool)preg_match('/RewriteCond.*QUERY_STRING.*\^\$/', $content));
    $checks[] = array('name' => 'FPC-Bypass Cookie (fpc_bypass)', 'ok' => strpos($content, 'fpc_bypass') !== false, 'info' => 'Replaces MODsid bypass (v8.2.0+)');
    $checks[] = array('name' => 'Cache File Existence Check', 'ok' => strpos($content, 'cache/fpc') !== false);
    $checks[] = array('name' => 'Admin Bypass', 'ok' => strpos($content, 'admin') !== false);
    $checks[] = array('name' => 'Bot Pass-through', 'ok' => true, 'info' => 'Bots are served cached pages');
    $passed = 0; $total = count($checks);
    foreach ($checks as $c) { if ($c['ok']) $passed++; }
    return array('ok' => $passed === $total, 'msg' => $passed . '/' . $total . ' checks passed', 'checks' => $checks, 'score' => round(($passed / $total) * 100));
}

// ============================================================
// v9.0.0 NEUE FUNKTIONEN
// ============================================================

function fpc_get_performance_data($log_dir) {
    $result = array('hit_miss' => array('hit' => 0, 'miss' => 0, 'bypass' => 0), 'avg_ttfb_hit' => 0, 'avg_ttfb_miss' => 0, 'requests_per_min' => 0, 'hourly' => array_fill(0, 24, array('hit' => 0, 'miss' => 0)), 'timeline' => array());
    // v9.0.5: Read from daily log files
    $entries = fpc_read_request_logs($log_dir, 2000000, 30);
    if (empty($entries)) return $result;
    $ttfb_hits = array(); $ttfb_misses = array(); $first_ts = PHP_INT_MAX; $last_ts = 0;
    $daily = array();
    foreach ($entries as $r) {
        if ($r['ts'] < $first_ts) $first_ts = $r['ts'];
        if ($r['ts'] > $last_ts) $last_ts = $r['ts'];
        $status = isset($r['status']) ? $r['status'] : 'MISS';
        if ($status === 'HIT') { $result['hit_miss']['hit']++; if (isset($r['ttfb'])) $ttfb_hits[] = $r['ttfb']; }
        elseif ($status === 'BYPASS') { $result['hit_miss']['bypass']++; }
        else { $result['hit_miss']['miss']++; if (isset($r['ttfb'])) $ttfb_misses[] = $r['ttfb']; }
        $hour = (int)date('G', $r['ts']);
        $result['hourly'][$hour][$status === 'HIT' ? 'hit' : 'miss']++;
        $day = date('Y-m-d', $r['ts']);
        if (!isset($daily[$day])) $daily[$day] = array('hit' => 0, 'miss' => 0, 'bypass' => 0);
        $daily[$day][$status === 'HIT' ? 'hit' : ($status === 'BYPASS' ? 'bypass' : 'miss')]++;
    }
    $result['avg_ttfb_hit'] = !empty($ttfb_hits) ? round(array_sum($ttfb_hits) / count($ttfb_hits)) : 0;
    $result['avg_ttfb_miss'] = !empty($ttfb_misses) ? round(array_sum($ttfb_misses) / count($ttfb_misses)) : 0;
    $duration = max(1, $last_ts - $first_ts);
    $total = $result['hit_miss']['hit'] + $result['hit_miss']['miss'] + $result['hit_miss']['bypass'];
    $result['requests_per_min'] = round($total / ($duration / 60), 1);
    foreach ($daily as $date => $counts) { $result['timeline'][] = array_merge(array('date' => $date), $counts); }
    usort($result['timeline'], function($a, $b) { return strcmp($a['date'], $b['date']); });
    $result['timeline'] = array_slice($result['timeline'], -30);
    return $result;
}

function fpc_get_coverage_data($cache_dir, $base_dir, $shop_url) {
    $cached_paths = array();
    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'html') {
                $path = '/' . str_replace('/index.html', '/', str_replace($cache_dir, '', $f->getPathname()));
                $cached_paths[str_replace('//', '/', $path)] = true;
            }
        }
    }
    // v9.0.5: Sitemap parsen - supports sitemapindex (like fpc_preloader.php)
    $sitemap_urls = array(); $sitemap_count = 0;
    $chrome_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $sitemap_url = $shop_url . '/sitemap.xml';
    $ch = curl_init($sitemap_url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $chrome_ua));
    $xml = curl_exec($ch); $xml_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($xml && $xml_code == 200 && strlen($xml) > 100) {
        // Check if sitemapindex
        if (strpos($xml, '<sitemapindex') !== false) {
            preg_match_all('/<loc>(.*?)<\/loc>/i', $xml, $m);
            foreach ($m[1] as $sub_url) {
                $ch2 = curl_init(trim($sub_url));
                curl_setopt_array($ch2, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $chrome_ua));
                $sub_xml = curl_exec($ch2); curl_close($ch2);
                if ($sub_xml && preg_match_all('/<loc>(.*?)<\/loc>/i', $sub_xml, $m2)) {
                    foreach ($m2[1] as $loc) { $path = parse_url(trim($loc), PHP_URL_PATH); if ($path) { $sitemap_urls[] = $path; $sitemap_count++; } }
                }
            }
        } else {
            // Simple sitemap
            if (preg_match_all('/<loc>(.*?)<\/loc>/i', $xml, $m)) {
                foreach ($m[1] as $loc) { $path = parse_url(trim($loc), PHP_URL_PATH); if ($path) { $sitemap_urls[] = $path; $sitemap_count++; } }
            }
        }
    }
    // Coverage berechnen
    $cached_count = count($cached_paths);
    $uncached = array(); $pagination_issues = array();
    foreach ($sitemap_urls as $url) {
        $normalized = rtrim($url, '/') . '/';
        if (!isset($cached_paths[$normalized])) {
            $uncached[] = $url;
            if (preg_match('/[?&]page=(\d+)/', $url, $pm) || preg_match('/\/page\/(\d+)/', $url, $pm)) {
                $pagination_issues[] = array('url' => $url, 'page_num' => (int)$pm[1]);
            }
        }
    }
    // Kategorien-Analyse
    $cat_coverage = array();
    foreach ($sitemap_urls as $url) {
        $parts = explode('/', trim($url, '/'));
        $cat = !empty($parts[0]) ? $parts[0] : 'root';
        if (!isset($cat_coverage[$cat])) $cat_coverage[$cat] = array('total' => 0, 'cached' => 0);
        $cat_coverage[$cat]['total']++;
        $normalized = rtrim($url, '/') . '/';
        if (isset($cached_paths[$normalized])) $cat_coverage[$cat]['cached']++;
    }
    arsort($cat_coverage);
    return array(
        'sitemap_total' => $sitemap_count, 'cached_total' => $cached_count,
        'coverage_pct' => $sitemap_count > 0 ? round(($cached_count / $sitemap_count) * 100, 1) : 0,
        'uncached_top50' => array_slice($uncached, 0, 50),
        'pagination_issues' => array_slice($pagination_issues, 0, 20),
        'categories' => array_slice($cat_coverage, 0, 20, true),
    );
}

function fpc_get_seo_data($log_dir, $cache_dir) {
    $result = array('bot_requests' => 0, 'bot_hits' => 0, 'bot_misses' => 0, 'bot_hit_rate' => 0, 'bots' => array(), 'bot_top_urls' => array());
    // v9.0.5: Read from daily log files
    $entries = fpc_read_request_logs($log_dir, 2000000, 7);
    foreach ($entries as $r) {
        if (!isset($r['bot']) || !$r['bot']) continue;
        $result['bot_requests']++;
        $bot_name = isset($r['bot_name']) ? $r['bot_name'] : 'Unknown';
        if (!isset($result['bots'][$bot_name])) $result['bots'][$bot_name] = array('requests' => 0, 'hits' => 0);
        $result['bots'][$bot_name]['requests']++;
        if (isset($r['status']) && $r['status'] === 'HIT') { $result['bot_hits']++; $result['bots'][$bot_name]['hits']++; }
        else $result['bot_misses']++;
        $url = isset($r['url']) ? $r['url'] : '';
        if (!isset($result['bot_top_urls'][$url])) $result['bot_top_urls'][$url] = 0;
        $result['bot_top_urls'][$url]++;
    }
    $result['bot_hit_rate'] = $result['bot_requests'] > 0 ? round(($result['bot_hits'] / $result['bot_requests']) * 100, 1) : 0;
    arsort($result['bot_top_urls']);
    $result['bot_top_urls'] = array_slice($result['bot_top_urls'], 0, 20, true);
    return $result;
}

function fpc_get_inspector_data($log_dir, $count, $filter) {
    // v9.0.5: Read from daily log files
    $entries = fpc_read_request_logs($log_dir, 1000000, 3);
    if (empty($entries)) return array('requests' => array(), 'total' => 0);
    $all = array();
    foreach ($entries as $r) {
        if ($filter) {
            if ($filter === 'miss' && (!isset($r['status']) || $r['status'] === 'HIT')) continue;
            if ($filter === 'hit' && (!isset($r['status']) || $r['status'] !== 'HIT')) continue;
            if ($filter === 'bot' && (!isset($r['bot']) || !$r['bot'])) continue;
            if ($filter === 'session' && (!isset($r['reason']) || strpos($r['reason'], 'session') === false)) continue;
            if ($filter === 'error' && (!isset($r['http_code']) || $r['http_code'] < 400)) continue;
        }
        $all[] = $r;
    }
    return array('requests' => array_slice(array_reverse($all), 0, $count), 'total' => count($all));
}

function fpc_get_miss_reasons($log_dir) {
    $reasons = array(); $total_misses = 0;
    // v9.0.5: Read from daily log files
    $entries = fpc_read_request_logs($log_dir, 2000000, 7);
    foreach ($entries as $r) {
        if (!isset($r['status']) || $r['status'] === 'HIT') continue;
        $total_misses++;
        $reason = isset($r['reason']) ? $r['reason'] : 'unknown';
        if (!isset($reasons[$reason])) $reasons[$reason] = 0;
        $reasons[$reason]++;
    }
    arsort($reasons);
    return array('reasons' => $reasons, 'total' => $total_misses);
}

function fpc_get_slowest_pages($log_dir, $limit) {
    $pages = array();
    // v9.0.5: Read from daily log files
    $entries = fpc_read_request_logs($log_dir, 2000000, 7);
    foreach ($entries as $r) {
        if (!isset($r['ttfb']) || !isset($r['url'])) continue;
        $url = $r['url'];
        if (!isset($pages[$url]) || $r['ttfb'] > $pages[$url]) $pages[$url] = $r['ttfb'];
    }
    arsort($pages);
    $result = array();
    foreach (array_slice($pages, 0, $limit, true) as $url => $ttfb) {
        $result[] = array('url' => $url, 'ttfb' => $ttfb);
    }
    return array('pages' => $result);
}

function fpc_get_error_urls($log_dir, $limit) {
    $errors = array();
    // v9.0.5: Read from daily log files
    $entries = fpc_read_request_logs($log_dir, 2000000, 7);
    foreach ($entries as $r) {
        if (!isset($r['http_code']) || $r['http_code'] < 400) continue;
        $key = $r['url'] . '|' . $r['http_code'];
        if (!isset($errors[$key])) $errors[$key] = array('url' => $r['url'], 'code' => $r['http_code'], 'count' => 0, 'last' => '');
        $errors[$key]['count']++;
        $errors[$key]['last'] = date('Y-m-d H:i', $r['ts']);
    }
    usort($errors, function($a, $b) { return $b['count'] - $a['count']; });
    return array('urls' => array_slice($errors, 0, $limit));
}

function fpc_get_alerts_config($file) {
    $defaults = array('hit_rate_min' => 70, 'preloader_stop_alert' => true, 'cache_empty_minutes' => 30, 'error_threshold' => 10, 'email' => '', 'enabled' => false);
    if (!is_file($file)) return $defaults;
    $data = @json_decode(file_get_contents($file), true);
    return $data ? array_merge($defaults, $data) : $defaults;
}

function fpc_get_alerts_history($file) {
    if (!is_file($file)) return array('alerts' => array());
    $data = @json_decode(file_get_contents($file), true);
    return array('alerts' => isset($data['alerts']) ? array_slice($data['alerts'], -50) : array());
}

// v9.0.6: Settings functions - reads/writes DB config + local JSON settings
function fpc_load_settings($cache_dir) {
    $settings = array();
    // 1. DB-based settings (modified eCommerce configuration table)
    $db_keys = array(
        'MODULE_MRHANF_FPC_STATUS' => array('label' => 'Enable Module', 'desc' => 'Should the Full Page Cache be enabled?', 'type' => 'boolean', 'default' => 'True'),
        'MODULE_MRHANF_FPC_CACHE_TIME' => array('label' => 'Cache Lifetime (seconds)', 'desc' => 'How long should a page stay in cache? Default: 86400 (24h)', 'type' => 'number', 'default' => '86400'),
        'MODULE_MRHANF_FPC_EXCLUDED_PAGES' => array('label' => 'Excluded Pages', 'desc' => 'Comma-separated URL parts that should NOT be cached', 'type' => 'text', 'default' => 'checkout,login,account,shopping_cart'),
        'MODULE_MRHANF_FPC_PRELOAD_LIMIT' => array('label' => 'Max Pages per Cron Run', 'desc' => 'Maximum number of pages to cache per cron run', 'type' => 'number', 'default' => '500'),
    );
    $db_settings = array();
    foreach ($db_keys as $key => $meta) {
        $val = defined($key) ? constant($key) : $meta['default'];
        $db_settings[] = array('key' => $key, 'value' => $val, 'label' => $meta['label'], 'desc' => $meta['desc'], 'type' => $meta['type']);
    }
    $settings['db'] = $db_settings;

    // 2. Preloader settings (from fpc_settings.json) - v10.3.0: config_dir
    $settings_file = $cache_dir . 'fpc_settings.json';
    // Migration: alte Datei aus cache/fpc_config/ oder cache/fpc/ uebernehmen
    if (!is_file($settings_file)) {
        $base = str_replace('api/fpc/', '', $cache_dir);
        if (is_file($base . 'cache/fpc_config/fpc_settings.json')) {
            @copy($base . 'cache/fpc_config/fpc_settings.json', $settings_file);
        } elseif (is_file($base . 'cache/fpc/fpc_settings.json')) {
            @copy($base . 'cache/fpc/fpc_settings.json', $settings_file);
        }
    }
    $preloader_defaults = array(
        'request_delay_ms' => 500, 'load_threshold' => 3.0, 'load_pause_sec' => 30,
        'batch_size' => 100, 'batch_pause_sec' => 30, 'slow_threshold_ms' => 3000,
        'max_runtime_sec' => 7200, 'adaptive_enabled' => true, 'min_html_size' => 1000,
        'require_doctype' => true, 'require_body' => true, 'verify_after_write' => true,
        'max_error_rate' => 0.20,
    );
    $preloader = $preloader_defaults;
    if (is_file($settings_file)) {
        $saved = @json_decode(file_get_contents($settings_file), true);
        if (is_array($saved) && isset($saved['preloader'])) {
            $preloader = array_merge($preloader_defaults, $saved['preloader']);
        } elseif (is_array($saved)) {
            // Fallback: alte flache Struktur
            $preloader = array_merge($preloader_defaults, $saved);
        }
    }
    $settings['preloader'] = $preloader;

    // 3. Serve settings (from fpc_settings.json)
    $serve_defaults = array(
        'min_filesize' => 500, 'max_age' => 172800, 'auto_delete' => true, 'request_log' => true,
    );
    $serve = $serve_defaults;
    if (is_file($settings_file)) {
        $saved = @json_decode(file_get_contents($settings_file), true);
        if (is_array($saved) && isset($saved['serve'])) $serve = array_merge($serve_defaults, $saved['serve']);
    }
    $settings['serve'] = $serve;

    // 4. Healthcheck settings
    $hc_defaults = array('max_urls' => 200, 'timeout' => 15, 'max_history' => 90);
    $hc = $hc_defaults;
    if (is_file($settings_file)) {
        $saved = @json_decode(file_get_contents($settings_file), true);
        if (is_array($saved) && isset($saved['healthcheck'])) $hc = array_merge($hc_defaults, $saved['healthcheck']);
    }
    $settings['healthcheck'] = $hc;

    return $settings;
}

function fpc_save_settings($cfg, $cache_dir) {
    if (!is_array($cfg)) return array('ok' => false, 'msg' => 'Invalid data');

    // Save DB settings
    if (!empty($cfg['db']) && is_array($cfg['db'])) {
        foreach ($cfg['db'] as $item) {
            if (!isset($item['key']) || !isset($item['value'])) continue;
            $key = preg_replace('/[^A-Z_]/', '', $item['key']);
            if (strpos($key, 'MODULE_MRHANF_FPC_') !== 0) continue;
            $val = addslashes($item['value']);
            xtc_db_query("UPDATE configuration SET configuration_value = '" . xtc_db_input($val) . "' WHERE configuration_key = '" . xtc_db_input($key) . "'");
        }
    }

    // Save local settings (preloader, serve, healthcheck) - v10.3.0: config_dir
    $settings_file = $cache_dir . 'fpc_settings.json';
    $existing = array();
    if (is_file($settings_file)) {
        $existing = @json_decode(file_get_contents($settings_file), true) ?: array();
    }
    if (isset($cfg['preloader'])) $existing['preloader'] = $cfg['preloader'];
    if (isset($cfg['serve'])) $existing['serve'] = $cfg['serve'];
    if (isset($cfg['healthcheck'])) $existing['healthcheck'] = $cfg['healthcheck'];
    file_put_contents($settings_file, json_encode($existing, JSON_PRETTY_PRINT));

    return array('ok' => true, 'msg' => 'Settings saved successfully');
}

function fpc_get_preloader_status($cache_dir, $pid_file, $log_file, $rebuild_log) {
    $progress = fpc_get_rebuild_progress($cache_dir, $pid_file, $rebuild_log);
    $queue_size = 0; $sitemap_urls = 0;
    // v9.0.5: Sitemap size from /sitemap.xml with sitemapindex support
    $chrome_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $ch = curl_init('https://mr-hanf.de/sitemap.xml');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => $chrome_ua));
    $sm = curl_exec($ch); curl_close($ch);
    if ($sm && strpos($sm, '<sitemapindex') !== false) {
        // Count sub-sitemaps and estimate URLs
        preg_match_all('/<loc>(.*?)<\/loc>/i', $sm, $sm_m);
        $sitemap_urls = count($sm_m[1]) * 7500; // Estimate ~7500 URLs per sub-sitemap
    } elseif ($sm && preg_match_all('/<loc>/i', $sm, $m)) {
        $sitemap_urls = count($m[0]);
    }
    $cached_files = 0;
    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) { if ($f->isFile() && $f->getExtension() === 'html') $cached_files++; }
    }
    return array_merge($progress, array('cached_files' => $cached_files, 'sitemap_urls' => $sitemap_urls, 'queue_size' => max(0, $progress['total'] - $progress['done'])));
}

// ============================================================
// v9.1.0: EXTERNAL INTEGRATIONS (GSC, GA4, SISTRIX)
// ============================================================

function fpc_load_api_credentials($config_dir) {
    if (!is_dir($config_dir)) @mkdir($config_dir, 0755, true);
    $file = $config_dir . 'api_credentials.json';
    $defaults = array(
        'gsc_service_account' => '',
        'gsc_site_url' => 'https://mr-hanf.de/',
        'ga4_service_account' => '',
        'ga4_property_id' => '',
        'sistrix_api_key' => '',
        'sistrix_domain' => 'mr-hanf.de',
        'openai_api_key' => '',
        'openai_model' => 'gpt-4.1-mini',
    );
    // v10.3.1: Migration - alte Datei aus cache/fpc_config/ oder cache/fpc/ uebernehmen
    if (!is_file($file)) {
        $base = str_replace('api/fpc/', '', $config_dir);
        if (is_file($base . 'cache/fpc_config/api_credentials.json')) {
            @copy($base . 'cache/fpc_config/api_credentials.json', $file);
        } elseif (is_file($base . 'cache/fpc/api_credentials.json')) {
            @copy($base . 'cache/fpc/api_credentials.json', $file);
        } else {
            return $defaults;
        }
    }
    $data = @json_decode(file_get_contents($file), true);
    return $data ? array_merge($defaults, $data) : $defaults;
}

function fpc_save_api_credentials($config_dir, $creds) {
    if (!is_dir($config_dir)) @mkdir($config_dir, 0755, true);
    $file = $config_dir . 'api_credentials.json';
    // Only save known keys
    $allowed = array('gsc_service_account','gsc_site_url','ga4_service_account','ga4_property_id','sistrix_api_key','sistrix_domain','openai_api_key','openai_model');
    $save = array();
    foreach ($allowed as $k) {
        $save[$k] = isset($creds[$k]) ? trim($creds[$k]) : '';
    }
    file_put_contents($file, json_encode($save, JSON_PRETTY_PRINT));
    return array('ok' => true, 'msg' => 'API credentials saved');
}

function fpc_get_gsc_data($cache_dir, $base_dir, $days = 28) {
    $config_dir = str_replace('cache/fpc/', 'api/fpc/', $cache_dir);
    $creds = fpc_load_api_credentials($config_dir);
    $sa_file = $creds['gsc_service_account'];
    if (empty($sa_file) || !is_file($base_dir . $sa_file)) {
        return array('error' => true, 'msg' => 'Google Service Account JSON not configured. Go to Settings > API Credentials to set the path.', 'configured' => false);
    }
    $allowed_days = array(7, 28, 90, 180, 365, 480);
    if (!in_array($days, $allowed_days)) $days = 28;
    try {
        require_once($base_dir . 'fpc_gsc.php');
        $gsc = new FPC_GoogleSearchConsole($base_dir . $sa_file, $creds['gsc_site_url'], $cache_dir . 'gsc/');
        $data = $gsc->getDashboardData($days);
        $data['configured'] = true;
        return $data;
    } catch (Exception $e) {
        return array('error' => true, 'msg' => 'GSC Error: ' . $e->getMessage(), 'configured' => true);
    }
}

function fpc_get_gsc_inspection($cache_dir, $base_dir, $urls) {
    $config_dir = str_replace('cache/fpc/', 'api/fpc/', $cache_dir);
    $creds = fpc_load_api_credentials($config_dir);
    $sa_file = $creds['gsc_service_account'];
    if (empty($sa_file) || !is_file($base_dir . $sa_file)) {
        return array('error' => true, 'msg' => 'GSC not configured');
    }
    try {
        require_once($base_dir . 'fpc_gsc.php');
        $gsc = new FPC_GoogleSearchConsole($base_dir . $sa_file, $creds['gsc_site_url'], $cache_dir . 'gsc/');
        return $gsc->getInspectionData($urls);
    } catch (Exception $e) {
        return array('error' => true, 'msg' => 'Inspection Error: ' . $e->getMessage());
    }
}

function fpc_get_ga4_data($cache_dir, $base_dir, $days = 30) {
    $config_dir = str_replace('cache/fpc/', 'api/fpc/', $cache_dir);
    $creds = fpc_load_api_credentials($config_dir);
    $sa_file = $creds['ga4_service_account'];
    $prop_id = $creds['ga4_property_id'];
    if (empty($sa_file) || empty($prop_id) || !is_file($base_dir . $sa_file)) {
        return array('error' => true, 'msg' => 'Google Analytics 4 not configured. Go to Settings > API Credentials to set Service Account path and Property ID.', 'configured' => false);
    }
    $allowed_days = array(7, 28, 30, 90, 180, 365);
    if (!in_array($days, $allowed_days)) $days = 30;
    try {
        require_once($base_dir . 'fpc_ga4.php');
        $ga4 = new FPC_GoogleAnalytics4($base_dir . $sa_file, $prop_id, $cache_dir . 'ga4/');
        $data = $ga4->getDashboardData($days);
        $data['configured'] = true;
        return $data;
    } catch (Exception $e) {
        return array('error' => true, 'msg' => 'GA4 Error: ' . $e->getMessage(), 'configured' => true);
    }
}

function fpc_get_sistrix_data($cache_dir, $base_dir) {
    $config_dir = str_replace('cache/fpc/', 'api/fpc/', $cache_dir);
    $creds = fpc_load_api_credentials($config_dir);
    $api_key = $creds['sistrix_api_key'];
    if (empty($api_key)) {
        return array('error' => true, 'msg' => 'SISTRIX API key not configured. Go to Settings > API Credentials to set your API key.', 'configured' => false);
    }
    try {
        require_once($base_dir . 'fpc_sistrix.php');
        $sx = new FPC_Sistrix($api_key, $creds['sistrix_domain'], $cache_dir . 'sistrix/');
        $data = $sx->getDashboardData();
        $data['configured'] = true;
        return $data;
    } catch (Exception $e) {
        return array('error' => true, 'msg' => 'SISTRIX Error: ' . $e->getMessage(), 'configured' => true);
    }
}

// ============================================================
// SEITENAUSGABE (HTML)
// ============================================================
$page_title = 'FPC Control Center';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $page_title; ?> v9.1.5</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4" defer></script>
<style>
:root { --fpc-bg:#0d1b2a; --fpc-card:#1b2838; --fpc-border:#2a3a4a; --fpc-text:#e0e6ed; --fpc-text2:#8899aa; --fpc-teal:#00d4aa; --fpc-green:#00e676; --fpc-red:#ff4757; --fpc-orange:#ffa502; --fpc-yellow:#ffd32a; --fpc-blue:#00a8ff; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--fpc-bg); color: var(--fpc-text); font-size: 14px; line-height: 1.5; }
.fpc-header { background: linear-gradient(135deg, #0d1b2a 0%, #1b2838 100%); padding: 16px 24px; border-bottom: 2px solid var(--fpc-teal); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; position: sticky; top: 0; z-index: 100; }
.fpc-header h1 { font-size: 20px; font-weight: 700; color: var(--fpc-teal); }
.fpc-header h1 span { font-size: 11px; color: var(--fpc-text2); font-weight: 400; }
.fpc-quick-actions { display: flex; gap: 6px; }
.fpc-quick-btn { background: var(--fpc-card); border: 1px solid var(--fpc-border); color: var(--fpc-text); padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s; }
.fpc-quick-btn:hover { border-color: var(--fpc-teal); color: var(--fpc-teal); }
.fpc-version { color: var(--fpc-text2); font-size: 11px; }
.fpc-tabs { display: flex; flex-wrap: wrap; gap: 2px; padding: 8px 24px; background: #0a1420; border-bottom: 1px solid var(--fpc-border); position: sticky; top: 58px; z-index: 99; }
.fpc-tab { padding: 8px 14px; border-radius: 6px 6px 0 0; cursor: pointer; font-size: 12px; font-weight: 600; color: var(--fpc-text2); background: transparent; border: none; transition: all 0.2s; white-space: nowrap; }
.fpc-tab:hover { color: var(--fpc-teal); background: rgba(0,212,170,0.05); }
.fpc-tab.active { color: var(--fpc-teal); background: var(--fpc-card); border-bottom: 2px solid var(--fpc-teal); }
.fpc-content { padding: 20px 24px; width: 100%; }
.fpc-panel { display: none; }
.fpc-panel.active { display: block; }
.fpc-kpis { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px; }
.fpc-kpi { background: var(--fpc-card); border-radius: 10px; padding: 16px; border: 1px solid var(--fpc-border); }
.fpc-kpi-label { font-size: 11px; color: var(--fpc-text2); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.fpc-kpi-value { font-size: 28px; font-weight: 700; }
.fpc-kpi-sub { font-size: 11px; color: var(--fpc-text2); margin-top: 2px; }
.fpc-section-title { font-size: 16px; font-weight: 700; color: var(--fpc-text); margin: 24px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--fpc-border); }
.fpc-charts { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 16px; margin-bottom: 20px; }
.fpc-chart-box { background: var(--fpc-card); border-radius: 10px; padding: 16px; border: 1px solid var(--fpc-border); min-height: 200px; max-height: 350px; overflow: hidden; }
.fpc-chart-box canvas { max-height: 280px !important; }
.fpc-chart-box h3 { font-size: 13px; color: var(--fpc-text2); margin-bottom: 10px; }
.fpc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.fpc-table th { background: #0a1420; color: var(--fpc-text2); padding: 8px 12px; text-align: left; font-weight: 600; font-size: 11px; text-transform: uppercase; position: sticky; top: 0; }
.fpc-table td { padding: 6px 12px; border-bottom: 1px solid var(--fpc-border); }
.fpc-table tr:hover { background: rgba(0,212,170,0.03); }
.fpc-btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px; }
.fpc-btn.green { background: var(--fpc-green); color: #000; }
.fpc-btn.red { background: var(--fpc-red); color: #fff; }
.fpc-btn.teal { background: var(--fpc-teal); color: #000; }
.fpc-btn.orange { background: var(--fpc-orange); color: #000; }
.fpc-btn.blue { background: var(--fpc-blue); color: #fff; }
.fpc-btn:hover { opacity: 0.85; transform: translateY(-1px); }
.fpc-input { background: #0a1420; border: 1px solid var(--fpc-border); color: var(--fpc-text); padding: 8px 12px; border-radius: 6px; font-size: 13px; width: 100%; }
.fpc-input:focus { outline: none; border-color: var(--fpc-teal); }
.fpc-input-group { display: flex; gap: 8px; margin-bottom: 12px; }
.fpc-input-group .fpc-input { flex: 1; }
.fpc-log-box { background: #0a1420; border-radius: 8px; padding: 12px; font-family: 'Fira Code', monospace, monospace; font-size: 12px; color: var(--fpc-text2); max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; border: 1px solid var(--fpc-border); }
.fpc-toast { position: fixed; bottom: 20px; right: 20px; background: var(--fpc-card); border: 1px solid var(--fpc-teal); color: var(--fpc-text); padding: 12px 20px; border-radius: 8px; z-index: 9999; animation: slideIn 0.3s ease; max-width: 400px; }
.fpc-toast.error { border-color: var(--fpc-red); }
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
.fpc-ampel { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 6px; }
.fpc-ampel.green { background: var(--fpc-green); box-shadow: 0 0 8px var(--fpc-green); }
.fpc-ampel.yellow { background: var(--fpc-yellow); box-shadow: 0 0 8px var(--fpc-yellow); }
.fpc-ampel.red { background: var(--fpc-red); box-shadow: 0 0 8px var(--fpc-red); }
.fpc-progress-wrap { background: var(--fpc-card); border-radius: 10px; padding: 20px; margin-bottom: 20px; border: 1px solid var(--fpc-border); display: none; }
.fpc-progress-wrap.active { display: block; }
.fpc-progress-bar-outer { background: #1a2736; border-radius: 8px; height: 28px; overflow: hidden; position: relative; margin: 12px 0; }
.fpc-progress-bar-inner { background: linear-gradient(90deg, var(--fpc-teal), var(--fpc-green)); height: 100%; border-radius: 8px; transition: width 0.5s ease; }
.fpc-progress-bar-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 13px; font-weight: 700; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.5); }
.fpc-progress-info { display: flex; justify-content: space-between; color: var(--fpc-text2); font-size: 12px; margin-top: 8px; }
.fpc-progress-log { background: #0a1018; border-radius: 6px; padding: 10px; font-family: monospace; font-size: 11px; color: var(--fpc-text2); max-height: 80px; overflow-y: auto; margin-top: 10px; white-space: pre-wrap; }
.fpc-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; }
.fpc-badge.hit { background: var(--fpc-green); color: #000; }
.fpc-badge.miss { background: var(--fpc-red); color: #fff; }
.fpc-badge.bypass { background: var(--fpc-orange); color: #000; }
.fpc-badge.bot { background: var(--fpc-blue); color: #fff; }
.fpc-layer-flow { display: flex; align-items: center; gap: 8px; padding: 16px; background: var(--fpc-card); border-radius: 10px; margin: 16px 0; flex-wrap: wrap; }
.fpc-layer { background: #0a1420; padding: 12px 20px; border-radius: 8px; border: 1px solid var(--fpc-border); text-align: center; min-width: 100px; }
.fpc-layer.active { border-color: var(--fpc-green); box-shadow: 0 0 10px rgba(0,230,118,0.2); }
.fpc-layer-arrow { color: var(--fpc-teal); font-size: 20px; }
.sev-critical { color: #fff; background: var(--fpc-red); padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.sev-error { color: #fff; background: #e84118; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.sev-warning { color: #000; background: var(--fpc-orange); padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.sev-notice { color: #000; background: var(--fpc-yellow); padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.sev-ok { color: #000; background: var(--fpc-green); padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.fpc-pagination { display: flex; gap: 4px; margin-top: 12px; flex-wrap: wrap; justify-content: center; }
.fpc-pagination button { background: var(--fpc-card); border: 1px solid var(--fpc-border); color: var(--fpc-text); padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; }
.fpc-pagination button.active { background: var(--fpc-teal); color: #000; border-color: var(--fpc-teal); }
.fpc-pagination button:hover:not(.active) { border-color: var(--fpc-teal); }
.fpc-pagination .ellipsis { padding: 4px 6px; color: var(--fpc-text2); }
@media (max-width: 768px) { .fpc-kpis { grid-template-columns: repeat(2, 1fr); } .fpc-charts { grid-template-columns: 1fr; } .fpc-tabs { gap: 2px; } .fpc-tab { padding: 6px 8px; font-size: 11px; } }
</style>
</head>
<body>

<!-- HEADER -->
<div class="fpc-header">
    <h1>FPC Control Center <span>v9.1.5</span></h1>
    <div class="fpc-quick-actions">
        <button class="fpc-quick-btn" onclick="fpcFlush()" title="Flush Cache">&#128465; Flush</button>
        <button class="fpc-quick-btn" onclick="fpcRebuild()" title="Rebuild Cache">&#8635; Rebuild</button>
        <button class="fpc-quick-btn" onclick="fpcExportUrls()" title="CSV Export">&#128190; Export</button>
    </div>
    <div>
        <span id="fpc-clock" style="color:var(--fpc-text2);font-size:12px;"></span>
        <span class="fpc-version">v9.1.5</span>
    </div>
</div>

<!-- TAB-NAVIGATION -->
<div class="fpc-tabs">
    <?php
    $tab_labels = array(
        'dashboard' => '&#9632; Dashboard', 'performance' => '&#9889; Performance', 'coverage' => '&#127760; Coverage',
        'steuerung' => '&#9881; Control', 'urls' => '&#128279; URLs', 'preloader' => '&#128640; Preloader',
        'fehler' => '&#9888; Errors', 'seo' => '&#128270; SEO', 'inspector' => '&#128269; Inspector',
        'health' => '&#128154; Health', 'statistik' => '&#128200; Statistics', 'alerts' => '&#128276; Alerts',
        'settings' => '&#9881; Settings',
        'gsc' => '&#128270; GSC',
        'analytics' => '&#128200; Analytics',
        'sistrix' => '&#128202; SISTRIX',
    );
    foreach ($tab_labels as $key => $label) {
        $cls = ($active_tab === $key) ? 'fpc-tab active' : 'fpc-tab';
        echo '<a href="?tab=' . $key . '" class="' . $cls . '" style="text-decoration:none;">' . $label . '</a>';
    }
    ?>
</div>

<div class="fpc-content">

<!-- ========== TAB 1: DASHBOARD ========== -->
<div class="fpc-panel <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" id="panel-dashboard">
    <div class="fpc-kpis" id="dash-kpis"></div>
    <div class="fpc-charts">
        <div class="fpc-chart-box"><h3>Cache Distribution by Category</h3><canvas id="chart-categories" height="200"></canvas></div>
        <div class="fpc-chart-box"><h3>Hit/Miss Last 24h</h3><canvas id="chart-hitmiss-24h" height="200"></canvas></div>
    </div>
    <div class="fpc-charts">
        <div class="fpc-chart-box"><h3>OPCache Status</h3><div id="dash-opcache"></div></div>
        <div class="fpc-chart-box"><h3>Last Preloader Run</h3><div id="dash-preloader"></div></div>
    </div>
</div>

<!-- ========== TAB 2: PERFORMANCE ========== -->
<div class="fpc-panel <?php echo $active_tab === 'performance' ? 'active' : ''; ?>" id="panel-performance">
    <div class="fpc-kpis" id="perf-kpis"></div>
    <div class="fpc-charts">
        <div class="fpc-chart-box"><h3>Hit vs Miss vs Bypass (Total)</h3><canvas id="chart-perf-pie" height="200"></canvas></div>
        <div class="fpc-chart-box"><h3>TTFB Comparison</h3><canvas id="chart-perf-ttfb" height="200"></canvas></div>
    </div>
    <div class="fpc-charts">
        <div class="fpc-chart-box"><h3>Requests per Hour (Today)</h3><canvas id="chart-perf-hourly" height="200"></canvas></div>
        <div class="fpc-chart-box"><h3>Daily Hit/Miss Trend (30 Days)</h3><canvas id="chart-perf-timeline" height="200"></canvas></div>
    </div>
</div>

<!-- ========== TAB 3: COVERAGE ========== -->
<div class="fpc-panel <?php echo $active_tab === 'coverage' ? 'active' : ''; ?>" id="panel-coverage">
    <div class="fpc-kpis" id="cov-kpis"></div>
    <div class="fpc-section-title">Category Coverage</div>
    <div id="cov-categories"></div>
    <div class="fpc-section-title">Pagination Issues (Page 2+ not cached)</div>
    <div id="cov-pagination"></div>
    <div class="fpc-section-title">Top 50 Uncached URLs</div>
    <div id="cov-uncached"></div>
</div>

<!-- ========== TAB 4: STEUERUNG ========== -->
<div class="fpc-panel <?php echo $active_tab === 'steuerung' ? 'active' : ''; ?>" id="panel-steuerung">
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <button class="fpc-btn green" onclick="fpcRebuild()">&#8635; Rebuild Cache</button>
        <button class="fpc-btn red" onclick="fpcFlush()">&#128465; Flush Entire Cache</button>
        <button class="fpc-btn orange" onclick="fpcStopRebuild()">&#9632; Stop Rebuild</button>
    </div>
    <div class="fpc-progress-wrap" id="rebuild-progress">
        <strong>Rebuild Progress</strong>
        <div class="fpc-progress-bar-outer">
            <div class="fpc-progress-bar-inner" id="rebuild-bar" style="width:0%"></div>
            <div class="fpc-progress-bar-text" id="rebuild-pct">0%</div>
        </div>
        <div class="fpc-progress-info">
            <span id="rebuild-done">0 / 0 URLs</span>
            <span id="rebuild-errors">Errors: 0 | Skipped: 0</span>
        </div>
        <div class="fpc-progress-log" id="rebuild-log"></div>
    </div>
    <div class="fpc-section-title">Cache Single URL</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:8px;">Enter a URL or path (e.g. /samen-shop/autoflowering-samen/) to immediately add this page to the cache.</p>
    <div class="fpc-input-group">
        <input type="text" class="fpc-input" id="cache-url-input" placeholder="/samen-shop/autoflowering-samen/">
        <button class="fpc-btn teal" onclick="fpcCacheUrl()">Cache</button>
    </div>
    <div id="cache-url-result" style="margin-bottom:20px;"></div>
    <div class="fpc-section-title">Custom URLs for Preloader</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:8px;">URLs not in the sitemap that should still be cached.</p>
    <div class="fpc-input-group">
        <input type="text" class="fpc-input" id="custom-url-input" placeholder="/blog/mein-artikel/">
        <button class="fpc-btn teal" onclick="fpcAddCustomUrl()">Add</button>
    </div>
    <div id="custom-urls-list"></div>
</div>

<!-- ========== TAB 5: URLS ========== -->
<div class="fpc-panel <?php echo $active_tab === 'urls' ? 'active' : ''; ?>" id="panel-urls">
    <div class="fpc-input-group">
        <input type="text" class="fpc-input" id="url-search" placeholder="Search URLs..." onkeyup="if(event.key==='Enter')fpcLoadUrls(1)">
        <button class="fpc-btn teal" onclick="fpcLoadUrls(1)">Search</button>
        <button class="fpc-btn blue" onclick="fpcExportUrls()">CSV Export</button>
    </div>
    <div id="urls-table"></div>
    <div class="fpc-pagination" id="urls-pagination"></div>
</div>

<!-- ========== TAB 6: PRELOADER ========== -->
<div class="fpc-panel <?php echo $active_tab === 'preloader' ? 'active' : ''; ?>" id="panel-preloader">
    <div class="fpc-kpis" id="preloader-kpis"></div>
    <div class="fpc-progress-wrap active" id="preloader-progress">
        <strong>Preloader Progress</strong>
        <div class="fpc-progress-bar-outer">
            <div class="fpc-progress-bar-inner" id="preloader-bar" style="width:0%"></div>
            <div class="fpc-progress-bar-text" id="preloader-pct">0%</div>
        </div>
        <div class="fpc-progress-info">
            <span id="preloader-done">0 / 0 URLs</span>
            <span id="preloader-speed">0 URLs/sec</span>
        </div>
        <div class="fpc-progress-log" id="preloader-log"></div>
    </div>
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <button class="fpc-btn green" onclick="fpcRebuild()">&#128640; Start Preloader</button>
        <button class="fpc-btn orange" onclick="fpcStopRebuild()">&#9632; Stop</button>
    </div>
    <div class="fpc-section-title">Preloader Log</div>
    <div class="fpc-log-box" id="preloader-full-log" style="max-height:300px;"></div>
</div>

<!-- ========== TAB 7: FEHLER ========== -->
<div class="fpc-panel <?php echo $active_tab === 'fehler' ? 'active' : ''; ?>" id="panel-fehler">
    <div class="fpc-section-title">Most Common Cache-Miss Reasons</div>
    <div id="fehler-reasons"></div>
    <div class="fpc-section-title">Top Error URLs (HTTP 4xx/5xx)</div>
    <div id="fehler-urls"></div>
    <div class="fpc-section-title">Slowest Pages (Top 20)</div>
    <div id="fehler-slowest"></div>
</div>

<!-- ========== TAB 8: SEO ========== -->
<div class="fpc-panel <?php echo $active_tab === 'seo' ? 'active' : ''; ?>" id="panel-seo">

    <!-- SEO HEALTH SCORE BANNER -->
    <div id="seo-health-banner" style="background:var(--fpc-card);border-radius:12px;padding:24px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
            <div id="seo-health-score" style="width:120px;height:120px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:bold;border:4px solid var(--fpc-teal);color:var(--fpc-teal);">--</div>
            <div style="flex:1;min-width:200px;">
                <h2 style="color:var(--fpc-text);margin:0 0 8px 0;">SEO Health Score</h2>
                <div id="seo-health-alerts" style="font-size:13px;color:var(--fpc-text2);">Lade Daten...</div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="fpc-btn green" onclick="fpcSeoRunScan('fast')">&#9654; Quick Scan (100 URLs)</button>
                <button class="fpc-btn teal" onclick="fpcSeoRunScan('full')">&#128269; Full Scan</button>
                <button class="fpc-btn blue" onclick="fpcSeoExportCsv()">&#128190; Export CSV</button>
            </div>
        </div>
    </div>

    <!-- SEO KPIs -->
    <div class="fpc-kpis" id="seo-kpis"></div>

    <!-- HEALTH TREND CHART -->
    <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <h3 style="color:var(--fpc-text);margin:0 0 12px 0;">Health Score Trend</h3>
        <canvas id="chart-seo-health-trend" height="80"></canvas>
    </div>

    <!-- KI ANALYSE + CHAT -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
        <!-- KI Analyse -->
        <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);">
            <h3 style="color:var(--fpc-purple);margin:0 0 12px 0;">&#129302; KI-Analyse (OpenAI)</h3>
            <div id="seo-ai-status" style="font-size:12px;color:var(--fpc-text2);margin-bottom:12px;"></div>
            <div style="display:flex;gap:8px;margin-bottom:12px;">
                <button class="fpc-btn purple" onclick="fpcSeoAiAnalysis(false)" id="btn-ai-analyze">&#9889; Analyse starten</button>
                <button class="fpc-btn orange" onclick="fpcSeoAiAnalysis(true)">&#8635; Neu analysieren</button>
            </div>
            <div id="seo-ai-result" style="max-height:400px;overflow-y:auto;font-size:13px;color:var(--fpc-text2);"></div>
        </div>
        <!-- KI Chat -->
        <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <h3 style="color:var(--fpc-blue);margin:0;">&#128172; SEO Chat</h3>
                <button class="fpc-btn red" onclick="fpcSeoChatClear()" style="font-size:11px;padding:4px 8px;">Chat leeren</button>
            </div>
            <div id="seo-chat-messages" style="flex:1;max-height:350px;overflow-y:auto;margin-bottom:12px;font-size:13px;"></div>
            <div style="display:flex;gap:8px;">
                <input type="text" class="fpc-input" id="seo-chat-input" placeholder="Stelle eine SEO-Frage..." style="flex:1;" onkeypress="if(event.key==='Enter')fpcSeoChatSend()">
                <button class="fpc-btn blue" onclick="fpcSeoChatSend()">Senden</button>
            </div>
        </div>
    </div>

    <!-- CROSS-API PROBLEME -->
    <div class="fpc-section-title" style="color:var(--fpc-red);">&#9888; Erkannte Probleme (Cross-API)</div>
    <div id="seo-problems" style="margin-bottom:20px;"></div>

    <!-- REDIRECTS -->
    <div class="fpc-section-title">&#8594; Redirect Manager</div>
    <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:end;">
            <div style="flex:1;min-width:200px;"><label style="color:var(--fpc-text2);font-size:11px;display:block;margin-bottom:4px;">Source URL</label><input type="text" class="fpc-input" id="redir-source" placeholder="/alte-seite/" style="width:100%;"></div>
            <div style="flex:1;min-width:200px;"><label style="color:var(--fpc-text2);font-size:11px;display:block;margin-bottom:4px;">Target URL</label><input type="text" class="fpc-input" id="redir-target" placeholder="/neue-seite/" style="width:100%;"></div>
            <div style="width:80px;"><label style="color:var(--fpc-text2);font-size:11px;display:block;margin-bottom:4px;">Typ</label><select class="fpc-input" id="redir-type" style="width:100%;"><option value="301">301</option><option value="302">302</option><option value="307">307</option><option value="410">410</option></select></div>
            <div style="width:60px;"><label style="color:var(--fpc-text2);font-size:11px;display:block;margin-bottom:4px;">Regex</label><input type="checkbox" id="redir-regex"></div>
            <div style="flex:1;min-width:150px;"><label style="color:var(--fpc-text2);font-size:11px;display:block;margin-bottom:4px;">Notiz</label><input type="text" class="fpc-input" id="redir-note" placeholder="Optional" style="width:100%;"></div>
            <button class="fpc-btn green" onclick="fpcSeoRedirectAdd()">+ Hinzufuegen</button>
        </div>
        <div style="margin-bottom:8px;"><input type="text" class="fpc-input" id="redir-search" placeholder="Redirects durchsuchen..." oninput="fpcSeoLoadRedirects()" style="width:300px;"></div>
        <div id="seo-redirects-table"></div>
    </div>

    <!-- CANONICAL OVERRIDES -->
    <div class="fpc-section-title">&#128279; Canonical Overrides</div>
    <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:end;">
            <div style="flex:1;min-width:200px;"><label style="color:var(--fpc-text2);font-size:11px;display:block;margin-bottom:4px;">Seiten-URL</label><input type="text" class="fpc-input" id="canon-page" placeholder="/seite/" style="width:100%;"></div>
            <div style="flex:1;min-width:200px;"><label style="color:var(--fpc-text2);font-size:11px;display:block;margin-bottom:4px;">Canonical URL</label><input type="text" class="fpc-input" id="canon-url" placeholder="https://mr-hanf.de/richtige-seite/" style="width:100%;"></div>
            <div style="flex:1;min-width:150px;"><label style="color:var(--fpc-text2);font-size:11px;display:block;margin-bottom:4px;">Notiz</label><input type="text" class="fpc-input" id="canon-note" placeholder="Optional" style="width:100%;"></div>
            <button class="fpc-btn green" onclick="fpcSeoCanonicalAdd()">+ Hinzufuegen</button>
        </div>
        <div id="seo-canonicals-table"></div>
    </div>

    <!-- 404 LOG -->
    <div class="fpc-section-title" style="color:var(--fpc-orange);">&#128683; 404 Fehler-Log</div>
    <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <div style="display:flex;gap:8px;margin-bottom:12px;">
            <button class="fpc-btn orange active" onclick="fpcSeoLoad404('unresolved')" id="btn-404-unresolved">Offen</button>
            <button class="fpc-btn teal" onclick="fpcSeoLoad404('resolved')" id="btn-404-resolved">Geloest</button>
            <button class="fpc-btn red" onclick="fpcSeoLoad404('dismissed')" id="btn-404-dismissed">Ignoriert</button>
            <input type="text" class="fpc-input" id="404-search" placeholder="404 URLs suchen..." oninput="fpcSeoLoad404()" style="width:250px;margin-left:auto;">
            <button class="fpc-btn" style="background:var(--fpc-text2);font-size:11px;" onclick="fpcSeo404Cleanup()" title="System-URLs (fpc_serve.php, index.php etc.) aus dem Log entfernen">&#128465; Bereinigen</button>
        </div>
        <div id="seo-404-table"></div>
    </div>

    <!-- SCAN ERGEBNISSE -->
    <div class="fpc-section-title">&#128269; Scan-Ergebnisse</div>
    <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <!-- Status-Filter -->
        <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;align-items:center;">
            <button class="fpc-btn teal active" onclick="fpcSeoLoadScanResults('')">Alle</button>
            <button class="fpc-btn green" onclick="fpcSeoLoadScanResults('ok')">OK</button>
            <button class="fpc-btn orange" onclick="fpcSeoLoadScanResults('warning')">Warnings</button>
            <button class="fpc-btn red" onclick="fpcSeoLoadScanResults('error')">Errors</button>
            <button class="fpc-btn blue" onclick="fpcSeoLoadScanResults('redirect')">Redirects</button>
            <span style="color:var(--fpc-text2);font-size:11px;margin-left:8px;">|</span>
            <!-- v10.4.0: Dateityp-Filter -->
            <button class="fpc-btn teal" style="font-size:11px;padding:4px 8px;" onclick="fpcSeoSetTypeFilter('pages')" id="btn-type-pages" title="Nur Shop-Seiten (HTML)">Seiten</button>
            <button class="fpc-btn" style="font-size:11px;padding:4px 8px;background:var(--fpc-card2);" onclick="fpcSeoSetTypeFilter('all')" id="btn-type-all" title="Alle URLs anzeigen">Alle Typen</button>
            <button class="fpc-btn" style="font-size:11px;padding:4px 8px;background:var(--fpc-card2);" onclick="fpcSeoSetTypeFilter('assets')" id="btn-type-assets" title="Nur Assets (PDF, Bilder, CSS, JS)">Assets</button>
            <input type="text" class="fpc-input" id="scan-search" placeholder="URLs suchen..." oninput="fpcSeoLoadScanResults()" style="width:200px;margin-left:auto;">
        </div>
        <!-- v10.4.0: Sprach-Filter + KI-Aktionen -->
        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
            <span style="color:var(--fpc-text2);font-size:11px;">Sprache:</span>
            <button class="fpc-btn teal" style="font-size:11px;padding:3px 7px;" onclick="fpcSeoSetLangFilter('')" id="btn-lang-all">Alle</button>
            <button class="fpc-btn" style="font-size:11px;padding:3px 7px;background:var(--fpc-card2);" onclick="fpcSeoSetLangFilter('de')" id="btn-lang-de">DE</button>
            <button class="fpc-btn" style="font-size:11px;padding:3px 7px;background:var(--fpc-card2);" onclick="fpcSeoSetLangFilter('en')" id="btn-lang-en">EN</button>
            <button class="fpc-btn" style="font-size:11px;padding:3px 7px;background:var(--fpc-card2);" onclick="fpcSeoSetLangFilter('fr')" id="btn-lang-fr">FR</button>
            <button class="fpc-btn" style="font-size:11px;padding:3px 7px;background:var(--fpc-card2);" onclick="fpcSeoSetLangFilter('es')" id="btn-lang-es">ES</button>
            <button class="fpc-btn" style="font-size:11px;padding:3px 7px;background:var(--fpc-card2);" onclick="fpcSeoSetLangFilter('nl')" id="btn-lang-nl">NL</button>
            <button class="fpc-btn" style="font-size:11px;padding:3px 7px;background:var(--fpc-card2);" onclick="fpcSeoSetLangFilter('it')" id="btn-lang-it">IT</button>
            <span style="color:var(--fpc-text2);font-size:11px;margin-left:12px;">|</span>
            <button class="fpc-btn" style="font-size:11px;padding:4px 10px;background:var(--fpc-card2);" onclick="fpcSeoToggleGroupView()" id="btn-group-toggle" title="URLs nach Basis-Pfad gruppieren (alle Sprachen zusammen)">&#127760; Gruppiert</button>
            <button class="fpc-btn" style="font-size:11px;padding:4px 10px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;" onclick="fpcSeoAiSuggestRedirects()" id="btn-ai-suggest" title="KI analysiert problematische URLs und schlaegt Redirects vor">&#129302; KI Redirect-Vorschlaege</button>
            <span id="scan-result-count" style="color:var(--fpc-text2);font-size:11px;margin-left:auto;"></span>
        </div>
        <div id="seo-scan-table"></div>
        <!-- v10.4.0: KI-Vorschlaege Container -->
        <div id="seo-ai-suggestions" style="display:none;margin-top:16px;"></div>
    </div>

    <!-- FILE EDITORS (.htaccess + robots.txt) -->
    <div class="fpc-section-title">&#128221; Datei-Editor</div>
    <div style="display:flex;gap:8px;margin-bottom:12px;">
        <button class="fpc-btn teal active" onclick="fpcFileEditorLoad('htaccess')" id="btn-file-htaccess">.htaccess</button>
        <button class="fpc-btn blue" onclick="fpcFileEditorLoad('robots')" id="btn-file-robots">robots.txt</button>
    </div>
    <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div>
                <span id="file-editor-name" style="color:var(--fpc-text);font-weight:bold;font-size:16px;">.htaccess</span>
                <span id="file-editor-meta" style="color:var(--fpc-text2);font-size:12px;margin-left:12px;"></span>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="fpc-btn green" onclick="fpcFileEditorSave()">&#128190; Speichern</button>
                <button class="fpc-btn orange" onclick="fpcFileEditorShowBackups()">&#128337; Backups</button>
                <button class="fpc-btn red" onclick="fpcFileEditorReload()">&#8635; Neu laden</button>
            </div>
        </div>
        <div id="file-editor-warning" style="display:none;background:rgba(255,165,0,0.15);border:1px solid var(--fpc-orange);border-radius:6px;padding:10px;margin-bottom:12px;color:var(--fpc-orange);font-size:12px;">
            &#9888; Vorsicht! Fehlerhafte Aenderungen an .htaccess koennen die Website unzugaenglich machen. Ein Backup wird automatisch erstellt.
        </div>
        <textarea id="file-editor-content" style="width:100%;min-height:400px;background:var(--fpc-bg);color:var(--fpc-green);border:1px solid var(--fpc-border);border-radius:6px;padding:12px;font-family:'Courier New',Consolas,monospace;font-size:13px;line-height:1.5;resize:vertical;tab-size:4;" spellcheck="false" placeholder="Datei wird geladen..."></textarea>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
            <span id="file-editor-lines" style="color:var(--fpc-text2);font-size:11px;"></span>
            <span id="file-editor-status" style="font-size:12px;"></span>
        </div>
        <!-- Backup Liste -->
        <div id="file-editor-backups" style="display:none;margin-top:16px;border-top:1px solid var(--fpc-border);padding-top:12px;">
            <h4 style="color:var(--fpc-text);margin:0 0 8px 0;">Backups</h4>
            <div id="file-editor-backups-list"></div>
        </div>
    </div>

    <!-- BOT DATA (original SEO tab content) -->
    <div class="fpc-section-title">&#129302; Bot-Analyse (FPC Request Log)</div>
    <div class="fpc-charts">
        <div class="fpc-chart-box"><h3>Bot Requests by Crawler</h3><canvas id="chart-seo-bots" height="200"></canvas></div>
        <div class="fpc-chart-box"><h3>Bot Hit Rate</h3><canvas id="chart-seo-hitrate" height="200"></canvas></div>
    </div>
    <div class="fpc-section-title">Top URLs Requested by Bots</div>
    <div id="seo-top-urls"></div>
</div>

<!-- ========== TAB 9: INSPECTOR ========== -->
<div class="fpc-panel <?php echo $active_tab === 'inspector' ? 'active' : ''; ?>" id="panel-inspector">
    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
        <button class="fpc-btn teal" onclick="fpcLoadInspector('')">All</button>
        <button class="fpc-btn red" onclick="fpcLoadInspector('miss')">MISS Only</button>
        <button class="fpc-btn green" onclick="fpcLoadInspector('hit')">HIT Only</button>
        <button class="fpc-btn blue" onclick="fpcLoadInspector('bot')">Bots Only</button>
        <button class="fpc-btn orange" onclick="fpcLoadInspector('session')">Session Leakage</button>
        <button class="fpc-btn red" onclick="fpcLoadInspector('error')">Errors (4xx/5xx)</button>
    </div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:12px;">Live Request Inspector - Shows why pages were NOT cached. Data comes from the request log (fpc_serve.php logs every request).</p>
    <div id="inspector-table"></div>
</div>

<!-- ========== TAB 10: HEALTH ========== -->
<div class="fpc-panel <?php echo $active_tab === 'health' ? 'active' : ''; ?>" id="panel-health">
    <div style="display:flex;gap:12px;margin-bottom:20px;">
        <button class="fpc-btn green" onclick="fpcRunHealthcheck()">&#9654; Run Check Now</button>
        <button class="fpc-btn teal" onclick="fpcLoadHealth()">&#8635; Refresh Data</button>
    </div>
    <div id="health-score-box" style="margin-bottom:20px;"></div>
    <div class="fpc-section-title">Technical Layer Overview</div>
    <div class="fpc-layer-flow" id="health-layers">
        <div class="fpc-layer active"><strong>User</strong><br><small>Browser</small></div>
        <div class="fpc-layer-arrow">&#8594;</div>
        <div class="fpc-layer"><strong>CDN</strong><br><small id="layer-cdn">-</small></div>
        <div class="fpc-layer-arrow">&#8594;</div>
        <div class="fpc-layer active"><strong>FPC</strong><br><small id="layer-fpc">aktiv</small></div>
        <div class="fpc-layer-arrow">&#8594;</div>
        <div class="fpc-layer"><strong>PHP</strong><br><small id="layer-php">-</small></div>
        <div class="fpc-layer-arrow">&#8594;</div>
        <div class="fpc-layer"><strong>DB</strong><br><small id="layer-db">-</small></div>
    </div>
    <div class="fpc-section-title">.htaccess Validator</div>
    <div id="health-htaccess"></div>
    <div class="fpc-section-title">Health Check Details</div>
    <div id="health-details"></div>
</div>

<!-- ========== TAB 11: STATISTIK ========== -->
<div class="fpc-panel <?php echo $active_tab === 'statistik' ? 'active' : ''; ?>" id="panel-statistik">
    <div style="margin-bottom:12px;">
        <select class="fpc-input" id="stats-days" style="width:auto;" onchange="fpcLoadStats()">
            <option value="7">Last 7 Days</option>
            <option value="14">Last 14 Days</option>
            <option value="30" selected>Last 30 Days</option>
            <option value="90">Last 90 Days</option>
        </select>
    </div>
    <div class="fpc-kpis" id="stats-kpis"></div>
    <div class="fpc-charts">
        <div class="fpc-chart-box"><h3>Pageviews per Day</h3><canvas id="chart-stats-daily" height="200"></canvas></div>
        <div class="fpc-chart-box"><h3>Visitors by Hour</h3><canvas id="chart-stats-hourly" height="200"></canvas></div>
    </div>
    <div class="fpc-charts">
        <div class="fpc-chart-box"><h3>Device Types</h3><canvas id="chart-stats-devices" height="200"></canvas></div>
        <div class="fpc-chart-box"><h3>Bounce Rate per Day</h3><canvas id="chart-stats-bounce" height="200"></canvas></div>
    </div>
    <div class="fpc-section-title">Top 20 Pages</div>
    <div id="stats-top-pages"></div>
    <div class="fpc-section-title">Top Referrer</div>
    <div id="stats-top-referrers"></div>
</div>

<!-- ========== TAB 12: ALERTS ========== -->
<div class="fpc-panel <?php echo $active_tab === 'alerts' ? 'active' : ''; ?>" id="panel-alerts">
    <div class="fpc-section-title">Alert Configuration</div>
    <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <label style="color:var(--fpc-text2);font-size:12px;">Hit Rate Minimum (%)</label>
                <input type="number" class="fpc-input" id="alert-hitrate" value="70" min="0" max="100">
            </div>
            <div>
                <label style="color:var(--fpc-text2);font-size:12px;">Cache Empty Warning (Minutes)</label>
                <input type="number" class="fpc-input" id="alert-empty" value="30" min="1">
            </div>
            <div>
                <label style="color:var(--fpc-text2);font-size:12px;">Error Threshold per Hour</label>
                <input type="number" class="fpc-input" id="alert-errors" value="10" min="1">
            </div>
            <div>
                <label style="color:var(--fpc-text2);font-size:12px;">Notification Email</label>
                <input type="email" class="fpc-input" id="alert-email" placeholder="admin@mr-hanf.de">
            </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:12px;align-items:center;">
            <label style="color:var(--fpc-text2);font-size:13px;"><input type="checkbox" id="alert-enabled"> Enable Alerts</label>
            <label style="color:var(--fpc-text2);font-size:13px;"><input type="checkbox" id="alert-preloader-stop" checked> Report Preloader Stops</label>
        </div>
        <div style="margin-top:16px;">
            <button class="fpc-btn green" onclick="fpcSaveAlerts()">&#128190; Save</button>
        </div>
    </div>
    <div class="fpc-section-title">Alert History</div>
    <div id="alerts-history"></div>
</div>

<!-- ========== TAB 13: SETTINGS ========== -->
<div class="fpc-panel <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" id="panel-settings">
    <div class="fpc-section-title">Module Settings (Database)</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:12px;">These settings are stored in the modified eCommerce database and used by the preloader cron job.</p>
    <div id="settings-db" style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;"></div>

    <div class="fpc-section-title">Preloader Settings</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:12px;">Rate limiting, server load protection, and validation settings for the preloader cron job.</p>
    <div id="settings-preloader" style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;"></div>

    <div class="fpc-section-title">Cache Serve Settings</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:12px;">Settings for fpc_serve.php - the cache delivery handler.</p>
    <div id="settings-serve" style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;"></div>

    <div class="fpc-section-title">Health Check Settings</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:12px;">Settings for the daily health check cron job.</p>
    <div id="settings-healthcheck" style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;"></div>

    <div class="fpc-section-title">KI System-Prompt</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:12px;">Der System-Prompt definiert wie die KI analysiert, welche Regeln sie befolgt und welchen Kontext sie hat. Aenderungen werden sofort wirksam.</p>
    <div id="settings-ai-prompt" style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <span id="ai-prompt-status" style="font-size:12px;color:var(--fpc-text2);">Lade...</span>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="fpc-btn" onclick="fpcAiPromptReset()" style="background:var(--fpc-warn);font-size:12px;padding:6px 12px;">Auf Standard zuruecksetzen</button>
                <button class="fpc-btn" onclick="fpcAiPromptSave()" style="font-size:12px;padding:6px 12px;">Prompt speichern</button>
            </div>
        </div>
        <textarea id="ai-prompt-textarea" style="width:100%;min-height:400px;max-height:800px;background:var(--fpc-bg);color:var(--fpc-text);border:1px solid var(--fpc-border);border-radius:8px;padding:12px;font-family:'Fira Code',monospace;font-size:12px;line-height:1.5;resize:vertical;white-space:pre-wrap;" placeholder="System-Prompt wird geladen..."></textarea>
        <div style="margin-top:8px;display:flex;justify-content:space-between;align-items:center;">
            <span id="ai-prompt-length" style="font-size:11px;color:var(--fpc-text2);"></span>
            <span style="font-size:11px;color:var(--fpc-text2);">Gespeichert in: api/fpc/ai_system_prompt.txt</span>
        </div>
    </div>

    <div class="fpc-section-title">API Credentials (External Integrations)</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:12px;">Configure API keys for Google Search Console, Google Analytics 4, and SISTRIX. Credentials are stored locally in <code>api/fpc/api_credentials.json</code> (geschuetzt vor Cache-Flush).</p>
    <div id="settings-api-creds" style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;"></div>

    <div class="fpc-section-title">Remote Management API</div>
    <p style="color:var(--fpc-text2);font-size:12px;margin-bottom:12px;">The FPC API allows remote management via <code>fpc_api.php</code>. Use the token below for authentication.</p>
    <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
                <label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">API Endpoint</label>
                <input type="text" class="fpc-input" value="https://mr-hanf.de/fpc_api.php" readonly style="width:100%;">
            </div>
            <div>
                <label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">API Token</label>
                <input type="text" class="fpc-input" value="(configured in fpc_api.php)" readonly style="width:100%;">
                <small style="color:var(--fpc-text2);font-size:11px;">Token is set directly in fpc_api.php for security</small>
            </div>
        </div>
    </div>

    <div style="margin-top:20px;">
        <button class="fpc-btn green" onclick="fpcSaveSettings()">&#128190; Save All Settings</button>
        <button class="fpc-btn green" onclick="fpcSaveApiCredentials()" style="margin-left:8px;">&#128190; Save API Credentials</button>
        <button class="fpc-btn orange" onclick="fpcLoadSettings()" style="margin-left:8px;">&#8635; Reset to Saved</button>
    </div>
</div>

<!-- ========== TAB 14: GOOGLE SEARCH CONSOLE ========== -->
<div class="fpc-panel <?php echo $active_tab === 'gsc' ? 'active' : ''; ?>" id="panel-gsc">
    <div id="gsc-setup" style="display:none;">
        <div style="background:var(--fpc-card);border-radius:10px;padding:30px;border:1px solid var(--fpc-border);max-width:600px;margin:40px auto;text-align:center;">
            <h3 style="color:var(--fpc-teal);margin-bottom:16px;">Google Search Console Setup</h3>
            <p style="color:var(--fpc-text2);margin-bottom:20px;">To use this feature, you need to configure a Google Service Account.<br>Go to <strong>Settings tab &gt; API Credentials</strong> to set up your credentials.</p>
            <a href="?tab=settings" class="fpc-btn green" style="text-decoration:none;">Go to Settings</a>
        </div>
    </div>
    <div id="gsc-content" style="display:none;">
        <!-- Time Range Selector -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
            <span style="color:var(--fpc-text2);font-size:13px;">Zeitraum:</span>
            <button class="fpc-btn small gsc-range" data-days="7" onclick="fpcGscSetRange(7)">7 Tage</button>
            <button class="fpc-btn small gsc-range active" data-days="28" onclick="fpcGscSetRange(28)">28 Tage</button>
            <button class="fpc-btn small gsc-range" data-days="90" onclick="fpcGscSetRange(90)">3 Monate</button>
            <button class="fpc-btn small gsc-range" data-days="180" onclick="fpcGscSetRange(180)">6 Monate</button>
            <button class="fpc-btn small gsc-range" data-days="365" onclick="fpcGscSetRange(365)">12 Monate</button>
            <button class="fpc-btn small gsc-range" data-days="480" onclick="fpcGscSetRange(480)">16 Monate</button>
            <span id="gsc-loading" style="display:none;color:var(--fpc-teal);font-size:12px;margin-left:8px;">Loading...</span>
            <span id="gsc-timestamp" style="color:var(--fpc-text2);font-size:11px;margin-left:auto;"></span>
        </div>

        <!-- KPIs with Trends -->
        <div class="fpc-kpis" id="gsc-kpis"></div>

        <!-- Chart: Daily Clicks & Impressions (full width) -->
        <div style="margin-bottom:16px;">
            <div class="fpc-chart-box" style="width:100%;"><h3 id="gsc-chart1-title">Daily Clicks &amp; Impressions</h3><canvas id="chart-gsc-daily" height="250"></canvas></div>
        </div>

        <!-- Chart: Position & CTR (full width) -->
        <div style="margin-bottom:16px;">
            <div class="fpc-chart-box" style="width:100%;"><h3 id="gsc-chart2-title">Average Position &amp; CTR</h3><canvas id="chart-gsc-position" height="250"></canvas></div>
        </div>

        <!-- Row: Devices + Countries side by side -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div class="fpc-chart-box"><h3>Ger&auml;te-Verteilung</h3><canvas id="chart-gsc-devices" height="200"></canvas></div>
            <div class="fpc-chart-box"><h3>Search Types</h3><canvas id="chart-gsc-types" height="200"></canvas></div>
        </div>

        <!-- Top Countries -->
        <div class="fpc-section-title">Top L&auml;nder</div>
        <div id="gsc-countries" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Search Appearance -->
        <div class="fpc-section-title">Search Appearance (Rich Results, AMP, etc.)</div>
        <div id="gsc-appearance" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Top Queries -->
        <div class="fpc-section-title">Top Search Queries (Keywords)</div>
        <div id="gsc-queries" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Top Pages -->
        <div class="fpc-section-title">Top Pages by Clicks</div>
        <div id="gsc-pages" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Query-Page Combinations -->
        <div class="fpc-section-title">Keyword &rarr; Page Zuordnung (Top 50)</div>
        <div id="gsc-query-pages" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Sitemaps -->
        <div class="fpc-section-title">Sitemaps Status</div>
        <div id="gsc-sitemaps" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- URL Inspection -->
        <div class="fpc-section-title">URL Inspection (Stichprobe)</div>
        <div style="margin-bottom:8px;display:flex;gap:8px;align-items:center;">
            <input type="text" class="fpc-input" id="gsc-inspect-url" placeholder="URL eingeben z.B. https://mr-hanf.de/" style="flex:1;">
            <button class="fpc-btn green" onclick="fpcGscInspectUrl()">Inspect</button>
            <button class="fpc-btn" onclick="fpcGscInspectSample()">Top 10 pr&uuml;fen</button>
        </div>
        <div id="gsc-inspection" style="overflow-x:auto;"></div>
    </div>
    <div id="gsc-error" style="display:none;"></div>
</div>

<!-- ========== TAB 15: GOOGLE ANALYTICS 4 v2.0 ========== -->
<div class="fpc-panel <?php echo $active_tab === 'analytics' ? 'active' : ''; ?>" id="panel-analytics">
    <div id="ga4-setup" style="display:none;">
        <div style="background:var(--fpc-card);border-radius:10px;padding:30px;border:1px solid var(--fpc-border);max-width:600px;margin:40px auto;text-align:center;">
            <h3 style="color:var(--fpc-teal);margin-bottom:16px;">Google Analytics 4 Setup</h3>
            <p style="color:var(--fpc-text2);margin-bottom:20px;">To use this feature, configure a Google Service Account and GA4 Property ID.<br>Go to <strong>Settings tab &gt; API Credentials</strong> to set up.</p>
            <a href="?tab=settings" class="fpc-btn green" style="text-decoration:none;">Go to Settings</a>
        </div>
    </div>
    <div id="ga4-content" style="display:none;">
        <!-- Time Range Selector -->
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
            <span style="color:var(--fpc-text2);font-size:13px;">Zeitraum:</span>
            <button class="fpc-btn small ga4-range" data-days="7" onclick="fpcGa4SetRange(7)">7 Tage</button>
            <button class="fpc-btn small ga4-range active" data-days="30" onclick="fpcGa4SetRange(30)">30 Tage</button>
            <button class="fpc-btn small ga4-range" data-days="90" onclick="fpcGa4SetRange(90)">3 Monate</button>
            <button class="fpc-btn small ga4-range" data-days="180" onclick="fpcGa4SetRange(180)">6 Monate</button>
            <button class="fpc-btn small ga4-range" data-days="365" onclick="fpcGa4SetRange(365)">12 Monate</button>
            <span id="ga4-loading" style="display:none;color:var(--fpc-teal);font-size:12px;margin-left:8px;">Loading...</span>
            <span id="ga4-timestamp" style="color:var(--fpc-text2);font-size:11px;margin-left:auto;"></span>
        </div>

        <!-- Realtime Banner -->
        <div id="ga4-realtime" style="background:linear-gradient(135deg,#0d1b2a,#1b2838);border:1px solid var(--fpc-teal);border-radius:10px;padding:16px;margin-bottom:16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;"></div>

        <!-- KPIs -->
        <div class="fpc-kpis" id="ga4-kpis"></div>

        <!-- Chart: Daily Sessions & Users (full width) -->
        <div style="margin-bottom:16px;">
            <div class="fpc-chart-box" style="width:100%;"><h3 id="ga4-chart1-title">Sessions &amp; Users</h3><canvas id="chart-ga4-daily" height="250"></canvas></div>
        </div>

        <!-- Chart: Pageviews & Bounce Rate (full width) -->
        <div style="margin-bottom:16px;">
            <div class="fpc-chart-box" style="width:100%;"><h3 id="ga4-chart2-title">Pageviews &amp; Bounce Rate</h3><canvas id="chart-ga4-pv-bounce" height="250"></canvas></div>
        </div>

        <!-- Row: Devices + Channel Groups -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div class="fpc-chart-box"><h3>Ger&auml;te-Verteilung</h3><canvas id="chart-ga4-devices" height="200"></canvas></div>
            <div class="fpc-chart-box"><h3>Channel Groups</h3><canvas id="chart-ga4-channels" height="200"></canvas></div>
        </div>

        <!-- Row: New vs Returning + Hourly -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div class="fpc-chart-box"><h3>Neu vs. Wiederkehrend</h3><canvas id="chart-ga4-newret" height="200"></canvas></div>
            <div class="fpc-chart-box"><h3>Stunden-Verteilung (7 Tage)</h3><canvas id="chart-ga4-hourly" height="200"></canvas></div>
        </div>

        <!-- Row: Day of Week + Browsers -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div class="fpc-chart-box"><h3>Wochentag-Verteilung</h3><canvas id="chart-ga4-dow" height="200"></canvas></div>
            <div class="fpc-chart-box"><h3>Browser</h3><canvas id="chart-ga4-browsers" height="200"></canvas></div>
        </div>

        <!-- E-Commerce Section -->
        <div class="fpc-section-title" style="color:var(--fpc-green);">E-Commerce &Uuml;bersicht</div>
        <div class="fpc-kpis" id="ga4-ecom-kpis"></div>

        <!-- E-Commerce Revenue Chart -->
        <div style="margin-bottom:16px;">
            <div class="fpc-chart-box" style="width:100%;"><h3>Umsatz &amp; Transaktionen</h3><canvas id="chart-ga4-revenue" height="250"></canvas></div>
        </div>

        <!-- Shopping Funnel -->
        <div style="margin-bottom:16px;">
            <div class="fpc-chart-box" style="width:100%;"><h3>Shopping Funnel</h3><canvas id="chart-ga4-funnel" height="200"></canvas></div>
        </div>

        <!-- Top Products -->
        <div class="fpc-section-title">Top Produkte (nach Umsatz)</div>
        <div id="ga4-products" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Traffic Sources Table -->
        <div class="fpc-section-title">Traffic Quellen (Source / Medium)</div>
        <div id="ga4-sources" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Landing Pages -->
        <div class="fpc-section-title">Top Landing Pages</div>
        <div id="ga4-landing" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Top Pages -->
        <div class="fpc-section-title">Top Seiten (nach Pageviews)</div>
        <div id="ga4-pages" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Countries -->
        <div class="fpc-section-title">Top L&auml;nder</div>
        <div id="ga4-countries" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Events -->
        <div class="fpc-section-title">Events &Uuml;bersicht</div>
        <div id="ga4-events" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Key Events / Conversions -->
        <div class="fpc-section-title">Key Events (Conversions)</div>
        <div id="ga4-keyevents" style="overflow-x:auto;margin-bottom:16px;"></div>

        <!-- Technology: OS + Screen -->
        <div class="fpc-section-title">Technologie</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
            <div id="ga4-os" style="overflow-x:auto;"></div>
            <div id="ga4-screens" style="overflow-x:auto;"></div>
        </div>

        <!-- Languages -->
        <div class="fpc-section-title">Sprachen</div>
        <div id="ga4-languages" style="overflow-x:auto;margin-bottom:16px;"></div>
    </div>
    <div id="ga4-error" style="display:none;"></div>
</div>

<!-- ========== TAB 16: SISTRIX ========== -->
<div class="fpc-panel <?php echo $active_tab === 'sistrix' ? 'active' : ''; ?>" id="panel-sistrix">
    <div id="sx-setup" style="display:none;">
        <div style="background:var(--fpc-card);border-radius:10px;padding:30px;border:1px solid var(--fpc-border);max-width:600px;margin:40px auto;text-align:center;">
            <h3 style="color:var(--fpc-teal);margin-bottom:16px;">SISTRIX Setup</h3>
            <p style="color:var(--fpc-text2);margin-bottom:20px;">To use this feature, enter your SISTRIX API key.<br>Go to <strong>Settings tab > API Credentials</strong> to configure.</p>
            <a href="?tab=settings" class="fpc-btn green" style="text-decoration:none;">Go to Settings</a>
        </div>
    </div>
    <div id="sx-content" style="display:none;">
        <div class="fpc-kpis" id="sx-kpis"></div>
        <div style="background:var(--fpc-card);border-radius:10px;padding:20px;border:1px solid var(--fpc-border);margin-bottom:20px;">
            <h3 style="font-size:14px;color:var(--fpc-text2);margin-bottom:12px;">Visibility Index History</h3>
            <div style="height:300px;position:relative;"><canvas id="chart-sx-visibility"></canvas></div>
        </div>
        <div id="sx-history-table" style="overflow-x:auto;"></div>
        <div id="sx-upgrade-hint" style="background:var(--fpc-card);border:1px solid var(--fpc-border);border-radius:10px;padding:20px;margin-top:20px;">
            <h3 style="color:var(--fpc-orange);font-size:14px;margin-bottom:8px;">Want more data?</h3>
            <p style="color:var(--fpc-text2);font-size:13px;margin-bottom:8px;">Your SISTRIX Plus plan includes the <strong>Visibility Index</strong>. Upgrade to <strong>Professional</strong> to unlock:</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;margin-top:12px;">
                <div style="background:#0a1420;padding:10px;border-radius:6px;color:var(--fpc-text2);font-size:12px;">Ranking Distribution</div>
                <div style="background:#0a1420;padding:10px;border-radius:6px;color:var(--fpc-text2);font-size:12px;">Keyword Rankings</div>
                <div style="background:#0a1420;padding:10px;border-radius:6px;color:var(--fpc-text2);font-size:12px;">Top Competitors</div>
                <div style="background:#0a1420;padding:10px;border-radius:6px;color:var(--fpc-text2);font-size:12px;">Top Pages by Visibility</div>
            </div>
            <p style="color:var(--fpc-text2);font-size:12px;margin-top:12px;">Alternatively, use the <strong>GSC tab</strong> for free keyword and ranking data from Google Search Console.</p>
        </div>
    </div>
    <div id="sx-error" style="display:none;"></div>
</div>

</div><!-- /fpc-content -->

<script>
// ============================================================
// JAVASCRIPT v9.1.5
// ============================================================
var BASE = '<?php echo basename(__FILE__); ?>';
var chartInstances = {};
var rebuildPollTimer = null;
var preloaderPollTimer = null;

// --- Helpers ---
function fpcAjax(params, callback, method) {
    method = method || 'GET';
    var url = BASE + '?' + params;
    var xhr = new XMLHttpRequest();
    xhr.timeout = 120000; // 120s Timeout
    if (method === 'POST') {
        var parts = params.split('?');
        url = BASE + '?' + parts[0];
        xhr.open('POST', url, true);
        if (parts.length > 1) {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send(parts[1]);
        } else {
            xhr.send();
        }
    } else {
        xhr.open('GET', url, true);
        xhr.send();
    }
    xhr.onload = function() {
        if (xhr.status === 200) {
            try { var data = JSON.parse(xhr.responseText); callback(data); }
            catch(e) { console.error('JSON parse error:', e, xhr.responseText.substring(0, 200)); callback({error:true,msg:'JSON Fehler'}); }
        } else {
            callback({error:true,msg:'Server-Fehler: HTTP ' + xhr.status});
        }
    };
    xhr.onerror = function() { console.error('AJAX error:', url); callback({error:true,msg:'Netzwerk-Fehler'}); };
    xhr.ontimeout = function() { callback({error:true,msg:'Timeout (>120s)'}); };
}

function fpcAjaxPost(endpoint, postData, callback) {
    var url = BASE + '?ajax=' + endpoint;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        if (xhr.status === 200) {
            try { callback(JSON.parse(xhr.responseText)); } catch(e) { console.error(e); }
        }
    };
    xhr.send(postData);
}

function fpcAjaxPostJson(endpoint, data, callback) {
    var url = BASE + '?ajax=' + endpoint;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.timeout = 120000; // 120s Timeout fuer KI-Anfragen
    xhr.onload = function() {
        if (xhr.status === 200) {
            try { callback(JSON.parse(xhr.responseText)); } catch(e) { console.error('JSON parse error:', e); callback({error:true,msg:'Antwort konnte nicht verarbeitet werden (JSON Fehler)'}); }
        } else {
            callback({error:true,msg:'Server-Fehler: HTTP ' + xhr.status});
        }
    };
    xhr.onerror = function() { callback({error:true,msg:'Netzwerk-Fehler - Server nicht erreichbar'}); };
    xhr.ontimeout = function() { callback({error:true,msg:'Timeout - Anfrage dauerte zu lange (>120s)'}); };
    xhr.send(JSON.stringify(data));
}

function fpcToast(msg, isError) {
    var d = document.createElement('div');
    d.className = 'fpc-toast' + (isError ? ' error' : '');
    d.textContent = msg;
    document.body.appendChild(d);
    setTimeout(function() { d.remove(); }, 4000);
}

function fpcFormatBytes(b) {
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
}

function fpcKpiBox(label, value, color) {
    color = color || 'teal';
    var colorVar = color.indexOf('#') === 0 ? color : 'var(--fpc-' + color + ')';
    return '<div class="fpc-kpi"><div class="fpc-kpi-label">' + label + '</div><div class="fpc-kpi-value" style="color:' + colorVar + '">' + value + '</div></div>';
}

function fpcNum(n) {
    if (n === null || n === undefined) return '0';
    return Number(n).toLocaleString();
}

function fpcChart(canvasId, type, labels, datasets, options) {
    var cfg = { type: type, data: { labels: labels, datasets: datasets }, options: options || {} };
    cfg.options.responsive = true;
    cfg.options.maintainAspectRatio = true;
    if (!cfg.options.plugins) cfg.options.plugins = {};
    if (!cfg.options.plugins.legend) cfg.options.plugins.legend = { labels: { color: '#8899aa' } };
    return fpcMakeChart(canvasId, cfg);
}

function fpcMakeChart(id, cfg) {
    if (typeof Chart === 'undefined') return null;
    var canvas = document.getElementById(id);
    if (!canvas) return null;
    if (chartInstances[id]) { chartInstances[id].destroy(); }
    Chart.defaults.color = '#8899aa';
    Chart.defaults.borderColor = '#2a3a4a';
    chartInstances[id] = new Chart(canvas, cfg);
    return chartInstances[id];
}

function fpcBuildPagination(containerId, currentPage, totalPages, callback) {
    var c = document.getElementById(containerId);
    if (!c) return;
    if (totalPages <= 1) { c.innerHTML = ''; return; }
    var html = '';
    var show = [];
    show.push(1);
    if (totalPages > 1) show.push(2);
    for (var i = currentPage - 2; i <= currentPage + 2; i++) { if (i > 0 && i <= totalPages) show.push(i); }
    if (totalPages > 1) show.push(totalPages - 1);
    show.push(totalPages);
    show = show.filter(function(v, idx, arr) { return arr.indexOf(v) === idx; }).sort(function(a, b) { return a - b; });
    var prev = 0;
    for (var j = 0; j < show.length; j++) {
        if (show[j] - prev > 1) html += '<span class="ellipsis">...</span>';
        html += '<button class="' + (show[j] === currentPage ? 'active' : '') + '" onclick="' + callback + '(' + show[j] + ')">' + show[j] + '</button>';
        prev = show[j];
    }
    c.innerHTML = html;
}

// --- Clock ---
setInterval(function() {
    var d = document.getElementById('fpc-clock');
    if (d) d.textContent = new Date().toLocaleTimeString('de-DE');
}, 1000);

// ============================================================
// TAB 1: DASHBOARD
// ============================================================
function fpcLoadDashboard() {
    fpcAjax('ajax=status', function(d) {
        var ampel = d.errors_1h > 5 ? 'red' : (d.hit_rate < 70 ? 'yellow' : 'green');
        var status = ampel === 'green' ? 'Active' : (ampel === 'yellow' ? 'Degraded' : 'Error');
        var kpis = '';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label"><span class="fpc-ampel ' + ampel + '"></span>Cache Status</div><div class="fpc-kpi-value" style="color:var(--fpc-' + ampel + ')">' + status + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Cached Pages</div><div class="fpc-kpi-value" style="color:var(--fpc-teal)">' + d.files.toLocaleString() + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Cache Size</div><div class="fpc-kpi-value">' + d.size_formatted + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Hit Rate (1h)</div><div class="fpc-kpi-value" style="color:' + (d.hit_rate >= 80 ? 'var(--fpc-green)' : d.hit_rate >= 50 ? 'var(--fpc-orange)' : 'var(--fpc-red)') + '">' + d.hit_rate + '%</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Requests (1h)</div><div class="fpc-kpi-value">' + d.total_requests_1h + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Errors (1h)</div><div class="fpc-kpi-value" style="color:' + (d.errors_1h > 0 ? 'var(--fpc-red)' : 'var(--fpc-green)') + '">' + d.errors_1h + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Health Score</div><div class="fpc-kpi-value">' + (d.health_grade || '-') + '</div><div class="fpc-kpi-sub">' + (d.health_score !== null ? d.health_score + '/100' : 'Not checked yet') + '</div></div>';
        if (d.opcache) {
            kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">OPCache Hit Rate</div><div class="fpc-kpi-value" style="color:var(--fpc-green)">' + d.opcache.hit_rate + '%</div></div>';
        }
        document.getElementById('dash-kpis').innerHTML = kpis;
        // OPCache
        var oc = d.opcache;
        if (oc) {
            document.getElementById('dash-opcache').innerHTML = '<p style="color:var(--fpc-text2)">Status: ' + (oc.enabled ? '<span class="sev-ok">Active</span>' : '<span class="sev-error">Inactive</span>') + '</p><p style="color:var(--fpc-text2)">Hit Rate: <strong>' + oc.hit_rate + '%</strong></p><p style="color:var(--fpc-text2)">Memory: ' + oc.memory_used + ' MB used / ' + oc.memory_free + ' MB free</p>';
        } else {
            document.getElementById('dash-opcache').innerHTML = '<p style="color:var(--fpc-text2)">OPCache not available</p>';
        }
        // Preloader
        var pl = '';
        if (d.rebuild_running) pl = '<p><span class="fpc-ampel green"></span><strong style="color:var(--fpc-green)">Rebuild running</strong> (since ' + d.rebuild_started + ')</p>';
        else if (d.last_run) pl = '<p>Last run: <strong>' + d.last_run + '</strong></p>';
        else pl = '<p style="color:var(--fpc-text2)">No preloader run recorded</p>';
        if (d.last_stats) pl += '<p style="color:var(--fpc-text2)">Cached: ' + d.last_stats.cached + ' | Skipped: ' + d.last_stats.skipped + ' | Errors: ' + d.last_stats.errors + '</p>';
        document.getElementById('dash-preloader').innerHTML = pl;
        // Charts
        if (typeof Chart !== 'undefined') {
            var cats = d.categories || {};
            var catLabels = Object.keys(cats);
            var catData = catLabels.map(function(k) { return cats[k]; });
            fpcMakeChart('chart-categories', { type: 'doughnut', data: { labels: catLabels, datasets: [{ data: catData, backgroundColor: ['#00d4aa','#00e676','#00a8ff','#ffa502','#ff4757','#ffd32a','#a29bfe','#fd79a8','#6c5ce7','#00cec9','#e17055','#636e72','#b2bec3','#dfe6e9','#2d3436'] }] }, options: { responsive: true, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } } } });
        }
    });
    // Hit/Miss 24h Chart
    fpcAjax('ajax=performance', function(d) {
        if (typeof Chart === 'undefined') return;
        var labels = []; var hitsData = []; var missData = [];
        for (var h = 0; h < 24; h++) {
            labels.push(h + ':00');
            hitsData.push(d.hourly[h] ? d.hourly[h].hit : 0);
            missData.push(d.hourly[h] ? d.hourly[h].miss : 0);
        }
        fpcMakeChart('chart-hitmiss-24h', { type: 'bar', data: { labels: labels, datasets: [{ label: 'HIT', data: hitsData, backgroundColor: '#00e676' }, { label: 'MISS', data: missData, backgroundColor: '#ff4757' }] }, options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true } }, plugins: { legend: { labels: { boxWidth: 12 } } } } });
    });
}

// ============================================================
// TAB 2: PERFORMANCE
// ============================================================
function fpcLoadPerformance() {
    fpcAjax('ajax=performance', function(d) {
        var total = d.hit_miss.hit + d.hit_miss.miss + d.hit_miss.bypass;
        var hitPct = total > 0 ? ((d.hit_miss.hit / total) * 100).toFixed(1) : 0;
        var kpis = '';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Hit Rate</div><div class="fpc-kpi-value" style="color:var(--fpc-green)">' + hitPct + '%</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">TTFB (Cached)</div><div class="fpc-kpi-value" style="color:var(--fpc-teal)">' + d.avg_ttfb_hit + ' ms</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">TTFB (Uncached)</div><div class="fpc-kpi-value" style="color:var(--fpc-orange)">' + d.avg_ttfb_miss + ' ms</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Requests/min</div><div class="fpc-kpi-value">' + d.requests_per_min + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Total HITs</div><div class="fpc-kpi-value" style="color:var(--fpc-green)">' + d.hit_miss.hit.toLocaleString() + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Total MISSes</div><div class="fpc-kpi-value" style="color:var(--fpc-red)">' + d.hit_miss.miss.toLocaleString() + '</div></div>';
        document.getElementById('perf-kpis').innerHTML = kpis;
        if (typeof Chart === 'undefined') return;
        fpcMakeChart('chart-perf-pie', { type: 'doughnut', data: { labels: ['HIT', 'MISS', 'BYPASS'], datasets: [{ data: [d.hit_miss.hit, d.hit_miss.miss, d.hit_miss.bypass], backgroundColor: ['#00e676', '#ff4757', '#ffa502'] }] }, options: { responsive: true } });
        fpcMakeChart('chart-perf-ttfb', { type: 'bar', data: { labels: ['Cached (FPC HIT)', 'Uncached (FPC MISS)'], datasets: [{ label: 'TTFB (ms)', data: [d.avg_ttfb_hit, d.avg_ttfb_miss], backgroundColor: ['#00d4aa', '#ff4757'] }] }, options: { responsive: true, indexAxis: 'y' } });
        var hLabels = []; var hHits = []; var hMisses = [];
        for (var h = 0; h < 24; h++) { hLabels.push(h + ':00'); hHits.push(d.hourly[h].hit); hMisses.push(d.hourly[h].miss); }
        fpcMakeChart('chart-perf-hourly', { type: 'bar', data: { labels: hLabels, datasets: [{ label: 'HIT', data: hHits, backgroundColor: '#00e676' }, { label: 'MISS', data: hMisses, backgroundColor: '#ff4757' }] }, options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true } } } });
        if (d.timeline.length > 0) {
            var tLabels = d.timeline.map(function(t) { return t.date.substring(5); });
            fpcMakeChart('chart-perf-timeline', { type: 'line', data: { labels: tLabels, datasets: [{ label: 'HIT', data: d.timeline.map(function(t) { return t.hit; }), borderColor: '#00e676', fill: false, tension: 0.3 }, { label: 'MISS', data: d.timeline.map(function(t) { return t.miss; }), borderColor: '#ff4757', fill: false, tension: 0.3 }] }, options: { responsive: true } });
        }
    });
}

</script>
<script>
// ============================================================
// TAB 3: COVERAGE
// ============================================================
function fpcLoadCoverage() {
    document.getElementById('cov-kpis').innerHTML = '<div class="fpc-kpi"><div class="fpc-kpi-label">Loading...</div><div class="fpc-kpi-value">...</div></div>';
    fpcAjax('ajax=coverage', function(d) {
        var kpis = '';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Sitemap URLs</div><div class="fpc-kpi-value">' + d.sitemap_total.toLocaleString() + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Cached</div><div class="fpc-kpi-value" style="color:var(--fpc-green)">' + d.cached_total.toLocaleString() + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Coverage</div><div class="fpc-kpi-value" style="color:' + (d.coverage_pct >= 80 ? 'var(--fpc-green)' : d.coverage_pct >= 50 ? 'var(--fpc-orange)' : 'var(--fpc-red)') + '">' + d.coverage_pct + '%</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Pagination Issues</div><div class="fpc-kpi-value" style="color:' + (d.pagination_issues.length > 0 ? 'var(--fpc-red)' : 'var(--fpc-green)') + '">' + d.pagination_issues.length + '</div></div>';
        document.getElementById('cov-kpis').innerHTML = kpis;
        // Kategorien
        var cats = d.categories || {};
        var html = '<table class="fpc-table"><thead><tr><th>Category</th><th>Sitemap</th><th>Cached</th><th>Coverage</th></tr></thead><tbody>';
        for (var cat in cats) {
            var c = cats[cat]; var pct = c.total > 0 ? ((c.cached / c.total) * 100).toFixed(1) : 0;
            html += '<tr><td>' + cat + '</td><td>' + c.total + '</td><td>' + c.cached + '</td><td><span style="color:' + (pct >= 80 ? 'var(--fpc-green)' : pct >= 50 ? 'var(--fpc-orange)' : 'var(--fpc-red)') + '">' + pct + '%</span></td></tr>';
        }
        html += '</tbody></table>';
        document.getElementById('cov-categories').innerHTML = html;
        // Pagination
        if (d.pagination_issues.length > 0) {
            html = '<table class="fpc-table"><thead><tr><th>URL</th><th>Page</th><th>Action</th></tr></thead><tbody>';
            d.pagination_issues.forEach(function(p) {
                html += '<tr><td>' + p.url + '</td><td>Page ' + p.page_num + '</td><td><button class="fpc-btn teal" style="padding:3px 8px;font-size:11px;" onclick="fpcCacheUrlDirect(\'' + p.url + '\')">Cache Now</button></td></tr>';
            });
            html += '</tbody></table>';
        } else { html = '<p style="color:var(--fpc-green)">No pagination issues found!</p>'; }
        document.getElementById('cov-pagination').innerHTML = html;
        // Uncached
        if (d.uncached_top50.length > 0) {
            html = '<table class="fpc-table"><thead><tr><th>URL</th><th>Action</th></tr></thead><tbody>';
            d.uncached_top50.forEach(function(u) {
                html += '<tr><td>' + u + '</td><td><button class="fpc-btn teal" style="padding:3px 8px;font-size:11px;" onclick="fpcCacheUrlDirect(\'' + u + '\')">Cache</button></td></tr>';
            });
            html += '</tbody></table>';
        } else { html = '<p style="color:var(--fpc-green)">All sitemap URLs are cached!</p>'; }
        document.getElementById('cov-uncached').innerHTML = html;
    });
}

function fpcCacheUrlDirect(url) {
    fpcAjaxPost('cache_url', 'url=' + encodeURIComponent(url), function(r) { fpcToast(r.msg, !r.ok); });
}

// ============================================================
// TAB 4: STEUERUNG
// ============================================================
function fpcFlush() {
    if (!confirm('Really flush entire cache?')) return;
    fpcAjax('ajax=flush', function(r) { fpcToast(r.msg); fpcLoadDashboard(); }, 'POST');
}

function fpcRebuild() {
    fpcAjaxPost('rebuild', '', function(r) {
        fpcToast(r.msg, !r.ok);
        if (r.ok) fpcStartProgressPoll();
    });
}

function fpcStopRebuild() {
    fpcAjaxPost('stop', '', function(r) {
        fpcToast(r.msg);
        if (rebuildPollTimer) { clearInterval(rebuildPollTimer); rebuildPollTimer = null; }
        document.getElementById('rebuild-progress').classList.remove('active');
    });
}

function fpcStartProgressPoll() {
    var wrap = document.getElementById('rebuild-progress');
    if (wrap) wrap.classList.add('active');
    if (rebuildPollTimer) clearInterval(rebuildPollTimer);
    rebuildPollTimer = setInterval(fpcPollProgress, 2000);
    fpcPollProgress();
}

function fpcPollProgress() {
    fpcAjax('ajax=rebuild_progress', function(d) {
        var bar = document.getElementById('rebuild-bar');
        var pct = document.getElementById('rebuild-pct');
        var done = document.getElementById('rebuild-done');
        var errs = document.getElementById('rebuild-errors');
        var log = document.getElementById('rebuild-log');
        if (bar) bar.style.width = d.percent + '%';
        if (pct) pct.textContent = d.percent + '%';
        if (done) done.textContent = d.done + ' / ' + d.total + ' URLs';
        if (errs) errs.textContent = 'Errors: ' + d.errors + ' | Skipped: ' + d.skipped;
        if (log && d.last_lines) log.textContent = d.last_lines.join('\n');
        // Also update preloader tab
        var pBar = document.getElementById('preloader-bar');
        var pPct = document.getElementById('preloader-pct');
        var pDone = document.getElementById('preloader-done');
        var pLog = document.getElementById('preloader-log');
        if (pBar) pBar.style.width = d.percent + '%';
        if (pPct) pPct.textContent = d.percent + '%';
        if (pDone) pDone.textContent = d.done + ' / ' + d.total + ' URLs';
        var pSpeed = document.getElementById('preloader-speed');
        if (pSpeed && d.speed) pSpeed.textContent = d.speed + ' URLs/sec';
        if (pLog && d.last_lines) pLog.textContent = d.last_lines.join('\n');
        if (!d.running && d.done > 0) {
            if (rebuildPollTimer) { clearInterval(rebuildPollTimer); rebuildPollTimer = null; }
            fpcToast('Rebuild completed: ' + d.done + ' URLs');
            setTimeout(function() {
                var wrap = document.getElementById('rebuild-progress');
                if (wrap) wrap.classList.remove('active');
            }, 10000);
        }
    });
}

function fpcCacheUrl() {
    var url = document.getElementById('cache-url-input').value.trim();
    if (!url) return;
    document.getElementById('cache-url-result').innerHTML = '<span style="color:var(--fpc-text2)">Lade...</span>';
    fpcAjaxPost('cache_url', 'url=' + encodeURIComponent(url), function(r) {
        document.getElementById('cache-url-result').innerHTML = '<span style="color:' + (r.ok ? 'var(--fpc-green)' : 'var(--fpc-red)') + '">' + r.msg + '</span>';
    });
}

function fpcAddCustomUrl() {
    var url = document.getElementById('custom-url-input').value.trim();
    if (!url) return;
    fpcAjaxPost('add_custom_url', 'url=' + encodeURIComponent(url), function(r) { fpcToast(r.msg, !r.ok); fpcLoadCustomUrls(); });
}

function fpcLoadCustomUrls() {
    fpcAjax('ajax=custom_urls', function(d) {
        if (!d.urls || d.urls.length === 0) { document.getElementById('custom-urls-list').innerHTML = '<p style="color:var(--fpc-text2)">No custom URLs.</p>'; return; }
        var html = '<table class="fpc-table"><thead><tr><th>URL</th><th>Action</th></tr></thead><tbody>';
        d.urls.forEach(function(u) {
            html += '<tr><td>' + u + '</td><td><button class="fpc-btn red" style="padding:3px 8px;font-size:11px;" onclick="fpcRemoveCustomUrl(\'' + u + '\')">Remove</button></td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('custom-urls-list').innerHTML = html;
    });
}

function fpcRemoveCustomUrl(url) {
    fpcAjaxPost('remove_custom_url', 'url=' + encodeURIComponent(url), function(r) { fpcToast(r.msg); fpcLoadCustomUrls(); });
}

// ============================================================
// TAB 5: URLS
// ============================================================
function fpcLoadUrls(page) {
    var search = document.getElementById('url-search') ? document.getElementById('url-search').value : '';
    fpcAjax('ajax=urls&page=' + page + '&search=' + encodeURIComponent(search), function(d) {
        var html = '<table class="fpc-table"><thead><tr><th>Path</th><th>Size</th><th>Cached</th><th>Age</th><th>Action</th></tr></thead><tbody>';
        d.urls.forEach(function(u) {
            html += '<tr><td><a href="https://mr-hanf.de' + u.path + '" target="_blank" style="color:var(--fpc-teal)">' + u.path + '</a></td><td>' + fpcFormatBytes(u.size) + '</td><td>' + u.cached + '</td><td>' + u.age_h + 'h</td>';
            html += '<td><button class="fpc-btn teal" style="padding:3px 8px;font-size:11px;" onclick="fpcRecacheUrl(\'' + u.path.replace(/'/g, "\\'") + '\')">&#8635;</button> ';
            html += '<button class="fpc-btn red" style="padding:3px 8px;font-size:11px;" onclick="fpcRemoveUrl(\'' + u.path.replace(/'/g, "\\'") + '\')">&#128465;</button></td></tr>';
        });
        html += '</tbody></table>';
        html += '<p style="color:var(--fpc-text2);font-size:12px;margin-top:8px;">' + d.total + ' URLs total | Page ' + d.page + ' of ' + d.pages + '</p>';
        document.getElementById('urls-table').innerHTML = html;
        fpcBuildPagination('urls-pagination', d.page, d.pages, 'fpcLoadUrls');
    });
}

function fpcRecacheUrl(path) {
    fpcAjaxPost('recache_url', 'path=' + encodeURIComponent(path), function(r) { fpcToast(r.msg, !r.ok); });
}

function fpcRemoveUrl(path) {
    fpcAjaxPost('remove_url', 'path=' + encodeURIComponent(path), function(r) { fpcToast(r.msg, !r.ok); fpcLoadUrls(1); });
}

function fpcExportUrls() {
    window.open(BASE + '?ajax=export_urls', '_blank');
}

// ============================================================
// TAB 6: PRELOADER
// ============================================================
function fpcLoadPreloader() {
    fpcAjax('ajax=preloader_status', function(d) {
        var kpis = '';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Cached Files</div><div class="fpc-kpi-value" style="color:var(--fpc-teal)">' + d.cached_files + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Sitemap URLs</div><div class="fpc-kpi-value">' + d.sitemap_urls + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Queue</div><div class="fpc-kpi-value">' + d.queue_size + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Status</div><div class="fpc-kpi-value" style="color:' + (d.running ? 'var(--fpc-green)' : 'var(--fpc-text2)') + '">' + (d.running ? 'Running' : 'Stopped') + '</div></div>';
        document.getElementById('preloader-kpis').innerHTML = kpis;
        if (d.running) fpcStartProgressPoll();
    });
    fpcAjax('ajax=log&type=preloader&lines=50', function(d) {
        var el = document.getElementById('preloader-full-log');
        if (el) el.textContent = d.content;
    });
}

// ============================================================
// TAB 7: FEHLER
// ============================================================
function fpcLoadFehler() {
    fpcAjax('ajax=miss_reasons', function(d) {
        var html = '<table class="fpc-table"><thead><tr><th>Reason</th><th>Count</th><th>Share</th></tr></thead><tbody>';
        for (var reason in d.reasons) {
            var pct = d.total > 0 ? ((d.reasons[reason] / d.total) * 100).toFixed(1) : 0;
            html += '<tr><td>' + reason + '</td><td>' + d.reasons[reason] + '</td><td>' + pct + '%</td></tr>';
        }
        html += '</tbody></table>';
        if (Object.keys(d.reasons).length === 0) html = '<p style="color:var(--fpc-text2)">No data yet. Request logging must be enabled in fpc_serve.php.</p>';
        document.getElementById('fehler-reasons').innerHTML = html;
    });
    fpcAjax('ajax=error_urls', function(d) {
        if (!d.urls || d.urls.length === 0) { document.getElementById('fehler-urls').innerHTML = '<p style="color:var(--fpc-green)">No error URLs!</p>'; return; }
        var html = '<table class="fpc-table"><thead><tr><th>URL</th><th>HTTP Code</th><th>Count</th><th>Last Error</th></tr></thead><tbody>';
        d.urls.forEach(function(u) {
            html += '<tr><td>' + u.url + '</td><td><span class="sev-error">' + u.code + '</span></td><td>' + u.count + '</td><td>' + u.last + '</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('fehler-urls').innerHTML = html;
    });
    fpcAjax('ajax=slowest_pages', function(d) {
        if (!d.pages || d.pages.length === 0) { document.getElementById('fehler-slowest').innerHTML = '<p style="color:var(--fpc-text2)">No data yet.</p>'; return; }
        var html = '<table class="fpc-table"><thead><tr><th>URL</th><th>TTFB (ms)</th><th>Rating</th></tr></thead><tbody>';
        d.pages.forEach(function(p) {
            var sev = p.ttfb > 3000 ? 'critical' : (p.ttfb > 1500 ? 'warning' : 'ok');
            html += '<tr><td>' + p.url + '</td><td>' + p.ttfb + '</td><td><span class="sev-' + sev + '">' + (sev === 'critical' ? 'Critical' : sev === 'warning' ? 'Slow' : 'OK') + '</span></td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('fehler-slowest').innerHTML = html;
    });
}

// ============================================================
// TAB 8: SEO - FULL SEO CONTROL CENTER
// ============================================================
var seo404Filter = 'unresolved';
var seoScanFilter = '';
var seoScanTypeFilter = 'pages'; // v10.4.0: pages|all|assets
var seoScanLangFilter = '';      // v10.4.0: ''|de|en|fr|es|nl|it
var seoScanGrouped = false;      // v10.4.0: Gruppierte Ansicht
var seoScanData = [];            // v10.4.0: Rohdaten fuer KI-Analyse

function fpcLoadSeo() {
    // File Editor laden
    fpcFileEditorLoad('htaccess');
    // 1. Overview laden (Health, KPIs)
    fpcAjax('ajax=seo_overview', function(d) {
        var h = d.health || {};
        var score = h.score || 0;
        var scoreColor = score >= 80 ? 'var(--fpc-green)' : (score >= 60 ? 'var(--fpc-orange)' : 'var(--fpc-red)');
        var el = document.getElementById('seo-health-score');
        el.textContent = score + '%';
        el.style.borderColor = scoreColor;
        el.style.color = scoreColor;

        // KPIs
        var kpis = '';
        kpis += fpcKpiBox('Aktive Redirects', d.redirects ? d.redirects.active : 0, 'var(--fpc-teal)');
        kpis += fpcKpiBox('Redirect Hits', d.redirects ? fpcNum(d.redirects.total_hits) : 0, 'var(--fpc-blue)');
        kpis += fpcKpiBox('Canonicals', d.canonicals ? d.canonicals.active : 0, 'var(--fpc-purple)');
        kpis += fpcKpiBox('Offene 404s', d.log_404 ? d.log_404.unresolved : 0, d.log_404 && d.log_404.unresolved > 10 ? 'var(--fpc-red)' : 'var(--fpc-orange)');
        kpis += fpcKpiBox('404 Hits gesamt', d.log_404 ? fpcNum(d.log_404.total_hits) : 0, 'var(--fpc-orange)');
        kpis += fpcKpiBox('Scan URLs', d.scan ? d.scan.total_results : 0, 'var(--fpc-text)');
        kpis += fpcKpiBox('Scan Errors', h.errors || 0, h.errors > 0 ? 'var(--fpc-red)' : 'var(--fpc-green)');
        kpis += fpcKpiBox('Canonical Mismatches', h.canonical_mismatches || 0, h.canonical_mismatches > 0 ? 'var(--fpc-orange)' : 'var(--fpc-green)');
        document.getElementById('seo-kpis').innerHTML = kpis;

        // Alerts
        var alerts = '';
        if (h.errors > 0) alerts += '<span style="color:var(--fpc-red);">&#9888; ' + h.errors + ' HTTP-Fehler</span> ';
        if (h.canonical_mismatches > 0) alerts += '<span style="color:var(--fpc-orange);">&#9888; ' + h.canonical_mismatches + ' Canonical Mismatches</span> ';
        if ((d.log_404 ? d.log_404.unresolved : 0) > 10) alerts += '<span style="color:var(--fpc-orange);">&#9888; ' + d.log_404.unresolved + ' offene 404s</span> ';
        if (!alerts) alerts = '<span style="color:var(--fpc-green);">Alles in Ordnung!</span>';
        document.getElementById('seo-health-alerts').innerHTML = alerts;

        // Health Trend Chart
        if (d.scan && d.scan.history && d.scan.history.length > 1 && typeof Chart !== 'undefined') {
            var labels = d.scan.history.map(function(h) { return h.date ? h.date.substring(0, 10) : ''; });
            var scores = d.scan.history.map(function(h) { return h.score; });
            fpcMakeChart('chart-seo-health-trend', {
                type: 'line',
                data: { labels: labels, datasets: [{ label: 'Health Score', data: scores, borderColor: '#00e676', backgroundColor: 'rgba(0,230,118,0.1)', fill: true, tension: 0.3 }] },
                options: { responsive: true, scales: { y: { min: 0, max: 100 } }, plugins: { legend: { display: false } } }
            });
        }
    });

    // 2. Redirects laden
    fpcSeoLoadRedirects();

    // 3. Canonicals laden
    fpcSeoLoadCanonicals();

    // 4. 404 Log laden
    fpcSeoLoad404('unresolved');

    // 5. Scan-Ergebnisse laden
    fpcSeoLoadScanResults('');

    // 6. Probleme laden
    fpcSeoLoadProblems();

    // 7. Bot-Daten laden (original)
    fpcAjax('ajax=seo_data', function(d) {
        if (typeof Chart !== 'undefined' && d.bots) {
            var botNames = Object.keys(d.bots);
            var botReqs = botNames.map(function(n) { return d.bots[n].requests; });
            fpcMakeChart('chart-seo-bots', { type: 'bar', data: { labels: botNames, datasets: [{ label: 'Requests', data: botReqs, backgroundColor: '#00a8ff' }] }, options: { responsive: true, indexAxis: 'y' } });
            var botHits = botNames.map(function(n) { return d.bots[n].hits; });
            var botMisses = botNames.map(function(n) { return d.bots[n].requests - d.bots[n].hits; });
            fpcMakeChart('chart-seo-hitrate', { type: 'bar', data: { labels: botNames, datasets: [{ label: 'HIT', data: botHits, backgroundColor: '#00e676' }, { label: 'MISS', data: botMisses, backgroundColor: '#ff4757' }] }, options: { responsive: true, scales: { x: { stacked: true }, y: { stacked: true } } } });
        }
        if (d.bot_top_urls) {
            var html = '<table class="fpc-table"><thead><tr><th>URL</th><th>Bot-Requests</th></tr></thead><tbody>';
            for (var url in d.bot_top_urls) { html += '<tr><td>' + url + '</td><td>' + d.bot_top_urls[url] + '</td></tr>'; }
            html += '</tbody></table>';
            if (Object.keys(d.bot_top_urls).length === 0) html = '<p style="color:var(--fpc-text2)">No bot data yet.</p>';
            document.getElementById('seo-top-urls').innerHTML = html;
        }
    });

    // 8. AI Quick Summary
    fpcAjax('ajax=ai_quick_summary', function(d) {
        if (d.ai_configured) {
            document.getElementById('seo-ai-status').innerHTML = '<span style="color:var(--fpc-green);">OpenAI API konfiguriert (Modell: ' + (d.model || 'gpt-4.1-mini') + ')</span>';
        } else {
            document.getElementById('seo-ai-status').innerHTML = '<span style="color:var(--fpc-orange);">OpenAI API nicht konfiguriert. Gehe zu Settings > API Credentials.</span>';
        }
    });

    // 9. Chat History laden
    fpcSeoChatLoadHistory();
}

// --- REDIRECTS ---
function fpcSeoLoadRedirects() {
    var search = document.getElementById('redir-search') ? document.getElementById('redir-search').value : '';
    fpcAjax('ajax=seo_redirects&search=' + encodeURIComponent(search), function(d) {
        if (!d || d.length === 0) { document.getElementById('seo-redirects-table').innerHTML = '<p style="color:var(--fpc-text2)">Keine Redirects vorhanden.</p>'; return; }
        var html = '<table class="fpc-table"><thead><tr><th>Source</th><th>Target</th><th>Typ</th><th>Regex</th><th>Hits</th><th>Letzter Hit</th><th>Notiz</th><th>Aktiv</th><th>Aktion</th></tr></thead><tbody>';
        d.forEach(function(r) {
            var esc_src = (r.source || '').replace(/"/g, '&quot;');
            var esc_tgt = (r.target || '').replace(/"/g, '&quot;');
            var esc_note = (r.note || '').replace(/"/g, '&quot;');
            // v10.2.5: Anzeige-Zeile (normal)
            html += '<tr id="redir-row-' + r.id + '">';
            html += '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;" title="' + esc_src + '">' + r.source + '</td>';
            html += '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;" title="' + esc_tgt + '">' + r.target + '</td>';
            html += '<td><span class="fpc-badge ' + (r.type === '301' ? 'hit' : 'bypass') + '">' + r.type + '</span></td>';
            html += '<td>' + (r.is_regex ? 'Ja' : 'Nein') + '</td>';
            html += '<td>' + (r.hit_count || 0) + '</td>';
            html += '<td style="font-size:11px;">' + (r.last_hit || '-') + '</td>';
            html += '<td style="font-size:11px;">' + (r.note || '') + '</td>';
            html += '<td>' + (r.active ? '<span style="color:var(--fpc-green)">Ja</span>' : '<span style="color:var(--fpc-red)">Nein</span>') + '</td>';
            html += '<td style="white-space:nowrap;">';
            html += '<button class="fpc-btn" style="padding:2px 6px;font-size:11px;margin-right:3px;background:var(--fpc-blue);" onclick="fpcSeoRedirectEdit(' + r.id + ')" title="Bearbeiten">&#9998;</button>';
            html += '<button class="fpc-btn red" style="padding:2px 6px;font-size:11px;" onclick="fpcSeoRedirectDelete(' + r.id + ')" title="Loeschen">X</button>';
            html += '</td>';
            html += '</tr>';
            // v10.2.5: Edit-Zeile (versteckt, wird bei Klick auf Edit sichtbar)
            html += '<tr id="redir-edit-' + r.id + '" style="display:none;background:rgba(0,150,255,0.08);">';
            html += '<td><input type="text" class="fpc-input" id="redit-src-' + r.id + '" value="' + esc_src + '" style="width:100%;font-size:12px;"></td>';
            html += '<td><input type="text" class="fpc-input" id="redit-tgt-' + r.id + '" value="' + esc_tgt + '" style="width:100%;font-size:12px;"></td>';
            html += '<td><select class="fpc-input" id="redit-type-' + r.id + '" style="font-size:12px;"><option value="301"' + (r.type==='301'?' selected':'') + '>301</option><option value="302"' + (r.type==='302'?' selected':'') + '>302</option><option value="307"' + (r.type==='307'?' selected':'') + '>307</option></select></td>';
            html += '<td><input type="checkbox" id="redit-regex-' + r.id + '"' + (r.is_regex ? ' checked' : '') + '></td>';
            html += '<td colspan="2"></td>';
            html += '<td><input type="text" class="fpc-input" id="redit-note-' + r.id + '" value="' + esc_note + '" style="width:100%;font-size:12px;"></td>';
            html += '<td><input type="checkbox" id="redit-active-' + r.id + '"' + (r.active ? ' checked' : '') + '> Aktiv</td>';
            html += '<td style="white-space:nowrap;">';
            html += '<button class="fpc-btn" style="padding:2px 6px;font-size:11px;margin-right:3px;background:var(--fpc-green);color:#fff;" onclick="fpcSeoRedirectSave(' + r.id + ')" title="Speichern">&#10003;</button>';
            html += '<button class="fpc-btn" style="padding:2px 6px;font-size:11px;background:var(--fpc-text2);color:#fff;" onclick="fpcSeoRedirectCancelEdit(' + r.id + ')" title="Abbrechen">&#10007;</button>';
            html += '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        html += '<p style="color:var(--fpc-text2);font-size:12px;margin-top:4px;">' + d.length + ' Redirects</p>';
        document.getElementById('seo-redirects-table').innerHTML = html;
    });
}

function fpcSeoRedirectAdd() {
    var data = {
        source: document.getElementById('redir-source').value,
        target: document.getElementById('redir-target').value,
        type: document.getElementById('redir-type').value,
        is_regex: document.getElementById('redir-regex').checked,
        note: document.getElementById('redir-note').value
    };
    fpcAjaxPostJson('seo_redirect_add', data, function(r) {
        fpcToast(r.msg, !r.ok);
        if (r.ok) {
            document.getElementById('redir-source').value = '';
            document.getElementById('redir-target').value = '';
            document.getElementById('redir-note').value = '';
            fpcSeoLoadRedirects();
        }
    });
}

function fpcSeoRedirectDelete(id) {
    if (!confirm('Redirect wirklich loeschen?')) return;
    fpcAjax('ajax=seo_redirect_delete&id=' + id, function(r) { fpcToast(r.msg, !r.ok); fpcSeoLoadRedirects(); });
}

// v10.2.5: Redirect bearbeiten - Edit-Zeile einblenden
function fpcSeoRedirectEdit(id) {
    // Alle anderen Edit-Zeilen schliessen
    document.querySelectorAll('[id^="redir-edit-"]').forEach(function(el) { el.style.display = 'none'; });
    document.querySelectorAll('[id^="redir-row-"]').forEach(function(el) { el.style.display = ''; });
    // Diese Edit-Zeile einblenden, Anzeige-Zeile ausblenden
    var editRow = document.getElementById('redir-edit-' + id);
    var viewRow = document.getElementById('redir-row-' + id);
    if (editRow) editRow.style.display = '';
    if (viewRow) viewRow.style.display = 'none';
}

// v10.2.5: Redirect speichern
function fpcSeoRedirectSave(id) {
    var data = {
        id: id,
        source: document.getElementById('redit-src-' + id).value,
        target: document.getElementById('redit-tgt-' + id).value,
        type: document.getElementById('redit-type-' + id).value,
        is_regex: document.getElementById('redit-regex-' + id).checked,
        note: document.getElementById('redit-note-' + id).value,
        active: document.getElementById('redit-active-' + id).checked
    };
    fpcAjaxPostJson('seo_redirect_update', data, function(r) {
        fpcToast(r.msg, !r.ok);
        fpcSeoLoadRedirects();
    });
}

// v10.2.5: Edit abbrechen
function fpcSeoRedirectCancelEdit(id) {
    var editRow = document.getElementById('redir-edit-' + id);
    var viewRow = document.getElementById('redir-row-' + id);
    if (editRow) editRow.style.display = 'none';
    if (viewRow) viewRow.style.display = '';
}

// --- CANONICALS ---
function fpcSeoLoadCanonicals() {
    fpcAjax('ajax=seo_canonicals', function(d) {
        if (!d || d.length === 0) { document.getElementById('seo-canonicals-table').innerHTML = '<p style="color:var(--fpc-text2)">Keine Canonical Overrides vorhanden.</p>'; return; }
        var html = '<table class="fpc-table"><thead><tr><th>Seiten-URL</th><th>Canonical URL</th><th>Aktiv</th><th>Notiz</th><th>Aktion</th></tr></thead><tbody>';
        d.forEach(function(c) {
            html += '<tr>';
            html += '<td>' + c.page_url + '</td>';
            html += '<td>' + c.canonical_url + '</td>';
            html += '<td>' + (c.active ? '<span style="color:var(--fpc-green)">Ja</span>' : '<span style="color:var(--fpc-red)">Nein</span>') + '</td>';
            html += '<td style="font-size:11px;">' + (c.note || '') + '</td>';
            html += '<td><button class="fpc-btn red" style="padding:2px 6px;font-size:11px;" onclick="fpcSeoCanonicalDelete(' + c.id + ')">X</button></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('seo-canonicals-table').innerHTML = html;
    });
}

function fpcSeoCanonicalAdd() {
    var data = {
        page_url: document.getElementById('canon-page').value,
        canonical_url: document.getElementById('canon-url').value,
        note: document.getElementById('canon-note').value
    };
    fpcAjaxPostJson('seo_canonical_add', data, function(r) {
        fpcToast(r.msg, !r.ok);
        if (r.ok) {
            document.getElementById('canon-page').value = '';
            document.getElementById('canon-url').value = '';
            document.getElementById('canon-note').value = '';
            fpcSeoLoadCanonicals();
        }
    });
}

function fpcSeoCanonicalDelete(id) {
    if (!confirm('Canonical Override loeschen?')) return;
    fpcAjax('ajax=seo_canonical_delete&id=' + id, function(r) { fpcToast(r.msg, !r.ok); fpcSeoLoadCanonicals(); });
}

// --- 404 LOG ---
function fpcSeoLoad404(filter) {
    if (filter) seo404Filter = filter;
    var search = document.getElementById('404-search') ? document.getElementById('404-search').value : '';
    var params = 'ajax=seo_404_log&search=' + encodeURIComponent(search);
    if (seo404Filter === 'unresolved') params += '&resolved=false&dismissed=false';
    else if (seo404Filter === 'resolved') params += '&resolved=true';
    else if (seo404Filter === 'dismissed') params += '&dismissed=true';

    fpcAjax(params, function(d) {
        if (!d || d.length === 0) { document.getElementById('seo-404-table').innerHTML = '<p style="color:var(--fpc-green)">Keine 404-Fehler in dieser Kategorie.</p>'; return; }
        var html = '<table class="fpc-table"><thead><tr><th>URL</th><th>Hits</th><th>Erstmals</th><th>Letzter Hit</th><th>Referers</th><th>Aktion</th></tr></thead><tbody>';
        d.forEach(function(e) {
            html += '<tr>';
            var checkUrl = (e.url.indexOf('http') === 0) ? e.url : 'https://mr-hanf.de' + e.url;
            html += '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;" title="' + e.url + '"><a href="' + checkUrl + '" target="_blank" style="color:var(--fpc-text);text-decoration:none;" title="URL oeffnen">' + e.url + '</a></td>';
            html += '<td><strong style="color:' + (e.hit_count > 50 ? 'var(--fpc-red)' : e.hit_count > 10 ? 'var(--fpc-orange)' : 'var(--fpc-text)') + '">' + e.hit_count + '</strong></td>';
            html += '<td style="font-size:11px;">' + (e.first_seen || '') + '</td>';
            html += '<td style="font-size:11px;">' + (e.last_hit || '') + '</td>';
            html += '<td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;">' + (e.referers ? e.referers.join(', ') : '-') + '</td>';
            if (!e.resolved && !e.dismissed) {
                html += '<td style="white-space:nowrap;">';
                html += '<button class="fpc-btn" style="padding:2px 6px;font-size:11px;margin-right:3px;background:var(--fpc-blue);" onclick="fpcSeo404Check(\'' + e.url.replace(/'/g, "\\'") + '\')" title="URL pruefen">&#128269;</button>';
                html += '<button class="fpc-btn green" style="padding:2px 6px;font-size:11px;margin-right:3px;" onclick="fpcSeo404Resolve(' + e.id + ',\'' + e.url.replace(/'/g, "\\'") + '\')">Redirect</button>';
                html += '<button class="fpc-btn red" style="padding:2px 6px;font-size:11px;" onclick="fpcSeo404Dismiss(' + e.id + ')">Ignorieren</button></td>';
            } else {
                html += '<td style="white-space:nowrap;font-size:11px;">';
                if (e.resolved && e.resolved_to) {
                    html += '<button class="fpc-btn" style="padding:2px 6px;font-size:11px;margin-right:3px;background:var(--fpc-blue);" onclick="fpcSeo404Check(\'' + e.url.replace(/'/g, "\\'") + '\')" title="Redirect pruefen">&#128269;</button>';
                    html += '<span style="color:var(--fpc-text2);">&#8594; ' + e.resolved_to + '</span>';
                } else {
                    html += '<span style="color:var(--fpc-text2);">' + (e.resolved ? 'Resolved' : 'Dismissed') + '</span>';
                }
                html += '</td>';
            }
            html += '</tr>';
        });
        html += '</tbody></table>';
        html += '<p style="color:var(--fpc-text2);font-size:12px;margin-top:4px;">' + d.length + ' Eintraege</p>';
        document.getElementById('seo-404-table').innerHTML = html;
    });
}

function fpcSeo404Resolve(id, url) {
    var target = prompt('Redirect-Ziel fuer ' + url + ':', '/');
    if (target === null) return;
    fpcAjaxPostJson('seo_404_resolve', { id: id, target: target }, function(r) {
        fpcToast(r.msg, !r.ok);
        fpcSeoLoad404();
        fpcSeoLoadRedirects();
    });
}

function fpcSeo404Dismiss(id) {
    fpcAjax('ajax=seo_404_dismiss&id=' + id, function(r) { fpcToast(r.msg, !r.ok); fpcSeoLoad404(); });
}

// v10.2.6: System-URLs aus 404-Log bereinigen
function fpcSeo404Cleanup() {
    if (!confirm('System-URLs (fpc_serve.php, index.php, .well-known etc.) aus dem 404-Log entfernen?')) return;
    fpcAjax('ajax=seo_404_cleanup', function(r) {
        fpcToast(r.msg, !r.ok);
        fpcSeoLoad404();
    });
}

// v10.2.6: URL pruefen - oeffnet in neuem Tab und zeigt HTTP-Status
function fpcSeo404Check(url) {
    var fullUrl = (url.indexOf('http') === 0) ? url : 'https://mr-hanf.de' + url;
    // AJAX-Call um HTTP-Status zu pruefen
    fpcAjax('ajax=seo_check_url&url=' + encodeURIComponent(url), function(r) {
        if (r && r.status) {
            var color = r.status >= 200 && r.status < 300 ? 'var(--fpc-green)' :
                        r.status >= 300 && r.status < 400 ? 'var(--fpc-orange)' : 'var(--fpc-red)';
            var msg = url + ' → HTTP ' + r.status;
            if (r.redirect_to) msg += ' → ' + r.redirect_to;
            if (r.final_status) msg += ' → HTTP ' + r.final_status;
            fpcToast(msg, r.status >= 400);
        } else {
            fpcToast('Konnte URL nicht pruefen', true);
        }
    });
    // Gleichzeitig in neuem Tab oeffnen
    window.open(fullUrl, '_blank');
}

// --- SCAN ---
function fpcSeoRunScan(mode) {
    fpcToast('Scan gestartet (' + mode + ')... Bitte warten.');
    fpcAjax('ajax=seo_scan&mode=' + mode, function(d) {
        if (d.ok) {
            fpcToast(d.scanned + ' URLs gescannt. Health Score: ' + (d.health_score ? d.health_score.score : '?') + '%');
            fpcLoadSeo();
        } else {
            fpcToast(d.error || 'Scan fehlgeschlagen', true);
        }
    });
}

// v10.4.0: Dateityp-Filter Definitionen
var ASSET_EXTENSIONS = ['.pdf', '.jpg', '.jpeg', '.png', '.gif', '.svg', '.webp', '.ico', '.css', '.js', '.txt', '.xml', '.json', '.woff', '.woff2', '.ttf', '.eot', '.map', '.zip', '.gz'];
var IMAGE_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.svg', '.webp', '.ico'];
var LANG_PREFIXES = ['/en/', '/fr/', '/es/', '/nl/', '/it/'];

// v10.4.0: Dateityp-Filter setzen
function fpcSeoSetTypeFilter(type) {
    seoScanTypeFilter = type;
    ['pages','all','assets'].forEach(function(t) {
        var btn = document.getElementById('btn-type-' + t);
        if (btn) { btn.style.background = (t === type) ? '' : 'var(--fpc-card2)'; btn.className = 'fpc-btn' + (t === type ? ' teal' : ''); }
    });
    fpcSeoRenderScanTable();
}

// v10.4.0: Sprach-Filter setzen
function fpcSeoSetLangFilter(lang) {
    seoScanLangFilter = lang;
    var langs = ['all','de','en','fr','es','nl','it'];
    langs.forEach(function(l) {
        var id = l === 'all' ? 'btn-lang-all' : 'btn-lang-' + l;
        var btn = document.getElementById(id);
        var match = (l === 'all' && lang === '') || l === lang;
        if (btn) { btn.style.background = match ? '' : 'var(--fpc-card2)'; btn.className = 'fpc-btn' + (match ? ' teal' : ''); }
    });
    fpcSeoRenderScanTable();
}

// v10.4.0: URL-Sprache erkennen
function fpcSeoGetUrlLang(url) {
    for (var i = 0; i < LANG_PREFIXES.length; i++) {
        if (url.indexOf(LANG_PREFIXES[i]) === 0) return LANG_PREFIXES[i].replace(/\//g, '');
    }
    return 'de';
}

// v10.4.0: Gruppierte Ansicht togglen
function fpcSeoToggleGroupView() {
    seoScanGrouped = !seoScanGrouped;
    var btn = document.getElementById('btn-group-toggle');
    if (btn) {
        btn.style.background = seoScanGrouped ? '' : 'var(--fpc-card2)';
        btn.className = 'fpc-btn' + (seoScanGrouped ? ' teal' : '');
    }
    fpcSeoRenderScanTable();
}

// v10.4.0: Basis-Pfad aus URL extrahieren (Sprach-Prefix entfernen)
function fpcSeoGetBasePath(url) {
    for (var i = 0; i < LANG_PREFIXES.length; i++) {
        if (url.indexOf(LANG_PREFIXES[i]) === 0) {
            return url.substring(LANG_PREFIXES[i].length - 1);
        }
    }
    return url;
}

// v10.4.0: Pruefen ob URL ein Asset ist
function fpcSeoIsAsset(url) {
    var lower = url.toLowerCase();
    for (var i = 0; i < ASSET_EXTENSIONS.length; i++) {
        if (lower.indexOf(ASSET_EXTENSIONS[i]) === lower.length - ASSET_EXTENSIONS[i].length) return true;
        if (lower.indexOf(ASSET_EXTENSIONS[i] + '?') !== -1) return true;
    }
    return false;
}

// v10.4.0: Client-seitige Filterung und Rendering
function fpcSeoRenderScanTable() {
    var d = seoScanData;
    if (!d || d.length === 0) {
        document.getElementById('seo-scan-table').innerHTML = '<p style="color:var(--fpc-text2)">Keine Scan-Ergebnisse. Starte einen Scan.</p>';
        document.getElementById('scan-result-count').textContent = '';
        return;
    }

    // Client-seitige Filter anwenden
    var filtered = d.filter(function(r) {
        var isAsset = fpcSeoIsAsset(r.url);
        if (seoScanTypeFilter === 'pages' && isAsset) return false;
        if (seoScanTypeFilter === 'assets' && !isAsset) return false;
        if (seoScanLangFilter !== '') {
            if (fpcSeoGetUrlLang(r.url) !== seoScanLangFilter) return false;
        }
        return true;
    });

    // Sprach-Statistik zaehlen
    var langCounts = {de:0, en:0, fr:0, es:0, nl:0, it:0};
    d.forEach(function(r) { var l = fpcSeoGetUrlLang(r.url); if (langCounts[l] !== undefined) langCounts[l]++; });
    ['de','en','fr','es','nl','it'].forEach(function(l) {
        var btn = document.getElementById('btn-lang-' + l);
        if (btn) btn.textContent = l.toUpperCase() + ' (' + langCounts[l] + ')';
    });

    // Gruppierte oder flache Ansicht?
    if (seoScanGrouped) {
        fpcSeoRenderGroupedTable(filtered);
    } else {
        fpcSeoRenderFlatTable(filtered);
    }
    document.getElementById('scan-result-count').textContent = filtered.length + ' von ' + d.length + ' URLs' + (seoScanGrouped ? ' (gruppiert)' : '');
}

// v10.4.0: Flache Tabelle (Standard-Ansicht)
function fpcSeoRenderFlatTable(filtered) {
    var langColorMap = {de:'#00d4aa', en:'#00a8ff', fr:'#ff6b6b', es:'#ffa726', nl:'#ff9800', it:'#ab47bc'};
    var html = '<table class="fpc-table"><thead><tr><th>URL</th><th>Sprache</th><th>HTTP</th><th>Canonical</th><th>FPC Cache</th><th>Response (ms)</th><th>Status</th><th>Issues</th><th>Aktion</th></tr></thead><tbody>';
    filtered.forEach(function(r) {
        var statusCls = r.status === 'ok' ? 'hit' : (r.status === 'warning' ? 'bypass' : 'miss');
        var escUrl = (r.url || '').replace(/'/g, "\\'");
        var fullUrl = (r.url.indexOf('http') === 0) ? r.url : 'https://mr-hanf.de' + r.url;
        var lang = fpcSeoGetUrlLang(r.url);
        html += '<tr>';
        html += '<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;" title="' + r.url + '"><a href="' + fullUrl + '" target="_blank" style="color:var(--fpc-teal);text-decoration:none;">' + r.url + '</a></td>';
        html += '<td><span style="color:' + (langColorMap[lang]||'#ccc') + ';font-size:11px;font-weight:bold;">' + lang.toUpperCase() + '</span></td>';
        html += '<td><span class="fpc-badge ' + (r.http_status < 300 ? 'hit' : r.http_status < 400 ? 'bypass' : 'miss') + '">' + r.http_status + '</span></td>';
        html += '<td>' + (r.canonical_match === true ? '<span style="color:var(--fpc-green)">OK</span>' : r.canonical_match === false ? '<span style="color:var(--fpc-red)">Mismatch</span>' : '-') + '</td>';
        html += '<td>' + (r.has_fpc_cache ? '<span style="color:var(--fpc-green)">HIT</span>' : '<span style="color:var(--fpc-orange)">MISS</span>') + '</td>';
        html += '<td>' + r.response_time_ms + '</td>';
        html += '<td><span class="fpc-badge ' + statusCls + '">' + r.status + '</span></td>';
        html += '<td style="font-size:11px;">' + (r.issues ? r.issues.join(', ') : '') + '</td>';
        html += '<td style="white-space:nowrap;">';
        html += '<button class="fpc-btn" style="padding:2px 6px;font-size:11px;margin-right:3px;background:var(--fpc-blue);" onclick="fpcSeo404Check(\'' + escUrl + '\')" title="URL pruefen">&#128269;</button>';
        if (r.http_status >= 300 && r.http_status < 400) html += '<button class="fpc-btn green" style="padding:2px 6px;font-size:11px;margin-right:3px;" onclick="fpcSeoScanRedirect(\'' + escUrl + '\')" title="Redirect anlegen">&#8594;</button>';
        if (r.http_status >= 400) html += '<button class="fpc-btn red" style="padding:2px 6px;font-size:11px;" onclick="fpcSeoScanRedirect(\'' + escUrl + '\')" title="Redirect anlegen">&#8594;</button>';
        html += '</td></tr>';
    });
    html += '</tbody></table>';
    document.getElementById('seo-scan-table').innerHTML = html;
}

// v10.4.0: Gruppierte Tabelle - URLs nach Basis-Pfad gruppiert
function fpcSeoRenderGroupedTable(filtered) {
    var langColorMap = {de:'#00d4aa', en:'#00a8ff', fr:'#ff6b6b', es:'#ffa726', nl:'#ff9800', it:'#ab47bc'};
    var langOrder = ['de','en','fr','es','nl','it'];

    // Nach Basis-Pfad gruppieren
    var groups = {};
    var groupOrder = [];
    filtered.forEach(function(r) {
        var base = fpcSeoGetBasePath(r.url);
        if (!groups[base]) {
            groups[base] = {};
            groupOrder.push(base);
        }
        var lang = fpcSeoGetUrlLang(r.url);
        groups[base][lang] = r;
    });

    // Sortieren: Gruppen mit mehreren Sprachen zuerst, dann nach Basis-Pfad
    groupOrder.sort(function(a, b) {
        var aCount = Object.keys(groups[a]).length;
        var bCount = Object.keys(groups[b]).length;
        if (aCount !== bCount) return bCount - aCount;
        return a.localeCompare(b);
    });

    var html = '';
    var multiLangGroups = 0;
    var singleGroups = 0;

    groupOrder.forEach(function(base) {
        var langs = groups[base];
        var langKeys = Object.keys(langs);
        var isMultiLang = langKeys.length > 1;
        if (isMultiLang) multiLangGroups++; else singleGroups++;

        // Worst-Status der Gruppe ermitteln
        var worstStatus = 'ok';
        var worstHttp = 200;
        langKeys.forEach(function(l) {
            var s = langs[l].http_status;
            if (s > worstHttp) worstHttp = s;
            if (langs[l].status === 'error') worstStatus = 'error';
            else if (langs[l].status === 'redirect' && worstStatus !== 'error') worstStatus = 'redirect';
            else if (langs[l].status === 'warning' && worstStatus === 'ok') worstStatus = 'warning';
        });
        var groupColor = worstStatus === 'error' ? 'var(--fpc-red)' : worstStatus === 'redirect' ? 'var(--fpc-blue)' : worstStatus === 'warning' ? 'var(--fpc-orange)' : 'var(--fpc-green)';
        var groupBorder = isMultiLang ? 'border-left:3px solid ' + groupColor + ';' : '';

        // Gruppen-Container
        html += '<div style="background:var(--fpc-card);border-radius:8px;padding:0;margin-bottom:' + (isMultiLang ? '12' : '4') + 'px;border:1px solid var(--fpc-border);' + groupBorder + 'overflow:hidden;">';

        if (isMultiLang) {
            // Gruppen-Header mit Basis-Pfad und Sprach-Badges
            var langBadges = langKeys.sort(function(a,b) { return langOrder.indexOf(a) - langOrder.indexOf(b); }).map(function(l) {
                var c = langColorMap[l] || '#ccc';
                var s = langs[l].http_status;
                return '<span style="display:inline-block;background:' + c + '22;color:' + c + ';font-size:10px;font-weight:bold;padding:2px 6px;border-radius:3px;border:1px solid ' + c + '44;">' + l.toUpperCase() + ' (' + s + ')</span>';
            }).join(' ');

            html += '<div style="padding:10px 14px;background:var(--fpc-card2);display:flex;align-items:center;gap:10px;flex-wrap:wrap;cursor:pointer;" onclick="this.parentElement.querySelector(\'.scan-group-body\').style.display=this.parentElement.querySelector(\'.scan-group-body\').style.display===\'none\'?\'block\':\'none\'">';
            html += '<span style="color:var(--fpc-text);font-weight:bold;font-size:13px;">&#127760; ' + base + '</span>';
            html += '<span style="font-size:11px;color:var(--fpc-text2);">' + langKeys.length + ' Sprachen</span>';
            html += langBadges;
            // Gruppen-Aktionen
            if (worstHttp >= 300) {
                var escBase = base.replace(/'/g, "\\'");
                html += '<button class="fpc-btn" style="padding:3px 8px;font-size:11px;margin-left:auto;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;" onclick="event.stopPropagation();fpcSeoGroupRedirect(\'' + escBase + '\')" title="Redirect fuer ALLE Sprachen dieser URL anlegen">&#127760; Redirect alle Sprachen</button>';
            }
            html += '</div>';

            // Gruppen-Body: Einzelne Sprach-Zeilen
            html += '<div class="scan-group-body" style="display:block;">';
            html += '<table class="fpc-table" style="margin:0;border-radius:0;"><tbody>';
        }

        // Zeilen fuer jede Sprache
        langKeys.sort(function(a,b) { return langOrder.indexOf(a) - langOrder.indexOf(b); }).forEach(function(lang) {
            var r = langs[lang];
            var statusCls = r.status === 'ok' ? 'hit' : (r.status === 'warning' ? 'bypass' : 'miss');
            var escUrl = (r.url || '').replace(/'/g, "\\'");
            var fullUrl = 'https://mr-hanf.de' + r.url;

            if (!isMultiLang) {
                html += '<table class="fpc-table" style="margin:0;border-radius:0;"><tbody>';
            }

            html += '<tr style="' + (isMultiLang ? 'border-left:3px solid ' + (langColorMap[lang]||'#ccc') + ';' : '') + '">';
            html += '<td style="width:30px;text-align:center;"><span style="color:' + (langColorMap[lang]||'#ccc') + ';font-size:11px;font-weight:bold;">' + lang.toUpperCase() + '</span></td>';
            html += '<td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;" title="' + r.url + '"><a href="' + fullUrl + '" target="_blank" style="color:var(--fpc-teal);text-decoration:none;font-size:12px;">' + r.url + '</a></td>';
            html += '<td style="width:50px;"><span class="fpc-badge ' + (r.http_status < 300 ? 'hit' : r.http_status < 400 ? 'bypass' : 'miss') + '">' + r.http_status + '</span></td>';
            html += '<td style="width:60px;">' + (r.has_fpc_cache ? '<span style="color:var(--fpc-green);font-size:11px;">HIT</span>' : '<span style="color:var(--fpc-orange);font-size:11px;">MISS</span>') + '</td>';
            html += '<td style="width:50px;font-size:11px;">' + r.response_time_ms + 'ms</td>';
            html += '<td style="width:60px;"><span class="fpc-badge ' + statusCls + '" style="font-size:10px;">' + r.status + '</span></td>';
            html += '<td style="font-size:10px;color:var(--fpc-text2);">' + (r.issues ? r.issues.join(', ') : '') + '</td>';
            html += '<td style="white-space:nowrap;width:70px;">';
            html += '<button class="fpc-btn" style="padding:2px 5px;font-size:10px;margin-right:2px;background:var(--fpc-blue);" onclick="fpcSeo404Check(\'' + escUrl + '\')" title="Pruefen">&#128269;</button>';
            if (r.http_status >= 300) {
                var btnCls = r.http_status < 400 ? 'green' : 'red';
                html += '<button class="fpc-btn ' + btnCls + '" style="padding:2px 5px;font-size:10px;" onclick="fpcSeoScanRedirect(\'' + escUrl + '\')" title="Redirect">&#8594;</button>';
            }
            html += '</td></tr>';

            if (!isMultiLang) {
                html += '</tbody></table>';
            }
        });

        if (isMultiLang) {
            html += '</tbody></table></div>'; // close group-body
        }
        html += '</div>'; // close group container
    });

    // Zusammenfassung oben
    var summary = '<div style="display:flex;gap:12px;margin-bottom:10px;font-size:12px;color:var(--fpc-text2);">';
    summary += '<span>&#127760; <strong>' + multiLangGroups + '</strong> Sprach-Gruppen</span>';
    summary += '<span>&#128196; <strong>' + singleGroups + '</strong> Einzel-URLs</span>';
    summary += '<span>&#128202; <strong>' + groupOrder.length + '</strong> Basis-Pfade gesamt</span>';
    summary += '</div>';

    document.getElementById('seo-scan-table').innerHTML = summary + html;
}

// v10.4.0: Gruppen-Redirect - fragt nach Ziel und legt fuer ALLE Sprachen an
function fpcSeoGroupRedirect(basePath) {
    var target = prompt('Redirect-Ziel fuer alle Sprachen von:\n' + basePath + '\n\nGib den Basis-Pfad ein (ohne Sprach-Prefix):', '/');
    if (target === null || target === '') return;

    // Alle Sprach-Varianten dieser Basis-URL finden
    var redirects = [];
    var langPrefixes = ['', '/en', '/fr', '/es', '/nl', '/it'];
    var langNames = ['DE', 'EN', 'FR', 'ES', 'NL', 'IT'];

    seoScanData.forEach(function(r) {
        var rBase = fpcSeoGetBasePath(r.url);
        if (rBase === basePath && r.http_status >= 300) {
            var lang = fpcSeoGetUrlLang(r.url);
            var prefix = lang === 'de' ? '' : '/' + lang;
            redirects.push({
                source: r.url,
                target: prefix + target,
                type: '301',
                note: 'Gruppen-Redirect (' + lang.toUpperCase() + ')'
            });
        }
    });

    if (redirects.length === 0) {
        fpcToast('Keine problematischen URLs in dieser Gruppe gefunden', true);
        return;
    }

    var msg = redirects.length + ' Redirects anlegen:\n\n';
    redirects.forEach(function(r) { msg += r.source + ' -> ' + r.target + '\n'; });
    if (!confirm(msg)) return;

    fpcAjaxPostJson('seo_redirect_bulk_add', { redirects: redirects }, function(r) {
        if (r.ok) {
            fpcToast(r.msg);
            fpcSeoLoadRedirects();
            fpcSeoLoadScanResults();
        } else {
            fpcToast(r.msg || 'Fehler', true);
        }
    });
}

function fpcSeoLoadScanResults(filter) {
    if (filter !== undefined) seoScanFilter = filter;
    var search = document.getElementById('scan-search') ? document.getElementById('scan-search').value : '';
    fpcAjax('ajax=seo_scan_results&status=' + encodeURIComponent(seoScanFilter) + '&search=' + encodeURIComponent(search), function(d) {
        seoScanData = d || [];
        fpcSeoRenderScanTable();
    });
}

// v10.4.0: Redirect aus Scan-Ergebnissen anlegen
function fpcSeoScanRedirect(url) {
    var target = prompt('Redirect-Ziel fuer ' + url + ':', '/');
    if (target === null) return;
    fpcAjaxPostJson('seo_redirect_add', { source: url, target: target, type: '301', note: 'Aus Scan-Ergebnis' }, function(r) {
        fpcToast(r.msg, !r.ok);
        fpcSeoLoadRedirects();
        fpcSeoLoadScanResults();
    });
}

// v10.4.0: KI-Redirect-Vorschlaege anfordern
function fpcSeoAiSuggestRedirects() {
    // Nur problematische URLs sammeln (Redirects + Errors)
    var problemUrls = seoScanData.filter(function(r) {
        return r.http_status >= 300 && !fpcSeoIsAsset(r.url);
    }).map(function(r) {
        return { url: r.url, http_status: r.http_status, redirect_target: r.redirect_target || '', issues: r.issues || [] };
    });

    if (problemUrls.length === 0) {
        fpcToast('Keine problematischen URLs gefunden (keine 3xx/4xx Seiten)', true);
        return;
    }

    // Max 50 URLs an KI senden
    if (problemUrls.length > 50) problemUrls = problemUrls.slice(0, 50);

    var btn = document.getElementById('btn-ai-suggest');
    btn.disabled = true;
    btn.innerHTML = '&#129302; Analysiere ' + problemUrls.length + ' URLs...';

    var container = document.getElementById('seo-ai-suggestions');
    container.style.display = 'block';
    container.innerHTML = '<div style="background:var(--fpc-card2);border-radius:8px;padding:16px;border:1px solid var(--fpc-border);"><p style="color:var(--fpc-text2);">&#129302; KI analysiert ' + problemUrls.length + ' problematische URLs... (kann 10-30 Sekunden dauern)</p></div>';

    fpcAjaxPostJson('ai_redirect_suggest', { urls: problemUrls }, function(d) {
        btn.disabled = false;
        btn.innerHTML = '&#129302; KI Redirect-Vorschlaege';

        if (d.error) {
            container.innerHTML = '<div style="background:var(--fpc-card2);border-radius:8px;padding:16px;border:1px solid var(--fpc-red);"><p style="color:var(--fpc-red);">Fehler: ' + d.msg + '</p></div>';
            return;
        }

        if (!d.suggestions || d.suggestions.length === 0) {
            container.innerHTML = '<div style="background:var(--fpc-card2);border-radius:8px;padding:16px;border:1px solid var(--fpc-border);"><p style="color:var(--fpc-text2);">Keine Vorschlaege - die KI konnte keine passenden Redirect-Ziele finden.</p>' + (d.raw ? '<details><summary style="color:var(--fpc-text2);cursor:pointer;font-size:11px;">KI-Antwort anzeigen</summary><pre style="color:var(--fpc-text2);font-size:11px;white-space:pre-wrap;">' + d.raw + '</pre></details>' : '') + '</div>';
            return;
        }

        fpcSeoRenderAiSuggestions(d);
    });
}

// v10.4.0: KI-Vorschlaege rendern
function fpcSeoRenderAiSuggestions(d) {
    var container = document.getElementById('seo-ai-suggestions');
    var html = '<div style="background:var(--fpc-card2);border-radius:8px;padding:16px;border:1px solid var(--fpc-border);">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
    html += '<h3 style="color:var(--fpc-text);margin:0;">&#129302; KI Redirect-Vorschlaege (' + d.suggestions.length + ')</h3>';
    html += '<div style="display:flex;gap:8px;">';
    html += '<button class="fpc-btn green" onclick="fpcSeoApplyAllSuggestions()" title="Alle Vorschlaege uebernehmen">Alle uebernehmen</button>';
    html += '<button class="fpc-btn" style="background:var(--fpc-card);" onclick="document.getElementById(\'seo-ai-suggestions\').style.display=\'none\'">Schliessen</button>';
    html += '</div></div>';

    // Sprach-Gruppen Info
    if (d.grouped_urls > 0) {
        html += '<div style="background:rgba(102,126,234,0.1);border:1px solid rgba(102,126,234,0.3);border-radius:6px;padding:8px 12px;margin-bottom:12px;">';
        html += '<span style="color:#667eea;font-size:12px;">&#127760; ' + d.grouped_urls + ' URLs in Sprach-Gruppen erkannt - Redirects werden fuer alle Sprachen vorgeschlagen</span>';
        html += '</div>';
    }

    html += '<table class="fpc-table" id="ai-suggestions-table"><thead><tr><th style="width:30px;"><input type="checkbox" checked onchange="fpcSeoToggleAllSuggestions(this)"></th><th>Quelle</th><th>Ziel</th><th>Typ</th><th>Konfidenz</th><th>Begruendung</th><th>Sprachen</th></tr></thead><tbody>';

    d.suggestions.forEach(function(s, idx) {
        var confColor = s.confidence === 'high' ? 'var(--fpc-green)' : s.confidence === 'medium' ? 'var(--fpc-orange)' : 'var(--fpc-red)';
        var langHtml = '';
        if (s.language_group && s.language_group.length > 1) {
            langHtml = s.language_group.map(function(lg) {
                return '<span style="color:var(--fpc-teal);font-size:10px;background:var(--fpc-card);padding:1px 4px;border-radius:3px;margin-right:2px;">' + lg.lang.toUpperCase() + '</span>';
            }).join('');
        } else {
            var srcLang = fpcSeoGetUrlLang(s.source);
            langHtml = '<span style="color:var(--fpc-text2);font-size:10px;">' + srcLang.toUpperCase() + '</span>';
        }

        html += '<tr data-idx="' + idx + '" data-source="' + (s.source || '').replace(/"/g, '&quot;') + '" data-target="' + (s.target || '').replace(/"/g, '&quot;') + '" data-type="' + (s.type || '301') + '" data-langgroup="' + encodeURIComponent(JSON.stringify(s.language_group || [])) + '">';
        html += '<td><input type="checkbox" checked class="ai-suggest-check"></td>';
        html += '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;font-size:12px;" title="' + s.source + '"><code style="color:var(--fpc-red);">' + s.source + '</code></td>';
        html += '<td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;font-size:12px;" title="' + s.target + '"><code style="color:var(--fpc-green);">' + s.target + '</code></td>';
        html += '<td><span class="fpc-badge bypass">' + (s.type || '301') + '</span></td>';
        html += '<td><span style="color:' + confColor + ';font-weight:bold;font-size:11px;">' + (s.confidence || '?') + '</span></td>';
        html += '<td style="font-size:11px;color:var(--fpc-text2);">' + (s.reason || '') + '</td>';
        html += '<td>' + langHtml + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table></div>';
    container.innerHTML = html;
    container.style.display = 'block';

    // Vorschlaege global speichern
    window._aiSuggestions = d.suggestions;
}

// v10.4.0: Alle Checkboxen togglen
function fpcSeoToggleAllSuggestions(masterCheckbox) {
    var checks = document.querySelectorAll('.ai-suggest-check');
    checks.forEach(function(cb) { cb.checked = masterCheckbox.checked; });
}

// v10.4.0: Ausgewaehlte Vorschlaege uebernehmen (inkl. Sprach-Gruppen)
function fpcSeoApplyAllSuggestions() {
    var rows = document.querySelectorAll('#ai-suggestions-table tbody tr');
    var redirects = [];

    rows.forEach(function(row) {
        var cb = row.querySelector('.ai-suggest-check');
        if (!cb || !cb.checked) return;

        var source = row.getAttribute('data-source');
        var target = row.getAttribute('data-target');
        var type = row.getAttribute('data-type') || '301';
        var langGroup = [];
        try { langGroup = JSON.parse(decodeURIComponent(row.getAttribute('data-langgroup'))); } catch(e) {}

        // Wenn Sprach-Gruppe vorhanden, alle Sprachen als separate Redirects
        if (langGroup && langGroup.length > 1) {
            langGroup.forEach(function(lg) {
                redirects.push({ source: lg.source, target: lg.target, type: type, note: 'KI-Vorschlag (' + lg.lang.toUpperCase() + ')' });
            });
        } else {
            redirects.push({ source: source, target: target, type: type, note: 'KI-Vorschlag' });
        }
    });

    if (redirects.length === 0) {
        fpcToast('Keine Vorschlaege ausgewaehlt', true);
        return;
    }

    if (!confirm(redirects.length + ' Redirects anlegen (inkl. Sprach-Varianten)?')) return;

    fpcAjaxPostJson('seo_redirect_bulk_add', { redirects: redirects }, function(r) {
        if (r.ok) {
            fpcToast(r.msg);
            document.getElementById('seo-ai-suggestions').style.display = 'none';
            fpcSeoLoadRedirects();
            fpcSeoLoadScanResults();
        } else {
            fpcToast(r.msg || 'Fehler beim Anlegen', true);
        }
    });
}

// --- PROBLEME ---
function fpcSeoLoadProblems() {
    fpcAjax('ajax=seo_problems', function(d) {
        if (!d || d.length === 0) { document.getElementById('seo-problems').innerHTML = '<p style="color:var(--fpc-green)">Keine Cross-API Probleme erkannt.</p>'; return; }
        var html = '<div style="background:var(--fpc-card);border-radius:10px;padding:16px;border:1px solid var(--fpc-border);">';
        d.forEach(function(p, i) {
            if (i >= 20) return;
            var sevColor = p.severity === 'critical' ? 'var(--fpc-red)' : 'var(--fpc-orange)';
            html += '<div style="padding:10px 0;border-bottom:1px solid var(--fpc-border);">';
            html += '<span style="color:' + sevColor + ';font-weight:bold;">' + (p.severity === 'critical' ? '&#9888; KRITISCH' : '&#9888; WARNUNG') + '</span> ';
            html += '<span style="color:var(--fpc-text);font-weight:bold;">[' + p.type + ']</span> ';
            html += '<span style="color:var(--fpc-text2);">' + p.description + '</span>';
            if (p.url) html += '<br><code style="color:var(--fpc-teal);font-size:12px;">' + p.url + '</code>';
            if (p.suggestion) html += '<br><span style="color:var(--fpc-blue);font-size:12px;">Vorschlag: ' + p.suggestion + '</span>';
            html += '</div>';
        });
        html += '</div>';
        html += '<p style="color:var(--fpc-text2);font-size:12px;margin-top:4px;">' + d.length + ' Probleme erkannt</p>';
        document.getElementById('seo-problems').innerHTML = html;
    });
}

// --- AI ANALYSE ---
function fpcSeoAiAnalysis(force) {
    document.getElementById('seo-ai-result').innerHTML = '<p style="color:var(--fpc-text2);">Analyse laeuft... (kann 10-30 Sekunden dauern)</p>';
    document.getElementById('btn-ai-analyze').disabled = true;
    fpcAjax('ajax=ai_analysis' + (force ? '&force=1' : ''), function(d) {
        document.getElementById('btn-ai-analyze').disabled = false;
        if (d.error) {
            document.getElementById('seo-ai-result').innerHTML = '<p style="color:var(--fpc-red);">' + d.msg + '</p>';
            return;
        }
        var html = '';
        if (d.type === 'analysis' && d.data) {
            var a = d.data;
            if (a.summary) html += '<p style="color:var(--fpc-text);font-weight:bold;margin-bottom:8px;">' + a.summary + '</p>';
            if (a.score_assessment) html += '<p style="color:var(--fpc-text2);margin-bottom:12px;">' + a.score_assessment + '</p>';
            if (a.critical_issues && a.critical_issues.length > 0) {
                html += '<h4 style="color:var(--fpc-red);margin:12px 0 8px 0;">Kritische Probleme:</h4>';
                a.critical_issues.forEach(function(issue) {
                    var impactColor = issue.impact === 'high' ? 'var(--fpc-red)' : issue.impact === 'medium' ? 'var(--fpc-orange)' : 'var(--fpc-text2)';
                    html += '<div style="padding:8px;margin-bottom:6px;background:rgba(255,71,87,0.1);border-radius:6px;border-left:3px solid ' + impactColor + ';">';
                    html += '<strong>' + issue.title + '</strong><br>';
                    html += '<span style="font-size:12px;">' + issue.description + '</span>';
                    if (issue.affected_url) html += '<br><code style="font-size:11px;">' + issue.affected_url + '</code>';
                    if (issue.action_details && issue.action_details.source) {
                        html += '<br><button class="fpc-btn green" style="padding:2px 8px;font-size:11px;margin-top:4px;" onclick="fpcSeoApplyAiAction(\'' + issue.action_details.source.replace(/'/g, "\\'") + '\',\'' + issue.action_details.target.replace(/'/g, "\\'") + '\',\'' + (issue.action_details.type || '301') + '\')">Redirect anwenden</button>';
                    }
                    html += '</div>';
                });
            }
            if (a.recommendations && a.recommendations.length > 0) {
                html += '<h4 style="color:var(--fpc-blue);margin:12px 0 8px 0;">Empfehlungen:</h4>';
                a.recommendations.forEach(function(rec) {
                    html += '<div style="padding:6px;margin-bottom:4px;border-left:3px solid var(--fpc-blue);padding-left:10px;">';
                    html += '<strong>' + rec.title + '</strong> <span style="font-size:11px;color:var(--fpc-text2);">[Prioritaet: ' + rec.priority + ' | Aufwand: ' + rec.effort + ']</span>';
                    html += '<br><span style="font-size:12px;">' + rec.description + '</span>';
                    html += '</div>';
                });
            }
            if (a.positive_findings && a.positive_findings.length > 0) {
                html += '<h4 style="color:var(--fpc-green);margin:12px 0 8px 0;">Positive Befunde:</h4>';
                a.positive_findings.forEach(function(f) { html += '<p style="color:var(--fpc-green);font-size:12px;">&#10003; ' + f + '</p>'; });
            }
        } else {
            html = '<div style="white-space:pre-wrap;">' + (d.raw || 'Keine Analyse-Daten') + '</div>';
        }
        html += '<p style="color:var(--fpc-text2);font-size:11px;margin-top:8px;">Analysiert: ' + (d.timestamp || '') + '</p>';
        document.getElementById('seo-ai-result').innerHTML = html;
    });
}

function fpcSeoApplyAiAction(source, target, type) {
    fpcAjaxPostJson('seo_redirect_add', { source: source, target: target, type: type, note: 'KI-Empfehlung' }, function(r) {
        fpcToast(r.msg, !r.ok);
        fpcSeoLoadRedirects();
    });
}

// --- AI CHAT ---
function fpcSeoChatSend() {
    var input = document.getElementById('seo-chat-input');
    var msg = input.value.trim();
    if (!msg) return;
    input.value = '';

    var container = document.getElementById('seo-chat-messages');
    container.innerHTML += '<div style="margin-bottom:8px;text-align:right;"><span style="background:var(--fpc-blue);color:#fff;padding:6px 12px;border-radius:12px 12px 0 12px;display:inline-block;max-width:80%;">' + msg + '</span></div>';
    container.innerHTML += '<div id="chat-typing" style="margin-bottom:8px;"><span style="background:var(--fpc-card);border:1px solid var(--fpc-border);color:var(--fpc-text2);padding:6px 12px;border-radius:12px 12px 12px 0;display:inline-block;">Denke nach...</span></div>';
    container.scrollTop = container.scrollHeight;

    fpcAjaxPostJson('ai_chat', { message: msg }, function(d) {
        var typing = document.getElementById('chat-typing');
        if (typing) typing.remove();
        if (d.error) {
            container.innerHTML += '<div style="margin-bottom:8px;"><span style="background:rgba(255,71,87,0.2);color:var(--fpc-red);padding:6px 12px;border-radius:12px 12px 12px 0;display:inline-block;">' + d.msg + '</span></div>';
        } else {
            var answer = (d.answer || '').replace(/\n/g, '<br>');
            container.innerHTML += '<div style="margin-bottom:8px;"><span style="background:var(--fpc-card);border:1px solid var(--fpc-border);color:var(--fpc-text);padding:6px 12px;border-radius:12px 12px 12px 0;display:inline-block;max-width:80%;text-align:left;">' + answer + '</span></div>';
        }
        container.scrollTop = container.scrollHeight;
    });
}

function fpcSeoChatLoadHistory() {
    fpcAjax('ajax=ai_chat_history', function(d) {
        if (!d || d.length === 0) {
            document.getElementById('seo-chat-messages').innerHTML = '<p style="color:var(--fpc-text2);text-align:center;margin-top:40px;">Stelle eine SEO-Frage...</p>';
            return;
        }
        var html = '';
        d.forEach(function(h) {
            html += '<div style="margin-bottom:8px;text-align:right;"><span style="background:var(--fpc-blue);color:#fff;padding:6px 12px;border-radius:12px 12px 0 12px;display:inline-block;max-width:80%;">' + h.question + '</span></div>';
            var answer = (h.answer || '').replace(/\n/g, '<br>');
            html += '<div style="margin-bottom:8px;"><span style="background:var(--fpc-card);border:1px solid var(--fpc-border);color:var(--fpc-text);padding:6px 12px;border-radius:12px 12px 12px 0;display:inline-block;max-width:80%;text-align:left;">' + answer + '</span></div>';
        });
        document.getElementById('seo-chat-messages').innerHTML = html;
        var container = document.getElementById('seo-chat-messages');
        container.scrollTop = container.scrollHeight;
    });
}

function fpcSeoChatClear() {
    if (!confirm('Chat-Verlauf wirklich loeschen?')) return;
    fpcAjax('ajax=ai_chat_clear', function(r) {
        fpcToast(r.msg, !r.ok);
        document.getElementById('seo-chat-messages').innerHTML = '<p style="color:var(--fpc-text2);text-align:center;margin-top:40px;">Stelle eine SEO-Frage...</p>';
    });
}

// --- CSV EXPORT ---
function fpcSeoExportCsv() {
    window.open(BASE + '?ajax=seo_export_csv', '_blank');
}

// --- FILE EDITOR (.htaccess + robots.txt) ---
var fpcFileEditorCurrent = 'htaccess';
var fpcFileEditorOriginal = '';

function fpcFileEditorLoad(fileKey) {
    fpcFileEditorCurrent = fileKey;
    // Button-Styling
    document.getElementById('btn-file-htaccess').className = 'fpc-btn ' + (fileKey === 'htaccess' ? 'teal active' : 'teal');
    document.getElementById('btn-file-robots').className = 'fpc-btn ' + (fileKey === 'robots' ? 'blue active' : 'blue');
    // Warning
    document.getElementById('file-editor-warning').style.display = (fileKey === 'htaccess') ? 'block' : 'none';
    // Backups ausblenden
    document.getElementById('file-editor-backups').style.display = 'none';
    // Laden
    document.getElementById('file-editor-content').value = 'Lade...';
    document.getElementById('file-editor-status').innerHTML = '';
    fpcAjax('ajax=file_read&file=' + fileKey, function(d) {
        if (d.error) {
            document.getElementById('file-editor-content').value = '';
            document.getElementById('file-editor-status').innerHTML = '<span style="color:var(--fpc-red)">' + (d.msg || 'Fehler') + '</span>';
            return;
        }
        document.getElementById('file-editor-name').textContent = d.file || fileKey;
        document.getElementById('file-editor-meta').textContent = d.size + ' Bytes | Geaendert: ' + d.modified + (d.writable ? ' | Beschreibbar' : ' | NICHT beschreibbar!');
        document.getElementById('file-editor-content').value = d.content || '';
        fpcFileEditorOriginal = d.content || '';
        fpcFileEditorUpdateLines();
    });
}

function fpcFileEditorUpdateLines() {
    var content = document.getElementById('file-editor-content').value;
    var lines = content.split('\n').length;
    var chars = content.length;
    document.getElementById('file-editor-lines').textContent = lines + ' Zeilen | ' + chars + ' Zeichen';
    // Aenderungs-Indikator
    if (content !== fpcFileEditorOriginal) {
        document.getElementById('file-editor-status').innerHTML = '<span style="color:var(--fpc-orange)">Ungespeicherte Aenderungen</span>';
    } else {
        document.getElementById('file-editor-status').innerHTML = '<span style="color:var(--fpc-green)">Aktuell</span>';
    }
}

// Zeilen-Counter bei Eingabe aktualisieren
document.getElementById('file-editor-content').addEventListener('input', fpcFileEditorUpdateLines);

// Tab-Taste im Editor ermoeglichen
document.getElementById('file-editor-content').addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        var ta = this;
        var start = ta.selectionStart;
        var end = ta.selectionEnd;
        ta.value = ta.value.substring(0, start) + '    ' + ta.value.substring(end);
        ta.selectionStart = ta.selectionEnd = start + 4;
        fpcFileEditorUpdateLines();
    }
    // Strg+S zum Speichern
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        fpcFileEditorSave();
    }
});

function fpcFileEditorSave() {
    var content = document.getElementById('file-editor-content').value;
    if (content === fpcFileEditorOriginal) {
        fpcToast('Keine Aenderungen vorhanden');
        return;
    }
    if (fpcFileEditorCurrent === 'htaccess') {
        if (!confirm('Sicher? Fehlerhafte .htaccess kann die Website unzugaenglich machen. Ein Backup wird automatisch erstellt.')) return;
    }
    document.getElementById('file-editor-status').innerHTML = '<span style="color:var(--fpc-cyan)">Speichere...</span>';
    fpcAjaxPostJson('file_save', { file: fpcFileEditorCurrent, content: content }, function(r) {
        if (r.ok) {
            fpcToast(r.msg);
            fpcFileEditorOriginal = content;
            document.getElementById('file-editor-status').innerHTML = '<span style="color:var(--fpc-green)">Gespeichert</span>';
        } else {
            fpcToast(r.msg || 'Fehler beim Speichern', true);
            document.getElementById('file-editor-status').innerHTML = '<span style="color:var(--fpc-red)">Fehler!</span>';
        }
    });
}

function fpcFileEditorReload() {
    if (document.getElementById('file-editor-content').value !== fpcFileEditorOriginal) {
        if (!confirm('Ungespeicherte Aenderungen verwerfen?')) return;
    }
    fpcFileEditorLoad(fpcFileEditorCurrent);
}

function fpcFileEditorShowBackups() {
    var panel = document.getElementById('file-editor-backups');
    if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
    panel.style.display = 'block';
    document.getElementById('file-editor-backups-list').innerHTML = '<span style="color:var(--fpc-text2)">Lade Backups...</span>';
    fpcAjax('ajax=file_backups&file=' + fpcFileEditorCurrent, function(backups) {
        if (!backups || backups.length === 0) {
            document.getElementById('file-editor-backups-list').innerHTML = '<span style="color:var(--fpc-text2)">Keine Backups vorhanden</span>';
            return;
        }
        var html = '<table class="fpc-table"><thead><tr><th>Datum</th><th>Groesse</th><th>Aktion</th></tr></thead><tbody>';
        backups.forEach(function(b) {
            html += '<tr>';
            html += '<td>' + b.date + '</td>';
            html += '<td>' + b.size + ' Bytes</td>';
            html += '<td><button class="fpc-btn teal" onclick="fpcFileEditorRestore(\'' + b.name + '\')">Wiederherstellen</button></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('file-editor-backups-list').innerHTML = html;
    });
}

function fpcFileEditorRestore(backupName) {
    if (!confirm('Aktuellen Inhalt mit diesem Backup ueberschreiben? Der aktuelle Stand wird vorher gesichert.')) return;
    fpcAjaxPostJson('file_restore', { file: fpcFileEditorCurrent, backup: backupName }, function(r) {
        if (r.ok) {
            fpcToast(r.msg);
            fpcFileEditorLoad(fpcFileEditorCurrent);
        } else {
            fpcToast(r.msg || 'Restore fehlgeschlagen', true);
        }
    });
}
</script>
<script>
// ============================================================
// TAB 9: INSPECTOR
// ============================================================
function fpcLoadInspector(filter) {
    filter = filter || '';
    fpcAjax('ajax=inspector&count=100&filter=' + encodeURIComponent(filter), function(d) {
        var html = '<table class="fpc-table"><thead><tr><th>Zeit</th><th>URL</th><th>Status</th><th>Grund</th><th>TTFB</th><th>Bot</th><th>HTTP</th></tr></thead><tbody>';
        d.requests.forEach(function(r) {
            var statusCls = r.status === 'HIT' ? 'hit' : (r.status === 'BYPASS' ? 'bypass' : 'miss');
            var time = r.ts ? new Date(r.ts * 1000).toLocaleTimeString('de-DE') : '-';
            html += '<tr>';
            html += '<td style="white-space:nowrap">' + time + '</td>';
            html += '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (r.url || '') + '">' + (r.url || '-') + '</td>';
            html += '<td><span class="fpc-badge ' + statusCls + '">' + (r.status || '-') + '</span></td>';
            html += '<td>' + (r.reason || '-') + '</td>';
            html += '<td>' + (r.ttfb || '-') + ' ms</td>';
            html += '<td>' + (r.bot ? '<span class="fpc-badge bot">' + (r.bot_name || 'Bot') + '</span>' : '-') + '</td>';
            html += '<td>' + (r.http_code || '-') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        if (d.requests.length === 0) html = '<p style="color:var(--fpc-text2)">No requests found. Make sure request logging is enabled in fpc_serve.php.</p>';
        else html += '<p style="color:var(--fpc-text2);font-size:12px;margin-top:8px;">' + d.total + ' Requests im Log | Zeige letzte ' + d.requests.length + '</p>';
        document.getElementById('inspector-table').innerHTML = html;
    });
}

// ============================================================
// TAB 10: HEALTH
// ============================================================
function fpcLoadHealth() {
    fpcAjax('ajax=healthcheck', function(d) {
        if (!d.available) {
            document.getElementById('health-score-box').innerHTML = '<p style="color:var(--fpc-orange)">No health check performed yet. Click "Run Check Now".</p>';
            document.getElementById('health-details').innerHTML = '';
            return;
        }
        var hc = d.data;
        var latest = hc.latest || {};
        var summary = latest.summary || {};
        var score = summary.health_score || 0;
        var grade = summary.health_grade || '-';
        var color = score >= 80 ? 'var(--fpc-green)' : score >= 60 ? 'var(--fpc-orange)' : 'var(--fpc-red)';
        var html = '<div style="display:flex;align-items:center;gap:20px;background:var(--fpc-card);padding:20px;border-radius:10px;border:1px solid var(--fpc-border);">';
        html += '<div style="font-size:64px;font-weight:900;color:' + color + '">' + grade + '</div>';
        html += '<div><div style="font-size:24px;font-weight:700;color:' + color + '">' + score + ' / 100</div>';
        html += '<div style="color:var(--fpc-text2)">Checked: ' + (summary.timestamp || '-') + '</div>';
        html += '<div style="color:var(--fpc-text2)">Tested: ' + (summary.tested || 0) + ' / ' + (summary.total_cached || '-') + ' cached pages</div>';
        html += '<div style="color:var(--fpc-text2)">Hit Rate: ' + (summary.hit_rate || 0) + '% | Avg TTFB: ' + (summary.avg_ttfb || 0) + 'ms | Errors: ' + (summary.errors || 0) + '</div>';
        html += '</div></div>';
        // SSL Info
        if (summary.ssl) {
            var ssl = summary.ssl;
            var sslColor = ssl.valid ? 'var(--fpc-green)' : 'var(--fpc-red)';
            html += '<div style="margin-top:12px;padding:12px 16px;background:var(--fpc-card);border-radius:8px;border:1px solid var(--fpc-border);display:flex;gap:20px;align-items:center;">';
            html += '<span style="color:' + sslColor + ';font-weight:700;">SSL ' + (ssl.valid ? 'OK' : 'ERROR') + '</span>';
            html += '<span style="color:var(--fpc-text2)">Issuer: ' + (ssl.issuer || '-') + '</span>';
            html += '<span style="color:var(--fpc-text2)">Expires: ' + (ssl.expires || '-') + ' (' + (ssl.days_left || 0) + ' days left)</span>';
            html += '</div>';
        }
        document.getElementById('health-score-box').innerHTML = html;
        // Summary KPIs
        var kpiHtml = '<div class="fpc-kpis" style="margin-bottom:16px;">';
        kpiHtml += '<div class="fpc-kpi"><div class="fpc-kpi-label">TESTED</div><div class="fpc-kpi-value">' + (summary.tested || 0) + '</div></div>';
        kpiHtml += '<div class="fpc-kpi"><div class="fpc-kpi-label">OK</div><div class="fpc-kpi-value" style="color:var(--fpc-green)">' + (summary.ok || 0) + '</div></div>';
        kpiHtml += '<div class="fpc-kpi"><div class="fpc-kpi-label">ERRORS</div><div class="fpc-kpi-value" style="color:' + ((summary.errors || 0) > 0 ? 'var(--fpc-red)' : 'var(--fpc-green)') + '">' + (summary.errors || 0) + '</div></div>';
        kpiHtml += '<div class="fpc-kpi"><div class="fpc-kpi-label">REDIRECTS</div><div class="fpc-kpi-value" style="color:var(--fpc-orange)">' + (summary.redirects || 0) + '</div></div>';
        kpiHtml += '<div class="fpc-kpi"><div class="fpc-kpi-label">SLOW (>2s)</div><div class="fpc-kpi-value">' + (summary.slow_count || 0) + '</div></div>';
        kpiHtml += '<div class="fpc-kpi"><div class="fpc-kpi-label">STALE (>24h)</div><div class="fpc-kpi-value">' + (summary.stale_count || 0) + '</div></div>';
        kpiHtml += '<div class="fpc-kpi"><div class="fpc-kpi-label">AVG TTFB</div><div class="fpc-kpi-value">' + (summary.avg_ttfb || 0) + 'ms</div></div>';
        kpiHtml += '<div class="fpc-kpi"><div class="fpc-kpi-label">TTFB RANGE</div><div class="fpc-kpi-value">' + (summary.ttfb_min || 0) + '-' + (summary.ttfb_max || 0) + 'ms</div></div>';
        kpiHtml += '</div>';
        // Details table from results array
        var results = latest.results || [];
        var detailHtml = kpiHtml;
        if (results.length === 0) {
            detailHtml += '<p style="color:var(--fpc-text2)">No detailed results available.</p>';
        } else {
            // Show errors/warnings first, then OK
            var sorted = results.slice().sort(function(a,b) {
                var sevOrder = {critical:0, error:1, warning:2, ok:3};
                return (sevOrder[a.severity]||3) - (sevOrder[b.severity]||3);
            });
            detailHtml += '<table class="fpc-table"><thead><tr><th>URL</th><th>HTTP</th><th>FPC</th><th>TTFB</th><th>Status</th><th>Issues</th></tr></thead><tbody>';
            sorted.forEach(function(r) {
                var sevClass = r.severity === 'ok' ? 'ok' : (r.severity === 'warning' ? 'warn' : 'error');
                var issues = (r.issues && r.issues.length > 0) ? r.issues.join('; ') : '-';
                detailHtml += '<tr><td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + r.path + '">' + r.path + '</td>';
                detailHtml += '<td>' + r.http + '</td>';
                detailHtml += '<td><span class="sev-' + (r.fpc === 'HIT' ? 'ok' : 'error') + '">' + r.fpc + '</span></td>';
                detailHtml += '<td>' + r.ttfb + 'ms</td>';
                detailHtml += '<td><span class="sev-' + sevClass + '">' + r.severity.toUpperCase() + '</span></td>';
                detailHtml += '<td style="color:var(--fpc-text2);font-size:12px;">' + issues + '</td></tr>';
            });
            detailHtml += '</tbody></table>';
        }
        document.getElementById('health-details').innerHTML = detailHtml;
    });
    // htaccess Validator
    fpcAjax('ajax=validate_htaccess', function(d) {
        var html = '<table class="fpc-table"><thead><tr><th>Check</th><th>Status</th><th>Info</th></tr></thead><tbody>';
        d.checks.forEach(function(c) {
            html += '<tr><td>' + c.name + '</td><td><span class="sev-' + (c.ok ? 'ok' : 'error') + '">' + (c.ok ? 'OK' : 'MISSING') + '</span></td><td style="color:var(--fpc-text2)">' + (c.info || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        html += '<p style="color:var(--fpc-text2);font-size:12px;margin-top:8px;">Score: ' + d.score + '/100 | ' + d.msg + '</p>';
        document.getElementById('health-htaccess').innerHTML = html;
    });
}

function fpcRunHealthcheck() {
    fpcToast('Running health check...');
    fpcAjaxPost('run_healthcheck', '', function(r) {
        fpcToast(r.msg, !r.ok);
        if (r.ok) setTimeout(fpcLoadHealth, 2000);
    });
}

// ============================================================
// TAB 11: STATISTIK
// ============================================================
function fpcLoadStats() {
    var days = document.getElementById('stats-days') ? document.getElementById('stats-days').value : 30;
    fpcAjax('ajax=visitor_stats&days=' + days, function(d) {
        var kpis = '';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Pageviews</div><div class="fpc-kpi-value" style="color:var(--fpc-teal)">' + d.total_pageviews.toLocaleString() + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Visitors</div><div class="fpc-kpi-value">' + d.total_visitors.toLocaleString() + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Bounce Rate</div><div class="fpc-kpi-value" style="color:' + (d.bounce_rate > 60 ? 'var(--fpc-red)' : d.bounce_rate > 40 ? 'var(--fpc-orange)' : 'var(--fpc-green)') + '">' + d.bounce_rate + '%</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Avg Duration</div><div class="fpc-kpi-value">' + d.avg_duration + 's</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Desktop</div><div class="fpc-kpi-value">' + d.devices.desktop + '</div></div>';
        kpis += '<div class="fpc-kpi"><div class="fpc-kpi-label">Mobile</div><div class="fpc-kpi-value">' + d.devices.mobile + '</div></div>';
        document.getElementById('stats-kpis').innerHTML = kpis;
        if (typeof Chart !== 'undefined' && d.daily.length > 0) {
            var dLabels = d.daily.map(function(x) { return x.date.substring(5); });
            fpcMakeChart('chart-stats-daily', { type: 'line', data: { labels: dLabels, datasets: [{ label: 'Pageviews', data: d.daily.map(function(x) { return x.pageviews; }), borderColor: '#00d4aa', backgroundColor: 'rgba(0,212,170,0.1)', fill: true, tension: 0.3 }, { label: 'Visitors', data: d.daily.map(function(x) { return x.visitors; }), borderColor: '#00a8ff', fill: false, tension: 0.3 }] }, options: { responsive: true } });
            var hLabels = []; var hData = [];
            for (var h = 0; h < 24; h++) { hLabels.push(h + ':00'); hData.push(d.hours[h] || 0); }
            fpcMakeChart('chart-stats-hourly', { type: 'bar', data: { labels: hLabels, datasets: [{ label: 'Views', data: hData, backgroundColor: '#00d4aa' }] }, options: { responsive: true } });
            fpcMakeChart('chart-stats-devices', { type: 'doughnut', data: { labels: ['Desktop', 'Mobile', 'Tablet'], datasets: [{ data: [d.devices.desktop, d.devices.mobile, d.devices.tablet], backgroundColor: ['#00a8ff', '#00e676', '#ffa502'] }] }, options: { responsive: true } });
            fpcMakeChart('chart-stats-bounce', { type: 'line', data: { labels: dLabels, datasets: [{ label: 'Bounce Rate %', data: d.daily.map(function(x) { return x.bounce_rate; }), borderColor: '#ff4757', fill: false, tension: 0.3 }] }, options: { responsive: true, scales: { y: { min: 0, max: 100 } } } });
        }
        // Top Pages
        var html = '<table class="fpc-table"><thead><tr><th>Page</th><th>Views</th></tr></thead><tbody>';
        for (var page in d.top_pages) { html += '<tr><td>' + page + '</td><td>' + d.top_pages[page] + '</td></tr>'; }
        html += '</tbody></table>';
        document.getElementById('stats-top-pages').innerHTML = html;
        // Top Referrers
        html = '<table class="fpc-table"><thead><tr><th>Referrer</th><th>Views</th></tr></thead><tbody>';
        for (var ref in d.top_referrers) { html += '<tr><td>' + ref + '</td><td>' + d.top_referrers[ref] + '</td></tr>'; }
        html += '</tbody></table>';
        document.getElementById('stats-top-referrers').innerHTML = html;
    });
}

// ============================================================
// TAB 12: ALERTS
// ============================================================
function fpcLoadAlerts() {
    fpcAjax('ajax=alerts_config', function(d) {
        if (document.getElementById('alert-hitrate')) document.getElementById('alert-hitrate').value = d.hit_rate_min || 70;
        if (document.getElementById('alert-empty')) document.getElementById('alert-empty').value = d.cache_empty_minutes || 30;
        if (document.getElementById('alert-errors')) document.getElementById('alert-errors').value = d.error_threshold || 10;
        if (document.getElementById('alert-email')) document.getElementById('alert-email').value = d.email || '';
        if (document.getElementById('alert-enabled')) document.getElementById('alert-enabled').checked = d.enabled || false;
        if (document.getElementById('alert-preloader-stop')) document.getElementById('alert-preloader-stop').checked = d.preloader_stop_alert !== false;
    });
    fpcAjax('ajax=alerts_history', function(d) {
        if (!d.alerts || d.alerts.length === 0) { document.getElementById('alerts-history').innerHTML = '<p style="color:var(--fpc-text2)">No alerts yet.</p>'; return; }
        var html = '<table class="fpc-table"><thead><tr><th>Time</th><th>Type</th><th>Message</th></tr></thead><tbody>';
        d.alerts.reverse().forEach(function(a) {
            html += '<tr><td>' + (a.timestamp || '-') + '</td><td><span class="sev-' + (a.severity || 'warning') + '">' + (a.type || '-') + '</span></td><td>' + (a.msg || '-') + '</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('alerts-history').innerHTML = html;
    });
}

function fpcSaveAlerts() {
    var cfg = {
        hit_rate_min: parseInt(document.getElementById('alert-hitrate').value) || 70,
        cache_empty_minutes: parseInt(document.getElementById('alert-empty').value) || 30,
        error_threshold: parseInt(document.getElementById('alert-errors').value) || 10,
        email: document.getElementById('alert-email').value || '',
        enabled: document.getElementById('alert-enabled').checked,
        preloader_stop_alert: document.getElementById('alert-preloader-stop').checked
    };
    fpcAjaxPostJson('alerts_config', cfg, function(r) { fpcToast(r.msg, !r.ok); });
}

// ============================================================
// TAB 13: SETTINGS
// ============================================================
function fpcLoadSettings() {
    fpcAjax('ajax=settings_load', function(d) {
        // DB Settings
        var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
        d.db.forEach(function(s) {
            html += '<div>';
            html += '<label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">' + s.label + '</label>';
            if (s.type === 'boolean') {
                html += '<select class="fpc-input" data-db-key="' + s.key + '" style="width:100%;">';
                html += '<option value="True"' + (s.value === 'True' ? ' selected' : '') + '>True</option>';
                html += '<option value="False"' + (s.value === 'False' ? ' selected' : '') + '>False</option>';
                html += '</select>';
            } else if (s.type === 'number') {
                html += '<input type="number" class="fpc-input" data-db-key="' + s.key + '" value="' + s.value + '" style="width:100%;">';
            } else {
                html += '<input type="text" class="fpc-input" data-db-key="' + s.key + '" value="' + s.value + '" style="width:100%;">';
            }
            html += '<small style="color:var(--fpc-text2);font-size:11px;">' + s.desc + '</small>';
            html += '</div>';
        });
        html += '</div>';
        document.getElementById('settings-db').innerHTML = html;

        // Preloader Settings
        var p = d.preloader;
        var preloaderFields = [
            {key:'request_delay_ms', label:'Request Delay (ms)', desc:'Minimum pause between requests', val:p.request_delay_ms, type:'number'},
            {key:'load_threshold', label:'Server Load Threshold', desc:'Pause preloader when load exceeds this', val:p.load_threshold, type:'number', step:'0.1'},
            {key:'load_pause_sec', label:'Load Pause (sec)', desc:'Pause duration when load is too high', val:p.load_pause_sec, type:'number'},
            {key:'batch_size', label:'Batch Size', desc:'Pause after this many requests', val:p.batch_size, type:'number'},
            {key:'batch_pause_sec', label:'Batch Pause (sec)', desc:'Pause duration between batches', val:p.batch_pause_sec, type:'number'},
            {key:'slow_threshold_ms', label:'Slow Threshold (ms)', desc:'Double delay when TTFB exceeds this', val:p.slow_threshold_ms, type:'number'},
            {key:'max_runtime_sec', label:'Max Runtime (sec)', desc:'Maximum preloader runtime per cron run (default: 7200 = 2h)', val:p.max_runtime_sec, type:'number'},
            {key:'min_html_size', label:'Min HTML Size (bytes)', desc:'Minimum valid HTML file size', val:p.min_html_size, type:'number'},
            {key:'max_error_rate', label:'Max Error Rate', desc:'Stop if error rate exceeds this (0.20 = 20%)', val:p.max_error_rate, type:'number', step:'0.01'},
            {key:'adaptive_enabled', label:'Adaptive Throttling', desc:'Auto-adjust delay based on response time', val:p.adaptive_enabled, type:'bool'},
            {key:'require_doctype', label:'Require DOCTYPE', desc:'Validate DOCTYPE in cached HTML', val:p.require_doctype, type:'bool'},
            {key:'require_body', label:'Require Body Tag', desc:'Validate body tag in cached HTML', val:p.require_body, type:'bool'},
            {key:'verify_after_write', label:'Verify After Write', desc:'Re-read and validate cache file after writing', val:p.verify_after_write, type:'bool'},
        ];
        html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
        preloaderFields.forEach(function(f) {
            html += '<div>';
            html += '<label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">' + f.label + '</label>';
            if (f.type === 'bool') {
                html += '<select class="fpc-input" id="set-pl-' + f.key + '" style="width:100%;">';
                html += '<option value="true"' + (f.val ? ' selected' : '') + '>Enabled</option>';
                html += '<option value="false"' + (!f.val ? ' selected' : '') + '>Disabled</option>';
                html += '</select>';
            } else {
                html += '<input type="number" class="fpc-input" id="set-pl-' + f.key + '" value="' + f.val + '"' + (f.step ? ' step="' + f.step + '"' : '') + ' style="width:100%;">';
            }
            html += '<small style="color:var(--fpc-text2);font-size:11px;">' + f.desc + '</small>';
            html += '</div>';
        });
        html += '</div>';
        document.getElementById('settings-preloader').innerHTML = html;

        // Serve Settings
        var sv = d.serve;
        html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">Min File Size (bytes)</label><input type="number" class="fpc-input" id="set-sv-min_filesize" value="' + sv.min_filesize + '" style="width:100%;"><small style="color:var(--fpc-text2);font-size:11px;">Reject cache files smaller than this</small></div>';
        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">Max Age (seconds)</label><input type="number" class="fpc-input" id="set-sv-max_age" value="' + sv.max_age + '" style="width:100%;"><small style="color:var(--fpc-text2);font-size:11px;">Fallback TTL for cache files (default: 172800 = 48h)</small></div>';
        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">Auto Delete Corrupt</label><select class="fpc-input" id="set-sv-auto_delete" style="width:100%;"><option value="true"' + (sv.auto_delete ? ' selected' : '') + '>Enabled</option><option value="false"' + (!sv.auto_delete ? ' selected' : '') + '>Disabled</option></select><small style="color:var(--fpc-text2);font-size:11px;">Automatically delete corrupt/expired cache files</small></div>';
        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">Request Logging</label><select class="fpc-input" id="set-sv-request_log" style="width:100%;"><option value="true"' + (sv.request_log ? ' selected' : '') + '>Enabled</option><option value="false"' + (!sv.request_log ? ' selected' : '') + '>Disabled</option></select><small style="color:var(--fpc-text2);font-size:11px;">Log every request for Inspector/SEO tabs</small></div>';
        html += '</div>';
        document.getElementById('settings-serve').innerHTML = html;

        // Healthcheck Settings
        var hc = d.healthcheck;
        html = '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">';
        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">Max URLs to Test</label><input type="number" class="fpc-input" id="set-hc-max_urls" value="' + hc.max_urls + '" style="width:100%;"><small style="color:var(--fpc-text2);font-size:11px;">Maximum URLs per health check run</small></div>';
        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">Timeout (sec)</label><input type="number" class="fpc-input" id="set-hc-timeout" value="' + hc.timeout + '" style="width:100%;"><small style="color:var(--fpc-text2);font-size:11px;">Timeout per URL in seconds</small></div>';
        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">Max History</label><input type="number" class="fpc-input" id="set-hc-max_history" value="' + hc.max_history + '" style="width:100%;"><small style="color:var(--fpc-text2);font-size:11px;">Maximum stored health check runs</small></div>';
        html += '</div>';
        document.getElementById('settings-healthcheck').innerHTML = html;
    });
}

function fpcSaveSettings() {
    var cfg = {db: [], preloader: {}, serve: {}, healthcheck: {}};
    // DB settings
    document.querySelectorAll('[data-db-key]').forEach(function(el) {
        cfg.db.push({key: el.getAttribute('data-db-key'), value: el.value});
    });
    // Preloader settings
    var plKeys = ['request_delay_ms','load_threshold','load_pause_sec','batch_size','batch_pause_sec','slow_threshold_ms','max_runtime_sec','min_html_size','max_error_rate','adaptive_enabled','require_doctype','require_body','verify_after_write'];
    plKeys.forEach(function(k) {
        var el = document.getElementById('set-pl-' + k);
        if (!el) return;
        if (el.tagName === 'SELECT') cfg.preloader[k] = el.value === 'true';
        else cfg.preloader[k] = parseFloat(el.value) || 0;
    });
    // Serve settings
    cfg.serve = {
        min_filesize: parseInt(document.getElementById('set-sv-min_filesize').value) || 500,
        max_age: parseInt(document.getElementById('set-sv-max_age').value) || 172800,
        auto_delete: document.getElementById('set-sv-auto_delete').value === 'true',
        request_log: document.getElementById('set-sv-request_log').value === 'true',
    };
    // Healthcheck settings
    cfg.healthcheck = {
        max_urls: parseInt(document.getElementById('set-hc-max_urls').value) || 200,
        timeout: parseInt(document.getElementById('set-hc-timeout').value) || 15,
        max_history: parseInt(document.getElementById('set-hc-max_history').value) || 90,
    };
    fpcAjaxPostJson('settings_save', cfg, function(r) { fpcToast(r.msg, !r.ok); });
}

// ============================================================
// v10.2.1: AI PROMPT MANAGEMENT
// ============================================================
var _aiPromptDefault = ''; // Wird beim Laden gesetzt

function fpcAiPromptLoad() {
    fpcAjax('ajax=ai_prompt_load', function(d) {
        if (!d.ok) return;
        var ta = document.getElementById('ai-prompt-textarea');
        var status = document.getElementById('ai-prompt-status');
        var len = document.getElementById('ai-prompt-length');
        if (ta) ta.value = d.prompt;
        _aiPromptDefault = d.default_prompt;
        if (status) {
            if (d.is_custom) {
                status.innerHTML = '<span style="color:var(--fpc-warn);">&#9679;</span> Benutzerdefinierter Prompt aktiv';
            } else {
                status.innerHTML = '<span style="color:var(--fpc-ok);">&#9679;</span> Standard-Prompt aktiv';
            }
        }
        if (len) len.textContent = d.length + ' Zeichen';
        // Live-Zaehler
        if (ta) {
            ta.oninput = function() {
                if (len) len.textContent = ta.value.length + ' Zeichen (ungespeichert)';
            };
        }
    });
}

function fpcAiPromptSave() {
    var ta = document.getElementById('ai-prompt-textarea');
    if (!ta) return;
    var prompt = ta.value;
    if (!prompt.trim()) {
        if (!confirm('Leerer Prompt = Zurueck zum Standard. Fortfahren?')) return;
    }
    fpcAjaxPostJson('ai_prompt_save', {prompt: prompt}, function(r) {
        fpcToast(r.msg, !r.ok);
        if (r.ok) fpcAiPromptLoad(); // Neu laden um Status zu aktualisieren
    });
}

function fpcAiPromptReset() {
    if (!confirm('KI-Prompt auf Standard zuruecksetzen? Alle Aenderungen gehen verloren.')) return;
    fpcAjax('ajax=ai_prompt_reset', function(r) {
        fpcToast(r.msg, !r.ok);
        if (r.ok) fpcAiPromptLoad();
    });
}

// ============================================================
// TAB 14: GOOGLE SEARCH CONSOLE v2.0 (MAXIMUM API)
// ============================================================
var gscCurrentDays = 28;
var gscTopPages = []; // store for inspection

function fpcGscSetRange(days) {
    gscCurrentDays = days;
    document.querySelectorAll('.gsc-range').forEach(function(b) { b.classList.remove('active'); });
    document.querySelector('.gsc-range[data-days="' + days + '"]').classList.add('active');
    fpcLoadGSC(days);
}

function fpcLoadGSC(days) {
    days = days || gscCurrentDays;
    var loading = document.getElementById('gsc-loading');
    if (loading) loading.style.display = 'inline';

    fpcAjax('ajax=gsc_data&days=' + days, function(d) {
        if (loading) loading.style.display = 'none';

        if (!d.configured) {
            document.getElementById('gsc-setup').style.display = 'block';
            document.getElementById('gsc-content').style.display = 'none';
            return;
        }
        if (d.error) {
            document.getElementById('gsc-error').style.display = 'block';
            document.getElementById('gsc-error').innerHTML = '<div style="background:var(--fpc-card);border:1px solid var(--fpc-red);border-radius:10px;padding:20px;margin:20px 0;"><strong style="color:var(--fpc-red);">Error:</strong> ' + d.msg + '</div>';
            return;
        }
        document.getElementById('gsc-content').style.display = 'block';
        document.getElementById('gsc-error').style.display = 'none';

        // Timestamp
        if (d.timestamp) {
            document.getElementById('gsc-timestamp').textContent = 'Stand: ' + d.timestamp + ' | ' + (d.date_range ? d.date_range.start + ' bis ' + d.date_range.end : '');
        }

        // ---- KPIs with Trend ----
        var comp = d.comparison || {};
        var cur = comp.current || {};
        var chg = comp.changes || {};
        var trendArrow = function(val, inverse) {
            if (!val) return '';
            var good = inverse ? (val < 0) : (val > 0);
            var color = good ? 'var(--fpc-green)' : 'var(--fpc-red)';
            var arrow = val > 0 ? '&#9650;' : '&#9660;';
            return ' <span style="font-size:12px;color:' + color + ';">' + arrow + ' ' + Math.abs(val) + '%</span>';
        };
        var posArrow = function(val) {
            if (!val) return '';
            var good = val < 0; // lower position = better
            var color = good ? 'var(--fpc-green)' : 'var(--fpc-red)';
            var arrow = val < 0 ? '&#9650;' : '&#9660;';
            return ' <span style="font-size:12px;color:' + color + ';">' + arrow + ' ' + Math.abs(val).toFixed(1) + '</span>';
        };

        document.getElementById('gsc-kpis').innerHTML =
            fpcKpiBox('Total Clicks', fpcNum(Math.round(cur.clicks || 0)) + trendArrow(chg.clicks), 'teal') +
            fpcKpiBox('Total Impressions', fpcNum(Math.round(cur.impressions || 0)) + trendArrow(chg.impressions), 'blue') +
            fpcKpiBox('Avg CTR', ((cur.ctr || 0) * 100).toFixed(1) + '%' + trendArrow(chg.ctr), (cur.ctr||0) > 0.03 ? 'green' : 'orange') +
            fpcKpiBox('Avg Position', (cur.position || 0).toFixed(1) + posArrow(chg.position), (cur.position||99) < 20 ? 'green' : 'orange');

        // ---- Chart 1: Daily Clicks & Impressions ----
        var perf = d.performance;
        document.getElementById('gsc-chart1-title').textContent = 'Daily Clicks & Impressions (' + days + ' Tage)';
        if (perf && perf.rows) {
            var labels = [], clicks = [], impressions = [];
            perf.rows.forEach(function(r) {
                labels.push(r.keys ? r.keys[0] : '');
                clicks.push(r.clicks || 0);
                impressions.push(r.impressions || 0);
            });
            fpcChart('chart-gsc-daily', 'line', labels, [
                {label: 'Clicks', data: clicks, borderColor: '#00d4aa', backgroundColor: 'rgba(0,212,170,0.1)', fill: true, tension: 0.3, pointRadius: days > 90 ? 0 : 3},
                {label: 'Impressions', data: impressions, borderColor: '#00a8ff', backgroundColor: 'rgba(0,168,255,0.1)', fill: true, yAxisID: 'y1', tension: 0.3, pointRadius: days > 90 ? 0 : 3}
            ], {scales: {y: {position: 'left', title: {display: true, text: 'Clicks'}}, y1: {position: 'right', grid: {drawOnChartArea: false}, title: {display: true, text: 'Impressions'}}}});
        }

        // ---- Chart 2: Position & CTR ----
        document.getElementById('gsc-chart2-title').textContent = 'Average Position & CTR (' + days + ' Tage)';
        if (perf && perf.rows) {
            var labels2 = [], positions = [], ctrs = [];
            perf.rows.forEach(function(r) {
                labels2.push(r.keys ? r.keys[0] : '');
                positions.push(r.position || 0);
                ctrs.push((r.ctr || 0) * 100);
            });
            fpcChart('chart-gsc-position', 'line', labels2, [
                {label: 'Avg Position', data: positions, borderColor: '#ff6b35', backgroundColor: 'rgba(255,107,53,0.1)', fill: false, tension: 0.3, pointRadius: days > 90 ? 0 : 3},
                {label: 'CTR %', data: ctrs, borderColor: '#a855f7', backgroundColor: 'rgba(168,85,247,0.1)', fill: true, yAxisID: 'y1', tension: 0.3, pointRadius: days > 90 ? 0 : 3}
            ], {scales: {y: {position: 'left', reverse: true, title: {display: true, text: 'Position (lower=better)'}}, y1: {position: 'right', grid: {drawOnChartArea: false}, title: {display: true, text: 'CTR %'}}}});
        }

        // ---- Chart 3: Devices (Donut) ----
        var devices = d.devices;
        if (devices && devices.rows) {
            var devLabels = [], devClicks = [], devColors = ['#00d4aa', '#00a8ff', '#ff6b35'];
            devices.rows.forEach(function(r) {
                devLabels.push(r.keys ? r.keys[0] : 'Unknown');
                devClicks.push(r.clicks || 0);
            });
            fpcChart('chart-gsc-devices', 'doughnut', devLabels, [
                {data: devClicks, backgroundColor: devColors.slice(0, devLabels.length), borderWidth: 0}
            ], {plugins: {legend: {position: 'bottom', labels: {color: '#8899aa'}}}});
        }

        // ---- Chart 4: Search Types (Bar) ----
        var types = d.search_types;
        if (types && types.length) {
            var typeLabels = [], typeClicks = [], typeColors = ['#00d4aa', '#00a8ff', '#ff6b35', '#a855f7', '#f59e0b'];
            types.forEach(function(t) {
                if (t.clicks > 0 || t.impressions > 0) {
                    typeLabels.push(t.type.charAt(0).toUpperCase() + t.type.slice(1));
                    typeClicks.push(t.clicks);
                }
            });
            fpcChart('chart-gsc-types', 'bar', typeLabels, [
                {label: 'Clicks', data: typeClicks, backgroundColor: typeColors.slice(0, typeLabels.length), borderWidth: 0}
            ], {plugins: {legend: {display: false}}, scales: {y: {beginAtZero: true}}});
        }

        // ---- Countries Table ----
        var countries = d.countries;
        if (countries && countries.rows && countries.rows.length) {
            var html = '<table class="fpc-table"><thead><tr><th>Land</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th><th>Anteil</th></tr></thead><tbody>';
            var totalC = 0; countries.rows.forEach(function(r) { totalC += r.clicks || 0; });
            countries.rows.slice(0, 30).forEach(function(r) {
                var pct = totalC > 0 ? ((r.clicks || 0) / totalC * 100).toFixed(1) : '0';
                html += '<tr><td>' + (r.keys ? r.keys[0] : '') + '</td><td>' + fpcNum(r.clicks||0) + '</td><td>' + fpcNum(r.impressions||0) + '</td><td>' + ((r.ctr||0)*100).toFixed(1) + '%</td><td>' + (r.position||0).toFixed(1) + '</td><td>' + pct + '%</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('gsc-countries').innerHTML = html;
        } else {
            document.getElementById('gsc-countries').innerHTML = '<p style="color:var(--fpc-text2);">Keine Daten</p>';
        }

        // ---- Search Appearance Table ----
        var appear = d.search_appearance;
        if (appear && appear.rows && appear.rows.length) {
            var html = '<table class="fpc-table"><thead><tr><th>Appearance</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
            appear.rows.forEach(function(r) {
                html += '<tr><td>' + (r.keys ? r.keys[0] : '') + '</td><td>' + fpcNum(r.clicks||0) + '</td><td>' + fpcNum(r.impressions||0) + '</td><td>' + ((r.ctr||0)*100).toFixed(1) + '%</td><td>' + (r.position||0).toFixed(1) + '</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('gsc-appearance').innerHTML = html;
        } else {
            document.getElementById('gsc-appearance').innerHTML = '<p style="color:var(--fpc-text2);">Keine Search Appearance Daten</p>';
        }

        // ---- Top Queries Table ----
        var queries = d.top_queries;
        if (queries && queries.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Keyword</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
            queries.rows.forEach(function(r, i) {
                var posColor = (r.position||99) <= 3 ? 'var(--fpc-green)' : ((r.position||99) <= 10 ? 'var(--fpc-teal)' : ((r.position||99) <= 20 ? 'var(--fpc-orange)' : 'var(--fpc-red)'));
                html += '<tr><td>' + (i+1) + '</td><td>' + (r.keys ? r.keys[0] : '') + '</td><td>' + fpcNum(r.clicks||0) + '</td><td>' + fpcNum(r.impressions||0) + '</td><td>' + ((r.ctr||0)*100).toFixed(1) + '%</td><td style="color:' + posColor + ';font-weight:600;">' + (r.position||0).toFixed(1) + '</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('gsc-queries').innerHTML = html;
        }

        // ---- Top Pages Table ----
        var pages = d.top_pages;
        gscTopPages = []; // reset
        if (pages && pages.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Seite</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
            pages.rows.forEach(function(r, i) {
                var url = r.keys ? r.keys[0] : '';
                if (i < 10) gscTopPages.push(url);
                var short = url.replace('https://mr-hanf.de', '');
                if (short.length > 70) short = short.substring(0, 67) + '...';
                var posColor = (r.position||99) <= 3 ? 'var(--fpc-green)' : ((r.position||99) <= 10 ? 'var(--fpc-teal)' : ((r.position||99) <= 20 ? 'var(--fpc-orange)' : 'var(--fpc-red)'));
                html += '<tr><td>' + (i+1) + '</td><td title="' + url + '"><a href="' + url + '" target="_blank" style="color:var(--fpc-teal);text-decoration:none;">' + short + '</a></td><td>' + fpcNum(r.clicks||0) + '</td><td>' + fpcNum(r.impressions||0) + '</td><td>' + ((r.ctr||0)*100).toFixed(1) + '%</td><td style="color:' + posColor + ';font-weight:600;">' + (r.position||0).toFixed(1) + '</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('gsc-pages').innerHTML = html;
        }

        // ---- Query-Page Combinations ----
        var qp = d.query_pages;
        if (qp && qp.rows && qp.rows.length) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Keyword</th><th>Seite</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
            qp.rows.forEach(function(r, i) {
                var query = r.keys ? r.keys[0] : '';
                var page = r.keys && r.keys[1] ? r.keys[1].replace('https://mr-hanf.de', '') : '';
                if (page.length > 50) page = page.substring(0, 47) + '...';
                html += '<tr><td>' + (i+1) + '</td><td>' + query + '</td><td title="' + (r.keys?r.keys[1]:'') + '">' + page + '</td><td>' + fpcNum(r.clicks||0) + '</td><td>' + fpcNum(r.impressions||0) + '</td><td>' + ((r.ctr||0)*100).toFixed(1) + '%</td><td>' + (r.position||0).toFixed(1) + '</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('gsc-query-pages').innerHTML = html;
        } else {
            document.getElementById('gsc-query-pages').innerHTML = '<p style="color:var(--fpc-text2);">Keine Daten</p>';
        }

        // ---- Sitemaps ----
        var sitemaps = d.sitemaps;
        if (sitemaps && sitemaps.sitemap) {
            var html = '<table class="fpc-table"><thead><tr><th>Sitemap</th><th>Typ</th><th>Eingereicht</th><th>Zuletzt geladen</th><th>Status</th><th>Errors</th><th>Warnings</th></tr></thead><tbody>';
            sitemaps.sitemap.forEach(function(s) {
                var statusColor = s.isPending ? 'var(--fpc-orange)' : 'var(--fpc-green)';
                var errCount = (s.errors || 0);
                var warnCount = (s.warnings || 0);
                html += '<tr><td style="word-break:break-all;">' + (s.path||'') + '</td><td>' + (s.type||'') + '</td><td>' + (s.lastSubmitted||'').substring(0,10) + '</td><td>' + (s.lastDownloaded||'').substring(0,10) + '</td><td style="color:' + statusColor + ';">' + (s.isPending ? 'Pending' : 'OK') + '</td><td style="color:' + (errCount > 0 ? 'var(--fpc-red)' : 'var(--fpc-green)') + ';">' + errCount + '</td><td style="color:' + (warnCount > 0 ? 'var(--fpc-orange)' : 'var(--fpc-green)') + ';">' + warnCount + '</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('gsc-sitemaps').innerHTML = html;
        }
    });
}

// URL Inspection: single URL
function fpcGscInspectUrl() {
    var url = document.getElementById('gsc-inspect-url').value.trim();
    if (!url) { fpcToast('Bitte URL eingeben', true); return; }
    fpcGscRunInspection([url]);
}

// URL Inspection: top 10 pages
function fpcGscInspectSample() {
    if (!gscTopPages.length) { fpcToast('Erst Daten laden', true); return; }
    fpcGscRunInspection(gscTopPages.slice(0, 10));
}

function fpcGscRunInspection(urls) {
    document.getElementById('gsc-inspection').innerHTML = '<p style="color:var(--fpc-teal);">Inspecting ' + urls.length + ' URLs... (kann 5-30 Sek. dauern)</p>';
    fpcAjaxPostJson('gsc_inspect', urls, function(d) {
        if (d.error) {
            document.getElementById('gsc-inspection').innerHTML = '<p style="color:var(--fpc-red);">Error: ' + (d.msg||'Unknown') + '</p>';
            return;
        }
        var results = d.urls || [];
        if (!results.length) {
            document.getElementById('gsc-inspection').innerHTML = '<p style="color:var(--fpc-text2);">Keine Ergebnisse</p>';
            return;
        }
        var html = '<table class="fpc-table"><thead><tr><th>URL</th><th>Verdict</th><th>Coverage</th><th>Crawled As</th><th>Last Crawl</th><th>Robots.txt</th><th>Mobile</th></tr></thead><tbody>';
        results.forEach(function(r) {
            var verdictColor = r.verdict === 'PASS' ? 'var(--fpc-green)' : (r.verdict === 'PARTIAL' ? 'var(--fpc-orange)' : 'var(--fpc-red)');
            var mobileColor = r.mobileVerdict === 'PASS' ? 'var(--fpc-green)' : (r.mobileVerdict === 'VERDICT_UNSPECIFIED' ? 'var(--fpc-text2)' : 'var(--fpc-red)');
            var shortUrl = (r.url||'').replace('https://mr-hanf.de', '');
            if (shortUrl.length > 50) shortUrl = shortUrl.substring(0, 47) + '...';
            html += '<tr>';
            html += '<td title="' + (r.url||'') + '">' + shortUrl + '</td>';
            html += '<td style="color:' + verdictColor + ';font-weight:600;">' + (r.verdict||'-') + '</td>';
            html += '<td>' + (r.coverageState||'-') + '</td>';
            html += '<td>' + (r.crawledAs||'-') + '</td>';
            html += '<td>' + (r.lastCrawl ? r.lastCrawl.substring(0,10) : '-') + '</td>';
            html += '<td>' + (r.robotsTxt||'-') + '</td>';
            html += '<td style="color:' + mobileColor + ';">' + (r.mobileVerdict||'-') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('gsc-inspection').innerHTML = html;
    });
}

// ============================================================
// TAB 15: GOOGLE ANALYTICS 4 v2.0 (MAXIMUM API)
// ============================================================
var ga4CurrentDays = 30;

function fpcGa4SetRange(days) {
    ga4CurrentDays = days;
    document.querySelectorAll('.ga4-range').forEach(function(b) { b.classList.remove('active'); });
    document.querySelector('.ga4-range[data-days="' + days + '"]').classList.add('active');
    fpcLoadGA4(days);
}

function ga4Val(row, idx) { return row.metricValues ? parseFloat(row.metricValues[idx].value) : 0; }
function ga4Dim(row, idx) { return row.dimensionValues ? row.dimensionValues[idx || 0].value : ''; }
function ga4Date(d) { return d.length === 8 ? d.substring(0,4)+'-'+d.substring(4,6)+'-'+d.substring(6,8) : d; }
function ga4Pct(v) { return (v * 100).toFixed(1) + '%'; }
function ga4Dur(s) { var m = Math.floor(s/60); return m > 0 ? m + 'm ' + Math.round(s%60) + 's' : Math.round(s) + 's'; }
function ga4Eur(v) { return Number(v).toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' EUR'; }
var ga4TrendArrow = function(cur, prev) {
    if (!prev || prev === 0) return '';
    var pct = ((cur - prev) / Math.abs(prev) * 100).toFixed(1);
    var good = pct > 0;
    var color = good ? 'var(--fpc-green)' : 'var(--fpc-red)';
    var arrow = pct > 0 ? '&#9650;' : '&#9660;';
    return ' <span style="font-size:11px;color:' + color + ';">' + arrow + ' ' + Math.abs(pct) + '%</span>';
};
var ga4TrendArrowInverse = function(cur, prev) {
    if (!prev || prev === 0) return '';
    var pct = ((cur - prev) / Math.abs(prev) * 100).toFixed(1);
    var good = pct < 0;
    var color = good ? 'var(--fpc-green)' : 'var(--fpc-red)';
    var arrow = pct > 0 ? '&#9650;' : '&#9660;';
    return ' <span style="font-size:11px;color:' + color + ';">' + arrow + ' ' + Math.abs(pct) + '%</span>';
};

function fpcLoadGA4(days) {
    days = days || ga4CurrentDays;
    var loading = document.getElementById('ga4-loading');
    if (loading) loading.style.display = 'inline';

    fpcAjax('ajax=ga4_data&days=' + days, function(d) {
        if (loading) loading.style.display = 'none';

        if (!d.configured) {
            document.getElementById('ga4-setup').style.display = 'block';
            document.getElementById('ga4-content').style.display = 'none';
            return;
        }
        if (d.error) {
            document.getElementById('ga4-error').style.display = 'block';
            document.getElementById('ga4-error').innerHTML = '<div style="background:var(--fpc-card);border:1px solid var(--fpc-red);border-radius:10px;padding:20px;margin:20px 0;"><strong style="color:var(--fpc-red);">Error:</strong> ' + d.msg + '</div>';
            return;
        }
        document.getElementById('ga4-content').style.display = 'block';
        document.getElementById('ga4-error').style.display = 'none';

        // Timestamp
        if (d.timestamp) {
            document.getElementById('ga4-timestamp').textContent = 'Stand: ' + d.timestamp + (d.date_range ? ' | ' + d.date_range.start + ' bis ' + d.date_range.end : '');
        }

        // ---- REALTIME BANNER ----
        var rt = d.realtime;
        var rtTotal = 0;
        if (rt && rt.rows) { rt.rows.forEach(function(r) { rtTotal += ga4Val(r, 0); }); }
        var rtDev = d.realtime_device;
        var rtDevHtml = '';
        if (rtDev && rtDev.rows) {
            rtDev.rows.forEach(function(r) {
                rtDevHtml += '<span style="color:var(--fpc-text2);font-size:12px;">' + ga4Dim(r) + ': <strong style="color:var(--fpc-teal);">' + Math.round(ga4Val(r,0)) + '</strong></span> ';
            });
        }
        document.getElementById('ga4-realtime').innerHTML =
            '<div style="font-size:14px;color:var(--fpc-text2);">LIVE</div>' +
            '<div style="font-size:28px;font-weight:700;color:var(--fpc-teal);animation:pulse 2s infinite;">' + rtTotal + '</div>' +
            '<div style="font-size:13px;color:var(--fpc-text2);">aktive Nutzer jetzt</div>' +
            '<div style="margin-left:auto;">' + rtDevHtml + '</div>';

        // ---- COMPARISON KPIs ----
        var comp = d.comparison;
        var curTotals = null, prevTotals = null;
        if (comp && comp.totals && comp.totals.length >= 2) {
            curTotals = comp.totals[0].metricValues || [];
            prevTotals = comp.totals[1].metricValues || [];
        }
        var cV = function(idx) { return curTotals && curTotals[idx] ? parseFloat(curTotals[idx].value) : 0; };
        var pV = function(idx) { return prevTotals && prevTotals[idx] ? parseFloat(prevTotals[idx].value) : 0; };

        document.getElementById('ga4-kpis').innerHTML =
            fpcKpiBox('Sessions', fpcNum(Math.round(cV(0))) + ga4TrendArrow(cV(0), pV(0)), 'teal') +
            fpcKpiBox('Users', fpcNum(Math.round(cV(1))) + ga4TrendArrow(cV(1), pV(1)), 'blue') +
            fpcKpiBox('New Users', fpcNum(Math.round(cV(2))) + ga4TrendArrow(cV(2), pV(2)), 'green') +
            fpcKpiBox('Pageviews', fpcNum(Math.round(cV(3))) + ga4TrendArrow(cV(3), pV(3)), 'orange') +
            fpcKpiBox('Engagement', ga4Pct(cV(4)) + ga4TrendArrow(cV(4), pV(4)), cV(4) > 0.5 ? 'green' : 'orange') +
            fpcKpiBox('Bounce Rate', ga4Pct(cV(5)) + ga4TrendArrowInverse(cV(5), pV(5)), cV(5) < 0.5 ? 'green' : 'red') +
            fpcKpiBox('Avg Duration', ga4Dur(cV(6)), 'teal') +
            fpcKpiBox('Sessions/User', cV(7).toFixed(2), 'blue');

        // ---- CHART 1: Daily Sessions & Users ----
        var daily = d.daily_traffic;
        document.getElementById('ga4-chart1-title').textContent = 'Sessions & Users (' + days + ' Tage)';
        if (daily && daily.rows) {
            var labels=[], sess=[], users=[], newU=[];
            daily.rows.forEach(function(r) {
                labels.push(ga4Date(ga4Dim(r)));
                sess.push(ga4Val(r,0)); users.push(ga4Val(r,1)); newU.push(ga4Val(r,2));
            });
            fpcChart('chart-ga4-daily', 'line', labels, [
                {label:'Sessions', data:sess, borderColor:'#00d4aa', backgroundColor:'rgba(0,212,170,0.1)', fill:true, tension:0.3, pointRadius: days>90?0:2},
                {label:'Users', data:users, borderColor:'#00a8ff', backgroundColor:'rgba(0,168,255,0.1)', fill:true, tension:0.3, pointRadius: days>90?0:2},
                {label:'New Users', data:newU, borderColor:'#a855f7', backgroundColor:'rgba(168,85,247,0.05)', fill:false, tension:0.3, pointRadius: days>90?0:2, borderDash:[5,5]}
            ], {scales:{y:{beginAtZero:true}}});
        }

        // ---- CHART 2: Pageviews & Bounce Rate ----
        document.getElementById('ga4-chart2-title').textContent = 'Pageviews & Bounce Rate (' + days + ' Tage)';
        if (daily && daily.rows) {
            var labels2=[], pvs=[], bounces=[];
            daily.rows.forEach(function(r) {
                labels2.push(ga4Date(ga4Dim(r)));
                pvs.push(ga4Val(r,3));
                bounces.push(ga4Val(r,6)*100);
            });
            fpcChart('chart-ga4-pv-bounce', 'line', labels2, [
                {label:'Pageviews', data:pvs, borderColor:'#00d4aa', backgroundColor:'rgba(0,212,170,0.1)', fill:true, tension:0.3, pointRadius: days>90?0:2},
                {label:'Bounce Rate %', data:bounces, borderColor:'#ff6b35', backgroundColor:'rgba(255,107,53,0.1)', fill:false, yAxisID:'y1', tension:0.3, pointRadius: days>90?0:2}
            ], {scales:{y:{position:'left', title:{display:true,text:'Pageviews'}}, y1:{position:'right', grid:{drawOnChartArea:false}, title:{display:true,text:'Bounce Rate %'}}}});
        }

        // ---- CHART: Devices (Donut) ----
        var devices = d.devices;
        if (devices && devices.rows) {
            var devL=[], devD=[], devC=['#00d4aa','#00a8ff','#ff6b35','#a855f7'];
            devices.rows.forEach(function(r) { devL.push(ga4Dim(r)); devD.push(ga4Val(r,0)); });
            fpcChart('chart-ga4-devices', 'doughnut', devL, [{data:devD, backgroundColor:devC, borderWidth:0}],
                {plugins:{legend:{position:'bottom',labels:{color:'#8899aa'}}}});
        }

        // ---- CHART: Channel Groups (Bar) ----
        var channels = d.channel_groups;
        if (channels && channels.rows) {
            var chL=[], chD=[], chC=['#00d4aa','#00a8ff','#ff6b35','#a855f7','#f59e0b','#ef4444','#10b981','#6366f1','#ec4899'];
            channels.rows.forEach(function(r) { chL.push(ga4Dim(r)); chD.push(ga4Val(r,0)); });
            fpcChart('chart-ga4-channels', 'bar', chL, [{label:'Sessions', data:chD, backgroundColor:chC.slice(0,chL.length), borderWidth:0}],
                {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}, indexAxis:'y'});
        }

        // ---- CHART: New vs Returning (Donut) ----
        var nvr = d.new_vs_returning;
        if (nvr && nvr.rows) {
            var nvrL=[], nvrD=[];
            nvr.rows.forEach(function(r) { nvrL.push(ga4Dim(r)==='new'?'Neue Nutzer':'Wiederkehrend'); nvrD.push(ga4Val(r,0)); });
            fpcChart('chart-ga4-newret', 'doughnut', nvrL, [{data:nvrD, backgroundColor:['#00d4aa','#00a8ff'], borderWidth:0}],
                {plugins:{legend:{position:'bottom',labels:{color:'#8899aa'}}}});
        }

        // ---- CHART: Hourly (Bar) ----
        var hourly = d.hourly;
        if (hourly && hourly.rows) {
            var hL=[], hS=[], hPV=[];
            for(var h=0;h<24;h++){hL.push(h+':00');hS.push(0);hPV.push(0);}
            hourly.rows.forEach(function(r) {
                var hr=parseInt(ga4Dim(r));
                hS[hr]=ga4Val(r,0); hPV[hr]=ga4Val(r,1);
            });
            fpcChart('chart-ga4-hourly', 'bar', hL, [
                {label:'Sessions', data:hS, backgroundColor:'rgba(0,212,170,0.7)'},
                {label:'Pageviews', data:hPV, backgroundColor:'rgba(0,168,255,0.7)'}
            ], {scales:{y:{beginAtZero:true}}});
        }

        // ---- CHART: Day of Week ----
        var dow = d.day_of_week;
        if (dow && dow.rows) {
            var dowNames = ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag'];
            var dowL=[], dowS=[], dowR=[];
            for(var i=0;i<7;i++){dowL.push(dowNames[i]);dowS.push(0);dowR.push(0);}
            dow.rows.forEach(function(r) {
                var di=parseInt(ga4Dim(r));
                dowS[di]=ga4Val(r,0); dowR[di]=ga4Val(r,4);
            });
            fpcChart('chart-ga4-dow', 'bar', dowL, [
                {label:'Sessions', data:dowS, backgroundColor:'rgba(0,212,170,0.7)'},
                {label:'Revenue', data:dowR, backgroundColor:'rgba(245,158,11,0.7)', yAxisID:'y1'}
            ], {scales:{y:{position:'left',beginAtZero:true}, y1:{position:'right',grid:{drawOnChartArea:false},beginAtZero:true}}});
        }

        // ---- CHART: Browsers ----
        var browsers = d.browsers;
        if (browsers && browsers.rows) {
            var bL=[], bD=[], bC=['#00d4aa','#00a8ff','#ff6b35','#a855f7','#f59e0b','#ef4444','#10b981','#6366f1'];
            browsers.rows.slice(0,8).forEach(function(r) { bL.push(ga4Dim(r)); bD.push(ga4Val(r,0)); });
            fpcChart('chart-ga4-browsers', 'doughnut', bL, [{data:bD, backgroundColor:bC, borderWidth:0}],
                {plugins:{legend:{position:'bottom',labels:{color:'#8899aa',font:{size:10}}}}});
        }

        // ---- E-COMMERCE KPIs ----
        var ecom = d.ecommerce;
        var funnel = d.shopping_funnel;
        if (ecom && ecom.rows) {
            var totalRev=0, totalTx=0, totalAOV=0, totalCarts=0, totalCheckouts=0, totalItemViews=0;
            ecom.rows.forEach(function(r) {
                totalTx += ga4Val(r,0);
                totalRev += ga4Val(r,1);
                totalCarts += ga4Val(r,4);
                totalCheckouts += ga4Val(r,5);
                totalItemViews += ga4Val(r,6);
            });
            totalAOV = totalTx > 0 ? totalRev / totalTx : 0;
            var c2v = funnel && funnel.totals && funnel.totals[0] ? parseFloat(funnel.totals[0].metricValues[5].value) : 0;
            var p2v = funnel && funnel.totals && funnel.totals[0] ? parseFloat(funnel.totals[0].metricValues[6].value) : 0;

            document.getElementById('ga4-ecom-kpis').innerHTML =
                fpcKpiBox('Umsatz', ga4Eur(totalRev) + ga4TrendArrow(cV(9), pV(9)), 'green') +
                fpcKpiBox('Transaktionen', fpcNum(Math.round(totalTx)) + ga4TrendArrow(cV(8), pV(8)), 'teal') +
                fpcKpiBox('AOV', ga4Eur(totalAOV), 'blue') +
                fpcKpiBox('Cart-to-View', ga4Pct(c2v), c2v > 0.05 ? 'green' : 'orange') +
                fpcKpiBox('Purchase-to-View', ga4Pct(p2v), p2v > 0.02 ? 'green' : 'orange') +
                fpcKpiBox('Add to Carts', fpcNum(Math.round(totalCarts)), 'orange');

            // Revenue Chart
            var revL=[], revD=[], txD=[];
            ecom.rows.forEach(function(r) {
                revL.push(ga4Date(ga4Dim(r)));
                revD.push(ga4Val(r,1));
                txD.push(ga4Val(r,0));
            });
            fpcChart('chart-ga4-revenue', 'line', revL, [
                {label:'Umsatz (EUR)', data:revD, borderColor:'#10b981', backgroundColor:'rgba(16,185,129,0.1)', fill:true, tension:0.3, pointRadius: days>90?0:2},
                {label:'Transaktionen', data:txD, borderColor:'#f59e0b', backgroundColor:'rgba(245,158,11,0.1)', fill:false, yAxisID:'y1', tension:0.3, pointRadius: days>90?0:2}
            ], {scales:{y:{position:'left',title:{display:true,text:'EUR'}}, y1:{position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'Transaktionen'}}}});
        }

        // ---- SHOPPING FUNNEL ----
        if (funnel && funnel.totals && funnel.totals[0]) {
            var ft = funnel.totals[0].metricValues;
            var funnelData = [
                parseFloat(ft[0].value), // itemViews
                parseFloat(ft[1].value), // addToCarts
                parseFloat(ft[2].value), // checkouts
                parseFloat(ft[3].value), // purchases
            ];
            var funnelLabels = ['Produkt angesehen', 'In Warenkorb', 'Checkout', 'Kauf'];
            var funnelColors = ['rgba(0,168,255,0.7)', 'rgba(245,158,11,0.7)', 'rgba(168,85,247,0.7)', 'rgba(16,185,129,0.7)'];
            fpcChart('chart-ga4-funnel', 'bar', funnelLabels, [{label:'Anzahl', data:funnelData, backgroundColor:funnelColors, borderWidth:0}],
                {plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}}});
        }

        // ---- TOP PRODUCTS TABLE ----
        var products = d.top_products;
        if (products && products.rows && products.rows.length) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Produkt</th><th>Views</th><th>Add to Cart</th><th>Purchases</th><th>Umsatz</th><th>Cart/View</th><th>Purchase/View</th></tr></thead><tbody>';
            products.rows.forEach(function(r, i) {
                html += '<tr><td>'+(i+1)+'</td><td>'+ga4Dim(r)+'</td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td><td>'+fpcNum(ga4Val(r,2))+'</td><td>'+ga4Eur(ga4Val(r,3))+'</td><td>'+ga4Pct(ga4Val(r,5))+'</td><td>'+ga4Pct(ga4Val(r,6))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-products').innerHTML = html;
        } else {
            document.getElementById('ga4-products').innerHTML = '<p style="color:var(--fpc-text2);">Keine E-Commerce Daten</p>';
        }

        // ---- TRAFFIC SOURCES TABLE ----
        var sources = d.traffic_sources;
        if (sources && sources.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Source / Medium</th><th>Sessions</th><th>Users</th><th>New Users</th><th>Bounce</th><th>Engagement</th><th>Avg Duration</th><th>Conversions</th><th>Purchases</th><th>Revenue</th></tr></thead><tbody>';
            sources.rows.forEach(function(r, i) {
                html += '<tr><td>'+(i+1)+'</td><td>'+ga4Dim(r)+'</td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td><td>'+fpcNum(ga4Val(r,2))+'</td><td>'+ga4Pct(ga4Val(r,3))+'</td><td>'+ga4Pct(ga4Val(r,4))+'</td><td>'+ga4Dur(ga4Val(r,5))+'</td><td>'+fpcNum(ga4Val(r,6))+'</td><td>'+fpcNum(ga4Val(r,7))+'</td><td>'+ga4Eur(ga4Val(r,8))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-sources').innerHTML = html;
        }

        // ---- LANDING PAGES TABLE ----
        var landing = d.landing_pages;
        if (landing && landing.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Landing Page</th><th>Sessions</th><th>Users</th><th>Bounce</th><th>Engagement</th><th>Avg Duration</th><th>Conversions</th><th>Purchases</th><th>Revenue</th></tr></thead><tbody>';
            landing.rows.forEach(function(r, i) {
                var p = ga4Dim(r); if(p.length>60) p=p.substring(0,57)+'...';
                html += '<tr><td>'+(i+1)+'</td><td title="'+ga4Dim(r)+'"><a href="https://mr-hanf.de'+ga4Dim(r)+'" target="_blank" style="color:var(--fpc-teal);text-decoration:none;">'+p+'</a></td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td><td>'+ga4Pct(ga4Val(r,2))+'</td><td>'+ga4Pct(ga4Val(r,4))+'</td><td>'+ga4Dur(ga4Val(r,3))+'</td><td>'+fpcNum(ga4Val(r,5))+'</td><td>'+fpcNum(ga4Val(r,6))+'</td><td>'+ga4Eur(ga4Val(r,7))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-landing').innerHTML = html;
        }

        // ---- TOP PAGES TABLE ----
        var pages = d.top_pages;
        if (pages && pages.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Seite</th><th>Pageviews</th><th>Sessions</th><th>Users</th><th>Bounce</th><th>Engagement</th><th>Avg Duration</th><th>Conversions</th></tr></thead><tbody>';
            pages.rows.forEach(function(r, i) {
                var p = ga4Dim(r); if(p.length>60) p=p.substring(0,57)+'...';
                html += '<tr><td>'+(i+1)+'</td><td title="'+ga4Dim(r)+'"><a href="https://mr-hanf.de'+ga4Dim(r)+'" target="_blank" style="color:var(--fpc-teal);text-decoration:none;">'+p+'</a></td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td><td>'+fpcNum(ga4Val(r,2))+'</td><td>'+ga4Pct(ga4Val(r,3))+'</td><td>'+ga4Pct(ga4Val(r,5))+'</td><td>'+ga4Dur(ga4Val(r,4))+'</td><td>'+fpcNum(ga4Val(r,6))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-pages').innerHTML = html;
        }

        // ---- COUNTRIES TABLE ----
        var countries = d.countries;
        if (countries && countries.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Land</th><th>Sessions</th><th>Users</th><th>New Users</th><th>Engagement</th><th>Bounce</th><th>Purchases</th><th>Revenue</th></tr></thead><tbody>';
            countries.rows.forEach(function(r, i) {
                html += '<tr><td>'+(i+1)+'</td><td>'+ga4Dim(r)+'</td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td><td>'+fpcNum(ga4Val(r,2))+'</td><td>'+ga4Pct(ga4Val(r,3))+'</td><td>'+ga4Pct(ga4Val(r,4))+'</td><td>'+fpcNum(ga4Val(r,5))+'</td><td>'+ga4Eur(ga4Val(r,6))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-countries').innerHTML = html;
        }

        // ---- EVENTS TABLE ----
        var events = d.events;
        if (events && events.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>#</th><th>Event</th><th>Count</th><th>Users</th><th>Per User</th></tr></thead><tbody>';
            events.rows.forEach(function(r, i) {
                html += '<tr><td>'+(i+1)+'</td><td>'+ga4Dim(r)+'</td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td><td>'+ga4Val(r,2).toFixed(2)+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-events').innerHTML = html;
        }

        // ---- KEY EVENTS TABLE ----
        var keyEvents = d.key_events;
        if (keyEvents && keyEvents.rows && keyEvents.rows.length) {
            var html = '<table class="fpc-table"><thead><tr><th>Key Event</th><th>Count</th><th>Users</th></tr></thead><tbody>';
            keyEvents.rows.forEach(function(r) {
                html += '<tr><td style="color:var(--fpc-green);font-weight:600;">'+ga4Dim(r)+'</td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-keyevents').innerHTML = html;
        } else {
            document.getElementById('ga4-keyevents').innerHTML = '<p style="color:var(--fpc-text2);">Keine Key Events konfiguriert</p>';
        }

        // ---- OS TABLE ----
        var os = d.operating_systems;
        if (os && os.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>Betriebssystem</th><th>Sessions</th><th>Users</th><th>Bounce</th></tr></thead><tbody>';
            os.rows.forEach(function(r) {
                html += '<tr><td>'+ga4Dim(r)+'</td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td><td>'+ga4Pct(ga4Val(r,2))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-os').innerHTML = '<h4 style="color:var(--fpc-text2);margin-bottom:8px;">Betriebssysteme</h4>' + html;
        }

        // ---- SCREEN RESOLUTIONS TABLE ----
        var screens = d.screen_resolutions;
        if (screens && screens.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>Aufloesung</th><th>Sessions</th><th>Users</th></tr></thead><tbody>';
            screens.rows.forEach(function(r) {
                html += '<tr><td>'+ga4Dim(r)+'</td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-screens').innerHTML = '<h4 style="color:var(--fpc-text2);margin-bottom:8px;">Bildschirmaufloesung</h4>' + html;
        }

        // ---- LANGUAGES TABLE ----
        var langs = d.languages;
        if (langs && langs.rows) {
            var html = '<table class="fpc-table"><thead><tr><th>Sprache</th><th>Sessions</th><th>Users</th></tr></thead><tbody>';
            langs.rows.forEach(function(r) {
                html += '<tr><td>'+ga4Dim(r)+'</td><td>'+fpcNum(ga4Val(r,0))+'</td><td>'+fpcNum(ga4Val(r,1))+'</td></tr>';
            });
            html += '</tbody></table>';
            document.getElementById('ga4-languages').innerHTML = html;
        }
    });
}

// ============================================================
// TAB 16: SISTRIX
// ============================================================
function fpcLoadSistrix() {
    fpcAjax('ajax=sistrix_data', function(d) {
        if (!d.configured) {
            document.getElementById('sx-setup').style.display = 'block';
            document.getElementById('sx-content').style.display = 'none';
            return;
        }
        if (d.error) {
            document.getElementById('sx-error').style.display = 'block';
            document.getElementById('sx-error').innerHTML = '<div style="background:var(--fpc-card);border:1px solid var(--fpc-red);border-radius:10px;padding:20px;margin:20px 0;"><strong style="color:var(--fpc-red);">Error:</strong> ' + (d.msg || 'Unknown error') + '</div>';
            return;
        }
        document.getElementById('sx-content').style.display = 'block';

        // ---- VISIBILITY INDEX ----
        var vi = d.visibility;
        var viVal = '\u2014', viDate = '\u2014';
        if (vi && vi.answer && vi.answer[0] && vi.answer[0].sichtbarkeitsindex) {
            var siArr = vi.answer[0].sichtbarkeitsindex;
            if (siArr[0]) {
                viVal = parseFloat(siArr[0].value).toFixed(4);
                viDate = siArr[0].date || '\u2014';
            }
        }

        // Credits
        var creditsUsed = '\u2014', creditsTotal = '\u2014';
        var cr = d.credits;
        if (cr && cr.answer && cr.answer[0] && cr.answer[0].credits) {
            creditsTotal = cr.answer[0].credits[0].value || '\u2014';
        }
        if (cr && cr.credits && cr.credits[0]) {
            creditsUsed = cr.credits[0].used || 0;
        }

        // Calculate trend from history
        var trendHtml = '\u2014';
        var viHist = d.vi_history;
        var labels = [], values = [];
        if (viHist && viHist.answer && viHist.answer[0] && viHist.answer[0].sichtbarkeitsindex) {
            var items = viHist.answer[0].sichtbarkeitsindex;
            items.forEach(function(p) {
                if (p.date) labels.push(p.date.substring(0,10));
                if (p.value !== undefined) values.push(parseFloat(p.value));
            });
            labels.reverse();
            values.reverse();
            if (values.length >= 2) {
                var prev = values[values.length - 2];
                var curr = values[values.length - 1];
                var diff = curr - prev;
                var pct = prev > 0 ? ((diff / prev) * 100).toFixed(1) : '0.0';
                var arrow = diff >= 0 ? '&#9650;' : '&#9660;';
                var color = diff >= 0 ? 'var(--fpc-green)' : 'var(--fpc-red)';
                trendHtml = '<span style="color:' + color + '">' + arrow + ' ' + (diff >= 0 ? '+' : '') + diff.toFixed(4) + ' (' + (diff >= 0 ? '+' : '') + pct + '%)</span>';
            }
        }

        document.getElementById('sx-kpis').innerHTML =
            fpcKpiBox('Visibility Index', viVal, 'teal') +
            fpcKpiBox('Weekly Trend', trendHtml, 'text2') +
            fpcKpiBox('Data Points', values.length + ' weeks', 'blue') +
            fpcKpiBox('Last Update', viDate, 'text2') +
            fpcKpiBox('API Credits Used', creditsUsed + ' / ' + creditsTotal, 'orange');

        // ---- VISIBILITY HISTORY CHART ----
        if (values.length > 0) {
            fpcMakeChart('chart-sx-visibility', {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Visibility Index',
                        data: values,
                        borderColor: '#00d4aa',
                        backgroundColor: 'rgba(0,212,170,0.15)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 2,
                        pointHoverRadius: 5,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        x: { ticks: { maxTicksAutoSkip: true, maxRotation: 45, font: { size: 10 } }, grid: { color: '#1a2736' } },
                        y: { beginAtZero: false, grid: { color: '#1a2736' }, ticks: { font: { size: 11 } } }
                    }
                }
            });
        }

        // ---- HISTORY TABLE (last 12 weeks) ----
        if (values.length > 0) {
            var html = '<div class="fpc-section-title">Recent Visibility History</div>';
            html += '<table class="fpc-table"><thead><tr><th>Date</th><th>Visibility Index</th><th>Change</th></tr></thead><tbody>';
            var showCount = Math.min(values.length, 12);
            for (var i = values.length - 1; i >= values.length - showCount; i--) {
                var change = '\u2014';
                if (i > 0) {
                    var ch = values[i] - values[i-1];
                    var chColor = ch >= 0 ? 'var(--fpc-green)' : 'var(--fpc-red)';
                    var chArrow = ch >= 0 ? '&#9650;' : '&#9660;';
                    change = '<span style="color:' + chColor + '">' + chArrow + ' ' + (ch >= 0 ? '+' : '') + ch.toFixed(4) + '</span>';
                }
                html += '<tr><td>' + labels[i] + '</td><td style="font-weight:700;color:var(--fpc-teal);">' + values[i].toFixed(4) + '</td><td>' + change + '</td></tr>';
            }
            html += '</tbody></table>';
            document.getElementById('sx-history-table').innerHTML = html;
        }
    });
}

// ============================================================
// API CREDENTIALS (Settings Tab)
// ============================================================
function fpcLoadApiCredentials() {
    fpcAjax('ajax=load_api_credentials', function(d) {
        var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">Google Service Account JSON Path</label>';
        html += '<input type="text" class="fpc-input" id="cred-gsc-sa" value="' + (d.gsc_service_account || '') + '" placeholder="e.g. cache/fpc/google-service-account.json" style="width:100%;">';
        html += '<small style="color:var(--fpc-text2);font-size:11px;">Relative path from shop root. Used for both GSC and GA4.</small></div>';

        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">GSC Site URL</label>';
        html += '<input type="text" class="fpc-input" id="cred-gsc-url" value="' + (d.gsc_site_url || 'https://mr-hanf.de/') + '" style="width:100%;">';
        html += '<small style="color:var(--fpc-text2);font-size:11px;">Your Search Console property URL</small></div>';

        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">GA4 Service Account JSON Path</label>';
        html += '<input type="text" class="fpc-input" id="cred-ga4-sa" value="' + (d.ga4_service_account || '') + '" placeholder="Same as GSC or separate file" style="width:100%;">';
        html += '<small style="color:var(--fpc-text2);font-size:11px;">Can be the same file as GSC if scopes are configured</small></div>';

        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">GA4 Property ID</label>';
        html += '<input type="text" class="fpc-input" id="cred-ga4-prop" value="' + (d.ga4_property_id || '') + '" placeholder="e.g. 123456789" style="width:100%;">';
        html += '<small style="color:var(--fpc-text2);font-size:11px;">Numeric GA4 Property ID (Admin > Property Settings)</small></div>';

        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">SISTRIX API Key</label>';
        html += '<input type="text" class="fpc-input" id="cred-sx-key" value="' + (d.sistrix_api_key || '') + '" placeholder="Your SISTRIX API key" style="width:100%;">';
        html += '<small style="color:var(--fpc-text2);font-size:11px;">From sistrix.de > My Account > API</small></div>';

        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">SISTRIX Domain</label>';
        html += '<input type="text" class="fpc-input" id="cred-sx-domain" value="' + (d.sistrix_domain || 'mr-hanf.de') + '" style="width:100%;">';
        html += '<small style="color:var(--fpc-text2);font-size:11px;">Domain to analyze</small></div>';

        html += '<div style="grid-column:1/-1;"><hr style="border-color:var(--fpc-border);margin:8px 0;"><h4 style="color:var(--fpc-purple);margin:0 0 8px 0;">KI-Analyse (OpenAI)</h4></div>';

        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">OpenAI API Key</label>';
        html += '<input type="password" class="fpc-input" id="cred-openai-key" value="' + (d.openai_api_key || '') + '" placeholder="sk-..." style="width:100%;">';
        html += '<small style="color:var(--fpc-text2);font-size:11px;">Von platform.openai.com > API Keys</small></div>';

        html += '<div><label style="color:var(--fpc-text2);font-size:12px;display:block;margin-bottom:4px;">OpenAI Modell</label>';
        html += '<select class="fpc-input" id="cred-openai-model" style="width:100%;">';
        var models = ['gpt-4.1-mini','gpt-4.1-nano','gpt-4o-mini','gpt-4o','gpt-4.1'];
        models.forEach(function(m) { html += '<option value="' + m + '"' + (d.openai_model === m ? ' selected' : '') + '>' + m + '</option>'; });
        html += '</select>';
        html += '<small style="color:var(--fpc-text2);font-size:11px;">gpt-4.1-mini empfohlen (guenstig + gut)</small></div>';

        html += '</div>';
        document.getElementById('settings-api-creds').innerHTML = html;
    });
}

function fpcSaveApiCredentials() {
    var creds = {
        gsc_service_account: document.getElementById('cred-gsc-sa').value,
        gsc_site_url: document.getElementById('cred-gsc-url').value,
        ga4_service_account: document.getElementById('cred-ga4-sa').value,
        ga4_property_id: document.getElementById('cred-ga4-prop').value,
        sistrix_api_key: document.getElementById('cred-sx-key').value,
        sistrix_domain: document.getElementById('cred-sx-domain').value,
        openai_api_key: document.getElementById('cred-openai-key').value,
        openai_model: document.getElementById('cred-openai-model').value,
    };
    fpcAjaxPostJson('save_api_credentials', creds, function(r) { fpcToast(r.msg, !r.ok); });
}

// ============================================================
// INIT: Tab-specific data loading
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    var tab = '<?php echo $active_tab; ?>';
    switch (tab) {
        case 'dashboard': fpcLoadDashboard(); break;
        case 'performance': fpcLoadPerformance(); break;
        case 'coverage': fpcLoadCoverage(); break;
        case 'steuerung': fpcLoadCustomUrls(); fpcPollProgress(); break;
        case 'urls': fpcLoadUrls(1); break;
        case 'preloader': fpcLoadPreloader(); break;
        case 'fehler': fpcLoadFehler(); break;
        case 'seo': fpcLoadSeo(); break;
        case 'inspector': fpcLoadInspector(''); break;
        case 'health': fpcLoadHealth(); break;
        case 'statistik': fpcLoadStats(); break;
        case 'alerts': fpcLoadAlerts(); break;
        case 'settings': fpcLoadSettings(); fpcLoadApiCredentials(); fpcAiPromptLoad(); break;
        case 'gsc': fpcLoadGSC(); break;
        case 'analytics': fpcLoadGA4(); break;
        case 'sistrix': fpcLoadSistrix(); break;
    }
    // Check if rebuild is running
    fpcAjax('ajax=rebuild_progress', function(d) {
        if (d.running) fpcStartProgressPoll();
    });
});
</script>
</body>
</html>
