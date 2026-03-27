<?php
/**
 * Mr. Hanf FPC Schaltzentrale v8.3.0
 *
 * Vollstaendiges Dashboard fuer das Full Page Cache System.
 * Wird als eigenstaendige Admin-Seite unter Statistiken eingebunden.
 *
 * Tabs:
 *   1. Dashboard     - KPI-Kacheln, Charts, Uebersicht, Quick-Actions
 *   2. Steuerung     - Cache leeren, neu aufbauen, einzelne URLs cachen
 *   3. URLs          - Alle gecachten URLs durchsuchen, filtern, verwalten
 *   4. Logs          - Preloader-Log, Rebuild-Log, Live-Ansicht
 *   5. Monitoring    - Automatische Tests, Redirect-Pruefung, Historie
 *   6. Health-Check  - Health-Score, SSL, Fehler, langsame URLs, Trends
 *   7. Statistik     - Besucherstatistik, Absprungrate, Verweildauer, Geraete
 *   8. Fehler-Log    - PHP-Error-Log, FPC-Fehler, Severity-Filter
 *
 * @version   8.3.0
 * @date      2026-03-27
 */

// modified eCommerce Admin-Bootstrap
define('_VALID_XTC', true);
$current_page = 'fpc_dashboard.php';

require('includes/application_top.php');

// ============================================================
// KONFIGURATION
// ============================================================
$base_dir  = defined('DIR_FS_DOCUMENT_ROOT') ? DIR_FS_DOCUMENT_ROOT : (defined('DIR_FS_CATALOG') ? DIR_FS_CATALOG : '');
$cache_dir = $base_dir . 'cache/fpc/';
$pid_file  = $cache_dir . 'rebuild.pid';
$log_file  = $cache_dir . 'preloader.log';
$rebuild_log = $cache_dir . 'rebuild_manual.log';
$monitor_log = $cache_dir . 'monitor.json';
$custom_urls_file = $cache_dir . 'custom_urls.txt';
$healthcheck_file = $cache_dir . 'healthcheck.json';
$tracker_dir = $base_dir . 'cache/fpc/tracker/';

// Aktiven Tab ermitteln
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$allowed_tabs = array('dashboard', 'steuerung', 'urls', 'logs', 'monitoring', 'healthcheck', 'statistik', 'fehlerlog');
if (!in_array($active_tab, $allowed_tabs)) $active_tab = 'dashboard';

// ============================================================
// AJAX-ENDPUNKTE (JSON-Responses)
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_GET['ajax']) {

        // --- Status-Daten fuer Dashboard ---
        case 'status':
            $data = fpc_get_status($cache_dir, $pid_file, $log_file, $healthcheck_file);
            echo json_encode($data);
            exit;

        // --- Log-Inhalt laden ---
        case 'log':
            $type = isset($_GET['type']) ? $_GET['type'] : 'preloader';
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
            $allowed_logs = array(
                'preloader'   => $log_file,
                'rebuild'     => $rebuild_log,
                'healthcheck' => $cache_dir . 'healthcheck_cron.log',
            );
            $file = isset($allowed_logs[$type]) ? $allowed_logs[$type] : $log_file;
            echo json_encode(array('content' => fpc_tail_file($file, $lines), 'file' => basename($file)));
            exit;

        // --- Gecachte URLs auflisten ---
        case 'urls':
            $search = isset($_GET['search']) ? $_GET['search'] : '';
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $per_page = 50;
            echo json_encode(fpc_list_cached_urls($cache_dir, $search, $page, $per_page));
            exit;

        // --- Einzelne URL cachen ---
        case 'cache_url':
            $url = isset($_POST['url']) ? trim($_POST['url']) : '';
            if (empty($url)) { echo json_encode(array('ok' => false, 'msg' => 'Keine URL angegeben')); exit; }
            echo json_encode(fpc_cache_single_url($url, $cache_dir, $base_dir));
            exit;

        // --- Einzelne URL aus Cache entfernen ---
        case 'remove_url':
            $path = isset($_POST['path']) ? trim($_POST['path']) : '';
            if (empty($path)) { echo json_encode(array('ok' => false, 'msg' => 'Kein Pfad angegeben')); exit; }
            echo json_encode(fpc_remove_cached_url($cache_dir, $path));
            exit;

        // --- Cache leeren ---
        case 'flush':
            fpc_flush_cache($cache_dir);
            echo json_encode(array('ok' => true, 'msg' => 'Cache wurde geleert'));
            exit;

        // --- Rebuild starten ---
        case 'rebuild':
            echo json_encode(fpc_trigger_rebuild($base_dir, $cache_dir, $pid_file));
            exit;

        // --- Rebuild stoppen ---
        case 'stop':
            fpc_stop_rebuild($pid_file);
            echo json_encode(array('ok' => true, 'msg' => 'Rebuild gestoppt'));
            exit;

        // --- Monitoring-Daten ---
        case 'monitor_data':
            echo json_encode(fpc_get_monitor_data($monitor_log));
            exit;

        // --- Monitoring-Test starten ---
        case 'run_monitor':
            $count = isset($_POST['count']) ? (int)$_POST['count'] : 20;
            echo json_encode(fpc_run_monitor_test($cache_dir, $monitor_log, $base_dir, $count));
            exit;

        // --- Custom URL hinzufuegen ---
        case 'add_custom_url':
            $url = isset($_POST['url']) ? trim($_POST['url']) : '';
            if (empty($url)) { echo json_encode(array('ok' => false, 'msg' => 'Keine URL angegeben')); exit; }
            echo json_encode(fpc_add_custom_url($custom_urls_file, $url));
            exit;

        // --- Custom URLs laden ---
        case 'custom_urls':
            echo json_encode(fpc_get_custom_urls($custom_urls_file));
            exit;

        // --- Custom URL entfernen ---
        case 'remove_custom_url':
            $url = isset($_POST['url']) ? trim($_POST['url']) : '';
            echo json_encode(fpc_remove_custom_url($custom_urls_file, $url));
            exit;

        // --- Health-Check Daten ---
        case 'healthcheck':
            echo json_encode(fpc_get_healthcheck_data($healthcheck_file));
            exit;

        // --- Health-Check manuell starten ---
        case 'run_healthcheck':
            echo json_encode(fpc_trigger_healthcheck($base_dir));
            exit;

        // --- Besucherstatistik ---
        case 'visitor_stats':
            $days = isset($_GET['days']) ? min(90, max(1, (int)$_GET['days'])) : 30;
            echo json_encode(fpc_get_visitor_stats($tracker_dir, $days));
            exit;

        // --- PHP-Fehler-Log ---
        case 'error_log':
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
            $filter = isset($_GET['filter']) ? $_GET['filter'] : '';
            echo json_encode(fpc_get_error_log($lines, $filter));
            exit;

        // --- CSV-Export gecachte URLs ---
        case 'export_urls':
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="fpc_urls_' . date('Y-m-d') . '.csv"');
            fpc_export_urls_csv($cache_dir);
            exit;

        // --- htaccess-Validator ---
        case 'validate_htaccess':
            echo json_encode(fpc_validate_htaccess($base_dir));
            exit;
    }
    exit;
}

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function fpc_get_status($cache_dir, $pid_file, $log_file, $healthcheck_file) {
    $files = 0; $size = 0; $oldest = PHP_INT_MAX; $newest = 0;
    $categories = array();

    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'html') {
                $files++;
                $size += $f->getSize();
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
        if (preg_match('/\[FPC\] Fertig: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $tail, $m)) {
            $last_run = $m[1];
        }
        if (preg_match('/Gecacht: (\d+) \| Uebersprungen: (\d+).*Fehler: (\d+)/', $tail, $m2)) {
            $last_stats = array('cached' => (int)$m2[1], 'skipped' => (int)$m2[2], 'errors' => (int)$m2[3]);
        }
    }

    $rebuild_running = false;
    $rebuild_started = null;
    if (is_file($pid_file)) {
        $content = file_get_contents($pid_file);
        $lines = explode("\n", trim($content));
        $pid = (int)$lines[0];
        if ($pid > 0) {
            $running = function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir('/proc/' . $pid);
            if ($running) {
                $rebuild_running = true;
                $rebuild_started = isset($lines[1]) ? $lines[1] : null;
            } else {
                @unlink($pid_file);
            }
        }
    }

    // Health-Score laden
    $health_score = null;
    $health_grade = null;
    if (is_file($healthcheck_file)) {
        $hc = @json_decode(file_get_contents($healthcheck_file), true);
        if (isset($hc['latest']['summary'])) {
            $health_score = $hc['latest']['summary']['health_score'];
            $health_grade = $hc['latest']['summary']['health_grade'];
        }
    }

    arsort($categories);
    $top_categories = array_slice($categories, 0, 15, true);

    return array(
        'files'            => $files,
        'size'             => $size,
        'size_formatted'   => fpc_format_bytes($size),
        'oldest'           => $oldest < PHP_INT_MAX ? date('Y-m-d H:i', $oldest) : null,
        'newest'           => $newest > 0 ? date('Y-m-d H:i', $newest) : null,
        'last_run'         => $last_run,
        'last_stats'       => $last_stats,
        'rebuild_running'  => $rebuild_running,
        'rebuild_started'  => $rebuild_started,
        'categories'       => $top_categories,
        'health_score'     => $health_score,
        'health_grade'     => $health_grade,
    );
}

function fpc_tail_file($file, $lines = 100) {
    if (!is_file($file)) return '(Datei nicht gefunden: ' . basename($file) . ')';
    $content = @file($file, FILE_IGNORE_NEW_LINES);
    if (!$content) return '(Datei leer)';
    $tail = array_slice($content, -$lines);
    return implode("\n", $tail);
}

function fpc_format_bytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' Bytes';
}

function fpc_list_cached_urls($cache_dir, $search, $page, $per_page) {
    $all = array();
    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'html') continue;
            $rel = str_replace($cache_dir, '', $f->getPathname());
            $url_path = '/' . str_replace('/index.html', '', $rel);
            if ($url_path === '/') $url_path = '/';
            else $url_path .= '/';

            if (!empty($search) && stripos($url_path, $search) === false) continue;

            $all[] = array(
                'path'   => $url_path,
                'size'   => $f->getSize(),
                'cached' => date('Y-m-d H:i', $f->getMTime()),
                'age_h'  => round((time() - $f->getMTime()) / 3600, 1),
            );
        }
    }
    usort($all, function($a, $b) { return strcmp($a['path'], $b['path']); });
    $total = count($all);
    $pages = max(1, ceil($total / $per_page));
    $offset = ($page - 1) * $per_page;
    return array(
        'total' => $total,
        'page'  => $page,
        'pages' => $pages,
        'urls'  => array_slice($all, $offset, $per_page),
    );
}

function fpc_cache_single_url($url, $cache_dir, $base_dir) {
    if (strpos($url, 'http') !== 0) {
        $url = 'https://mr-hanf.de' . (substr($url, 0, 1) !== '/' ? '/' : '') . $url;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'FPC-Preloader/8.3 (Manual)',
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || strlen($html) < 1000) {
        return array('ok' => false, 'msg' => "HTTP {$code} - Seite konnte nicht geladen werden (" . strlen($html) . " Bytes)");
    }

    $parsed = parse_url($url);
    $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
    if (empty($path)) $path = '';
    $file_path = $cache_dir . ($path ? $path . '/' : '') . 'index.html';
    $dir = dirname($file_path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $html .= "\n<!-- FPC-VALID -->";
    file_put_contents($file_path, $html);

    return array('ok' => true, 'msg' => "Gecacht: {$url} (" . fpc_format_bytes(strlen($html)) . ")");
}

function fpc_remove_cached_url($cache_dir, $path) {
    $full = $cache_dir . ltrim($path, '/');
    if (is_file($full . '/index.html')) {
        @unlink($full . '/index.html');
        @rmdir($full);
        return array('ok' => true, 'msg' => 'Entfernt: ' . $path);
    }
    if (is_file($full)) {
        @unlink($full);
        return array('ok' => true, 'msg' => 'Entfernt: ' . $path);
    }
    return array('ok' => false, 'msg' => 'Datei nicht gefunden: ' . $path);
}

function fpc_flush_cache($cache_dir) {
    if (!is_dir($cache_dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        if ($f->isFile() && $f->getExtension() === 'html') @unlink($f->getPathname());
    }
    // Leere Verzeichnisse aufraeumen (nicht tracker/ und nicht cache/fpc/ selbst)
    $dirs = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($dirs as $d) {
        if ($d->isDir() && basename($d->getPathname()) !== 'tracker') {
            @rmdir($d->getPathname());
        }
    }
}

function fpc_trigger_rebuild($base_dir, $cache_dir, $pid_file) {
    if (is_file($pid_file)) {
        $content = file_get_contents($pid_file);
        $pid = (int)trim(explode("\n", $content)[0]);
        if ($pid > 0) {
            $running = function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir('/proc/' . $pid);
            if ($running) return array('ok' => false, 'msg' => 'Rebuild laeuft bereits (PID: ' . $pid . ')');
        }
    }
    $cmd = 'cd ' . escapeshellarg($base_dir) . ' && nohup /usr/local/bin/php fpc_preloader.php > '
         . escapeshellarg($cache_dir . 'rebuild_manual.log') . ' 2>&1 & echo $!';
    $pid = trim(shell_exec($cmd));
    if ($pid && is_numeric($pid)) {
        file_put_contents($pid_file, $pid . "\n" . date('Y-m-d H:i:s'));
        return array('ok' => true, 'msg' => 'Rebuild gestartet (PID: ' . $pid . ')');
    }
    return array('ok' => false, 'msg' => 'Konnte Rebuild nicht starten');
}

function fpc_stop_rebuild($pid_file) {
    if (!is_file($pid_file)) return;
    $content = file_get_contents($pid_file);
    $pid = (int)trim(explode("\n", $content)[0]);
    if ($pid > 0) {
        if (function_exists('posix_kill')) posix_kill($pid, 15);
        else @exec('kill ' . (int)$pid);
    }
    @unlink($pid_file);
}

function fpc_get_monitor_data($monitor_log) {
    if (!is_file($monitor_log)) return array('runs' => array(), 'latest' => null);
    $data = @json_decode(file_get_contents($monitor_log), true);
    return is_array($data) ? $data : array('runs' => array(), 'latest' => null);
}

function fpc_run_monitor_test($cache_dir, $monitor_log, $base_dir, $count) {
    $urls = array();
    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'html') {
                $rel = str_replace($cache_dir, '', $f->getPathname());
                $url_path = '/' . str_replace('/index.html', '', $rel);
                if ($url_path !== '/') $url_path .= '/';
                $urls[] = $url_path;
            }
        }
    }
    if (empty($urls)) return array('ok' => false, 'msg' => 'Keine gecachten URLs gefunden');

    shuffle($urls);
    $test_urls = array_slice($urls, 0, min($count, count($urls)));

    $results = array();
    $hits = 0; $ttfb_sum = 0; $errors = 0;

    foreach ($test_urls as $path) {
        $ch = curl_init('https://mr-hanf.de' . $path);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'FPC-Monitor/8.3',
        ));
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        $header_size = $info['header_size'];
        $headers = substr($resp, 0, $header_size);
        curl_close($ch);

        $code = $info['http_code'];
        $ttfb = round($info['starttransfer_time'] * 1000);
        $fpc = 'MISS';
        if (preg_match('/X-FPC-Cache:\s*(\S+)/i', $headers, $hm)) $fpc = strtoupper(trim($hm[1]));

        if ($fpc === 'HIT') $hits++;
        if ($code >= 400 || $code === 0) $errors++;
        $ttfb_sum += $ttfb;

        $results[] = array('url' => $path, 'http' => $code, 'fpc' => $fpc, 'ttfb' => $ttfb);
    }

    $run = array(
        'timestamp' => date('Y-m-d H:i:s'),
        'tested'    => count($test_urls),
        'hits'      => $hits,
        'hit_rate'  => count($test_urls) > 0 ? round(($hits / count($test_urls)) * 100, 1) : 0,
        'avg_ttfb'  => count($test_urls) > 0 ? round($ttfb_sum / count($test_urls)) : 0,
        'errors'    => $errors,
        'results'   => $results,
    );

    // In Monitor-Log speichern
    $existing = fpc_get_monitor_data($monitor_log);
    $existing['latest'] = $run;
    $existing['runs'][] = array(
        'timestamp' => $run['timestamp'],
        'tested'    => $run['tested'],
        'hits'      => $run['hits'],
        'hit_rate'  => $run['hit_rate'],
        'avg_ttfb'  => $run['avg_ttfb'],
        'errors'    => $run['errors'],
    );
    if (count($existing['runs']) > 100) {
        $existing['runs'] = array_slice($existing['runs'], -100);
    }
    @file_put_contents($monitor_log, json_encode($existing, JSON_PRETTY_PRINT));

    return array('ok' => true, 'msg' => 'Test abgeschlossen', 'data' => $run);
}

function fpc_add_custom_url($file, $url) {
    $urls = fpc_get_custom_urls($file);
    $url = trim($url);
    if (in_array($url, $urls)) return array('ok' => false, 'msg' => 'URL bereits vorhanden');
    $urls[] = $url;
    @file_put_contents($file, implode("\n", $urls) . "\n");
    return array('ok' => true, 'msg' => 'Hinzugefuegt: ' . $url);
}

function fpc_get_custom_urls($file) {
    if (!is_file($file)) return array();
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_values(array_filter(array_map('trim', $lines)));
}

function fpc_remove_custom_url($file, $url) {
    $urls = fpc_get_custom_urls($file);
    $urls = array_filter($urls, function($u) use ($url) { return $u !== $url; });
    @file_put_contents($file, implode("\n", $urls) . "\n");
    return array('ok' => true, 'msg' => 'Entfernt: ' . $url);
}

// --- v8.3.0: Health-Check Daten ---
function fpc_get_healthcheck_data($file) {
    if (!is_file($file)) return array('available' => false, 'msg' => 'Noch kein Health-Check ausgefuehrt. Starten Sie den Cron-Job oder klicken Sie auf "Jetzt pruefen".');
    $data = @json_decode(file_get_contents($file), true);
    if (!is_array($data)) return array('available' => false, 'msg' => 'Health-Check Daten fehlerhaft');
    $data['available'] = true;
    return $data;
}

function fpc_trigger_healthcheck($base_dir) {
    $cmd = 'cd ' . escapeshellarg($base_dir) . ' && nohup /usr/local/bin/php fpc_healthcheck.php > /dev/null 2>&1 &';
    @exec($cmd);
    return array('ok' => true, 'msg' => 'Health-Check gestartet (laeuft im Hintergrund, ~1-3 Minuten)');
}

// --- v8.3.0: Besucherstatistik ---
function fpc_get_visitor_stats($tracker_dir, $days) {
    if (!is_dir($tracker_dir)) return array('available' => false, 'msg' => 'Tracker-Verzeichnis nicht gefunden. Bitte fpc_tracker.php deployen.');

    $result = array(
        'available'    => true,
        'period_days'  => $days,
        'totals'       => array('pageviews' => 0, 'visitors' => 0, 'bounces' => 0, 'bounce_rate' => 0, 'avg_duration' => 0, 'avg_pages' => 0),
        'daily'        => array(),
        'hours'        => array_fill(0, 24, 0),
        'top_pages'    => array(),
        'top_referrers'=> array(),
        'devices'      => array('desktop' => 0, 'mobile' => 0, 'tablet' => 0),
    );

    $all_pages = array();
    $all_referrers = array();
    $all_durations = array();
    $total_pages_per_session = array();

    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $file = $tracker_dir . $date . '.json';

        $day = array('date' => $date, 'pageviews' => 0, 'visitors' => 0, 'bounces' => 0, 'bounce_rate' => 0, 'avg_duration' => 0);

        if (is_file($file)) {
            $data = @json_decode(file_get_contents($file), true);
            if (!is_array($data)) { $result['daily'][] = $day; continue; }

            $day['pageviews'] = isset($data['pageviews']) ? $data['pageviews'] : 0;
            $day['visitors']  = isset($data['visitors']) ? $data['visitors'] : 0;

            $bounces = 0;
            $durations = array();
            if (!empty($data['sessions'])) {
                foreach ($data['sessions'] as $session) {
                    $pages = isset($session['pages']) ? $session['pages'] : 1;
                    if ($pages <= 1) $bounces++;
                    $total_pages_per_session[] = $pages;
                    $dur = 0;
                    if (isset($session['duration']) && $session['duration'] > 0) $dur = $session['duration'];
                    elseif (isset($session['last_time'], $session['start_time'])) $dur = $session['last_time'] - $session['start_time'];
                    if ($dur > 0 && $dur < 3600) { $durations[] = $dur; $all_durations[] = $dur; }
                }
            }
            $day['bounces'] = $bounces;
            $day['bounce_rate'] = $day['visitors'] > 0 ? round(($bounces / $day['visitors']) * 100, 1) : 0;
            $day['avg_duration'] = count($durations) > 0 ? round(array_sum($durations) / count($durations)) : 0;

            if (!empty($data['hours'])) foreach ($data['hours'] as $h => $cnt) $result['hours'][$h] += $cnt;
            if (!empty($data['pages'])) foreach ($data['pages'] as $p => $cnt) $all_pages[$p] = ($all_pages[$p] ?? 0) + $cnt;
            if (!empty($data['referrers'])) foreach ($data['referrers'] as $r => $cnt) $all_referrers[$r] = ($all_referrers[$r] ?? 0) + $cnt;
            if (!empty($data['devices'])) foreach ($data['devices'] as $d => $cnt) $result['devices'][$d] = ($result['devices'][$d] ?? 0) + $cnt;

            $result['totals']['pageviews'] += $day['pageviews'];
            $result['totals']['visitors']  += $day['visitors'];
            $result['totals']['bounces']   += $bounces;
        }
        $result['daily'][] = $day;
    }

    $result['totals']['bounce_rate'] = $result['totals']['visitors'] > 0
        ? round(($result['totals']['bounces'] / $result['totals']['visitors']) * 100, 1) : 0;
    $result['totals']['avg_duration'] = count($all_durations) > 0
        ? round(array_sum($all_durations) / count($all_durations)) : 0;
    $result['totals']['avg_pages'] = count($total_pages_per_session) > 0
        ? round(array_sum($total_pages_per_session) / count($total_pages_per_session), 1) : 0;

    arsort($all_pages);
    $result['top_pages'] = array_slice($all_pages, 0, 30, true);
    arsort($all_referrers);
    $result['top_referrers'] = array_slice($all_referrers, 0, 20, true);

    return $result;
}

// --- v8.3.0: PHP-Fehler-Log ---
function fpc_get_error_log($lines, $filter) {
    // Verschiedene moegliche Error-Log-Pfade
    $possible_logs = array(
        ini_get('error_log'),
        '/var/log/php_errors.log',
        '/var/log/apache2/error.log',
        '/tmp/php_errors.log',
    );

    $log_file = null;
    foreach ($possible_logs as $path) {
        if (!empty($path) && is_file($path) && is_readable($path)) {
            $log_file = $path;
            break;
        }
    }

    if (!$log_file) {
        return array('content' => '(Kein lesbares PHP-Error-Log gefunden)', 'file' => 'unbekannt', 'entries' => array());
    }

    $content = @file($log_file, FILE_IGNORE_NEW_LINES);
    if (!$content) return array('content' => '(Log leer)', 'file' => basename($log_file), 'entries' => array());

    $tail = array_slice($content, -$lines);

    // Filtern
    if (!empty($filter)) {
        $tail = array_filter($tail, function($line) use ($filter) {
            return stripos($line, $filter) !== false;
        });
    }

    // Entries mit Severity parsen
    $entries = array();
    foreach ($tail as $line) {
        $severity = 'info';
        if (stripos($line, 'fatal') !== false || stripos($line, 'critical') !== false) $severity = 'critical';
        elseif (stripos($line, 'error') !== false) $severity = 'error';
        elseif (stripos($line, 'warning') !== false || stripos($line, 'warn') !== false) $severity = 'warning';
        elseif (stripos($line, 'notice') !== false || stripos($line, 'deprecated') !== false) $severity = 'notice';

        $is_fpc = (stripos($line, 'fpc') !== false || stripos($line, 'cache') !== false);

        $entries[] = array('line' => $line, 'severity' => $severity, 'fpc_related' => $is_fpc);
    }

    return array('content' => implode("\n", $tail), 'file' => basename($log_file), 'entries' => $entries);
}

// --- v8.3.0: CSV-Export ---
function fpc_export_urls_csv($cache_dir) {
    echo "URL;Groesse;Gecacht;Alter_Stunden\n";
    if (!is_dir($cache_dir)) return;
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'html') continue;
        $rel = str_replace($cache_dir, '', $f->getPathname());
        $url_path = '/' . str_replace('/index.html', '', $rel);
        if ($url_path !== '/') $url_path .= '/';
        echo $url_path . ';' . $f->getSize() . ';' . date('Y-m-d H:i', $f->getMTime()) . ';' . round((time() - $f->getMTime()) / 3600, 1) . "\n";
    }
}

// --- v8.3.0: htaccess-Validator ---
function fpc_validate_htaccess($base_dir) {
    $htaccess = $base_dir . '.htaccess';
    if (!is_file($htaccess)) return array('ok' => false, 'msg' => '.htaccess nicht gefunden', 'checks' => array());

    $content = file_get_contents($htaccess);
    $checks = array();

    // Pruefe FPC-Regeln
    $checks[] = array(
        'name' => 'RewriteEngine On',
        'ok' => (bool)preg_match('/RewriteEngine\s+On/i', $content),
    );
    $checks[] = array(
        'name' => 'FPC-Bypass Cookie-Bedingung',
        'ok' => strpos($content, 'fpc_bypass') !== false,
    );
    $checks[] = array(
        'name' => 'POST-Requests Bypass',
        'ok' => (bool)preg_match('/RewriteCond.*REQUEST_METHOD.*POST/i', $content),
    );
    $checks[] = array(
        'name' => 'Query-String Bypass',
        'ok' => (bool)preg_match('/RewriteCond.*QUERY_STRING.*\^\$/', $content),
    );
    $checks[] = array(
        'name' => 'MODsid Cookie Bypass',
        'ok' => strpos($content, 'MODsid') !== false,
    );
    $checks[] = array(
        'name' => 'Cache-Datei Existenz-Check',
        'ok' => strpos($content, 'cache/fpc') !== false,
    );
    $checks[] = array(
        'name' => 'fpc_serve.php Fallback',
        'ok' => strpos($content, 'fpc_serve.php') !== false,
    );
    $checks[] = array(
        'name' => 'Bot/Crawler Bypass',
        'ok' => (bool)preg_match('/HTTP_USER_AGENT.*(bot|crawl|spider)/i', $content),
    );

    $passed = count(array_filter($checks, function($c) { return $c['ok']; }));
    $total = count($checks);

    return array(
        'ok' => $passed === $total,
        'msg' => $passed . '/' . $total . ' Checks bestanden',
        'checks' => $checks,
        'score' => round(($passed / $total) * 100),
    );
}

// ============================================================
// SEITENAUSGABE (HTML)
// ============================================================
$page_title = defined('HEADING_TITLE') ? HEADING_TITLE : 'FPC Schaltzentrale';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FPC Schaltzentrale</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<style>
/* ============================================================
   FPC SCHALTZENTRALE v8.3.0 - CSS
   ============================================================ */
:root {
    --fpc-bg: #0f1923;
    --fpc-card: #1a2736;
    --fpc-border: #2a3a4a;
    --fpc-text: #e8edf2;
    --fpc-text2: #8899aa;
    --fpc-teal: #00d4aa;
    --fpc-blue: #00a8ff;
    --fpc-green: #2ed573;
    --fpc-orange: #ffa502;
    --fpc-red: #ff4757;
    --fpc-purple: #a55eea;
    --fpc-yellow: #fed330;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--fpc-bg); color: var(--fpc-text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; line-height: 1.5; }
.fpc-wrap { max-width: 1400px; margin: 0 auto; padding: 20px; }

/* Header */
.fpc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--fpc-border); }
.fpc-header h1 { font-size: 22px; font-weight: 600; }
.fpc-header h1 span { margin-right: 8px; }
.fpc-header-right { display: flex; align-items: center; gap: 15px; }
.fpc-version { background: var(--fpc-teal); color: #000; padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; }
#fpc-clock { color: var(--fpc-text2); font-size: 13px; font-family: monospace; }

/* Quick Actions */
.fpc-quick-actions { display: flex; gap: 8px; }
.fpc-quick-btn { background: var(--fpc-card); border: 1px solid var(--fpc-border); color: var(--fpc-text2); padding: 4px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; transition: all 0.2s; }
.fpc-quick-btn:hover { background: var(--fpc-teal); color: #000; border-color: var(--fpc-teal); }

/* Tabs */
.fpc-tabs { display: flex; gap: 4px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 2px; flex-wrap: wrap; }
.fpc-tab { padding: 10px 18px; background: var(--fpc-card); border: 1px solid var(--fpc-border); border-radius: 8px 8px 0 0; color: var(--fpc-text2); cursor: pointer; font-size: 13px; white-space: nowrap; text-decoration: none; transition: all 0.2s; }
.fpc-tab:hover { color: var(--fpc-text); background: #243447; }
.fpc-tab.active { background: var(--fpc-teal); color: #000; font-weight: 600; border-color: var(--fpc-teal); }
.fpc-tab-icon { margin-right: 5px; }

/* Tab Panels */
.fpc-tab-panel { display: none; }
.fpc-tab-panel.active { display: block; }
.fpc-content { background: var(--fpc-card); border: 1px solid var(--fpc-border); border-radius: 0 8px 8px 8px; padding: 24px; min-height: 500px; }

/* KPI Cards */
.fpc-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
.fpc-kpi { background: var(--fpc-bg); border-radius: 10px; padding: 18px; border-left: 4px solid var(--fpc-border); }
.fpc-kpi.teal { border-left-color: var(--fpc-teal); }
.fpc-kpi.blue { border-left-color: var(--fpc-blue); }
.fpc-kpi.green { border-left-color: var(--fpc-green); }
.fpc-kpi.orange { border-left-color: var(--fpc-orange); }
.fpc-kpi.red { border-left-color: var(--fpc-red); }
.fpc-kpi.purple { border-left-color: var(--fpc-purple); }
.fpc-kpi-label { font-size: 12px; color: var(--fpc-text2); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
.fpc-kpi-value { font-size: 28px; font-weight: 700; }
.fpc-kpi-sub { font-size: 12px; color: var(--fpc-text2); margin-top: 4px; }

/* Charts */
.fpc-charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 24px; }
.fpc-chart-card { background: var(--fpc-bg); border-radius: 10px; padding: 20px; }
.fpc-chart-title { font-size: 14px; font-weight: 600; margin-bottom: 12px; color: var(--fpc-text2); }

/* Buttons */
.fpc-btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s; color: #fff; }
.fpc-btn:hover { opacity: 0.85; transform: translateY(-1px); }
.fpc-btn:active { transform: translateY(0); }
.fpc-btn.teal { background: var(--fpc-teal); color: #000; }
.fpc-btn.blue { background: var(--fpc-blue); }
.fpc-btn.green { background: var(--fpc-green); color: #000; }
.fpc-btn.orange { background: var(--fpc-orange); color: #000; }
.fpc-btn.red { background: var(--fpc-red); }
.fpc-btn.dark { background: #2a3a4a; }
.fpc-btn.purple { background: var(--fpc-purple); }
.fpc-btn-group { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }

/* Inputs */
.fpc-input { background: var(--fpc-bg); border: 1px solid var(--fpc-border); color: var(--fpc-text); padding: 10px 14px; border-radius: 8px; font-size: 13px; }
.fpc-input:focus { outline: none; border-color: var(--fpc-teal); }
.fpc-input-group { display: flex; gap: 8px; margin-bottom: 16px; }
.fpc-input-group .fpc-input { flex: 1; }

/* Section Title */
.fpc-section-title { font-size: 16px; font-weight: 600; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--fpc-border); }

/* Tables */
.fpc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.fpc-table th { background: var(--fpc-bg); padding: 10px 12px; text-align: left; font-weight: 600; color: var(--fpc-text2); border-bottom: 2px solid var(--fpc-border); }
.fpc-table td { padding: 8px 12px; border-bottom: 1px solid var(--fpc-border); }
.fpc-table tr:hover td { background: rgba(0,212,170,0.05); }

/* Log Viewer */
.fpc-log { background: #0a1018; border: 1px solid var(--fpc-border); border-radius: 8px; padding: 16px; font-family: 'Fira Code', 'Cascadia Code', monospace; font-size: 12px; line-height: 1.6; max-height: 500px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; color: var(--fpc-text2); }

/* Pagination */
.fpc-pagination { display: flex; gap: 6px; margin-top: 16px; justify-content: center; }
.fpc-pagination button { background: var(--fpc-card); border: 1px solid var(--fpc-border); color: var(--fpc-text2); padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px; }
.fpc-pagination button.active { background: var(--fpc-teal); color: #000; border-color: var(--fpc-teal); }

/* Toast */
.fpc-toast { position: fixed; bottom: 30px; right: 30px; background: var(--fpc-card); border: 1px solid var(--fpc-teal); color: var(--fpc-text); padding: 14px 24px; border-radius: 10px; font-size: 13px; opacity: 0; transform: translateY(20px); transition: all 0.3s; z-index: 9999; max-width: 400px; }
.fpc-toast.show { opacity: 1; transform: translateY(0); }
.fpc-toast.error { border-color: var(--fpc-red); }

/* Health Score */
.fpc-health-score { display: inline-flex; align-items: center; justify-content: center; width: 80px; height: 80px; border-radius: 50%; font-size: 28px; font-weight: 700; }
.fpc-health-score.grade-a { background: rgba(46,213,115,0.2); color: var(--fpc-green); border: 3px solid var(--fpc-green); }
.fpc-health-score.grade-b { background: rgba(0,168,255,0.2); color: var(--fpc-blue); border: 3px solid var(--fpc-blue); }
.fpc-health-score.grade-c { background: rgba(255,165,2,0.2); color: var(--fpc-orange); border: 3px solid var(--fpc-orange); }
.fpc-health-score.grade-d { background: rgba(255,71,87,0.2); color: var(--fpc-red); border: 3px solid var(--fpc-red); }
.fpc-health-score.grade-f { background: rgba(255,71,87,0.3); color: var(--fpc-red); border: 3px solid var(--fpc-red); }

/* Severity badges */
.sev-critical { color: #fff; background: var(--fpc-red); padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; }
.sev-error { color: #fff; background: #e84118; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.sev-warning { color: #000; background: var(--fpc-orange); padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.sev-notice { color: #000; background: var(--fpc-yellow); padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.sev-ok { color: #000; background: var(--fpc-green); padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.sev-info { color: var(--fpc-text2); font-size: 11px; }

/* Responsive */
@media (max-width: 768px) {
    .fpc-kpis { grid-template-columns: repeat(2, 1fr); }
    .fpc-charts { grid-template-columns: 1fr; }
    .fpc-tabs { gap: 2px; }
    .fpc-tab { padding: 8px 12px; font-size: 12px; }
    .fpc-btn-group { flex-direction: column; }
}
</style>
</head>
<body>
<div class="fpc-wrap">

<div class="fpc-header">
    <h1><span>&#9881;</span> FPC Schaltzentrale</h1>
    <div class="fpc-header-right">
        <div class="fpc-quick-actions">
            <button class="fpc-quick-btn" onclick="fpcFlush()" title="Gesamten Cache leeren">&#128465; Leeren</button>
            <button class="fpc-quick-btn" onclick="fpcRebuild()" title="Cache neu aufbauen">&#8635; Rebuild</button>
            <button class="fpc-quick-btn" onclick="fpcExportUrls()" title="URLs als CSV exportieren">&#128190; Export</button>
        </div>
        <span id="fpc-clock"></span>
        <span class="fpc-version">v8.3.0</span>
    </div>
</div>

<!-- TAB-NAVIGATION -->
<div class="fpc-tabs">
    <a class="fpc-tab <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard">
        <span class="fpc-tab-icon">&#9632;</span> Dashboard
    </a>
    <a class="fpc-tab <?php echo $active_tab === 'steuerung' ? 'active' : ''; ?>" data-tab="steuerung">
        <span class="fpc-tab-icon">&#9881;</span> Steuerung
    </a>
    <a class="fpc-tab <?php echo $active_tab === 'urls' ? 'active' : ''; ?>" data-tab="urls">
        <span class="fpc-tab-icon">&#128279;</span> URLs
    </a>
    <a class="fpc-tab <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" data-tab="logs">
        <span class="fpc-tab-icon">&#128196;</span> Logs
    </a>
    <a class="fpc-tab <?php echo $active_tab === 'monitoring' ? 'active' : ''; ?>" data-tab="monitoring">
        <span class="fpc-tab-icon">&#128200;</span> Monitoring
    </a>
    <a class="fpc-tab <?php echo $active_tab === 'healthcheck' ? 'active' : ''; ?>" data-tab="healthcheck">
        <span class="fpc-tab-icon">&#128154;</span> Health-Check
    </a>
    <a class="fpc-tab <?php echo $active_tab === 'statistik' ? 'active' : ''; ?>" data-tab="statistik">
        <span class="fpc-tab-icon">&#128202;</span> Statistik
    </a>
    <a class="fpc-tab <?php echo $active_tab === 'fehlerlog' ? 'active' : ''; ?>" data-tab="fehlerlog">
        <span class="fpc-tab-icon">&#9888;</span> Fehler-Log
    </a>
</div>

<!-- ============================================================ -->
<!-- TAB 1: DASHBOARD -->
<!-- ============================================================ -->
<div class="fpc-content">
<div class="fpc-tab-panel <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" id="panel-dashboard">
    <div class="fpc-kpis">
        <div class="fpc-kpi teal">
            <div class="fpc-kpi-label">Gecachte Seiten</div>
            <div class="fpc-kpi-value" id="kpi-files">--</div>
            <div class="fpc-kpi-sub" id="kpi-files-sub"></div>
        </div>
        <div class="fpc-kpi blue">
            <div class="fpc-kpi-label">Cache-Groesse</div>
            <div class="fpc-kpi-value" id="kpi-size">--</div>
        </div>
        <div class="fpc-kpi green">
            <div class="fpc-kpi-label">Rebuild-Status</div>
            <div class="fpc-kpi-value" id="kpi-rebuild">--</div>
            <div class="fpc-kpi-sub" id="kpi-rebuild-sub"></div>
        </div>
        <div class="fpc-kpi orange">
            <div class="fpc-kpi-label">Letzter Cron-Lauf</div>
            <div class="fpc-kpi-value" id="kpi-lastrun" style="font-size:18px;">--</div>
            <div class="fpc-kpi-sub" id="kpi-lastrun-sub"></div>
        </div>
        <div class="fpc-kpi purple">
            <div class="fpc-kpi-label">Health-Score</div>
            <div class="fpc-kpi-value" id="kpi-health">--</div>
            <div class="fpc-kpi-sub" id="kpi-health-sub"></div>
        </div>
        <div class="fpc-kpi blue">
            <div class="fpc-kpi-label">Aelteste Datei</div>
            <div class="fpc-kpi-value" id="kpi-oldest" style="font-size:16px;">--</div>
        </div>
        <div class="fpc-kpi teal">
            <div class="fpc-kpi-label">Neueste Datei</div>
            <div class="fpc-kpi-value" id="kpi-newest" style="font-size:16px;">--</div>
        </div>
    </div>
    <div class="fpc-charts">
        <div class="fpc-chart-card">
            <div class="fpc-chart-title">Cache-Verteilung nach Kategorie</div>
            <canvas id="chart-categories" height="280"></canvas>
        </div>
        <div class="fpc-chart-card">
            <div class="fpc-chart-title">Letzter Preloader-Lauf</div>
            <canvas id="chart-laststats" height="280"></canvas>
        </div>
    </div>
</div>

<!-- TAB 2: STEUERUNG -->
<div class="fpc-tab-panel <?php echo $active_tab === 'steuerung' ? 'active' : ''; ?>" id="panel-steuerung">
    <div class="fpc-section-title">&#9881; Cache-Aktionen</div>
    <div class="fpc-btn-group">
        <button class="fpc-btn green" onclick="fpcRebuild()" id="btn-rebuild">&#8635; Cache neu aufbauen</button>
        <button class="fpc-btn orange" onclick="fpcStopRebuild()" id="btn-stop" style="display:none;">&#9632; Rebuild stoppen</button>
        <button class="fpc-btn red" onclick="fpcFlush()">&#128465; Gesamten Cache leeren</button>
    </div>
    <div class="fpc-section-title" style="margin-top:30px;">&#128279; Einzelne URL cachen</div>
    <p style="color:var(--fpc-text2); font-size:13px; margin-bottom:12px;">
        Geben Sie eine URL oder einen Pfad ein (z.B. <code>/samen-shop/autoflowering-samen/</code>), um diese Seite sofort in den Cache aufzunehmen.
    </p>
    <div class="fpc-input-group">
        <input type="text" class="fpc-input" id="single-url" placeholder="URL oder Pfad eingeben...">
        <button class="fpc-btn teal" onclick="fpcCacheSingle()">Cachen</button>
    </div>
    <div id="single-url-result"></div>
    <div class="fpc-section-title" style="margin-top:30px;">&#128203; Eigene URLs verwalten</div>
    <p style="color:var(--fpc-text2); font-size:13px; margin-bottom:12px;">
        URLs die zusaetzlich zum Sitemap-Preloader gecacht werden sollen.
    </p>
    <div class="fpc-input-group">
        <input type="text" class="fpc-input" id="custom-url-input" placeholder="Neue URL hinzufuegen...">
        <button class="fpc-btn blue" onclick="fpcAddCustomUrl()">Hinzufuegen</button>
    </div>
    <div id="custom-urls-list"></div>

    <div class="fpc-section-title" style="margin-top:30px;">&#128736; .htaccess Validator</div>
    <p style="color:var(--fpc-text2); font-size:13px; margin-bottom:12px;">
        Prueft ob alle FPC-relevanten Regeln in der .htaccess korrekt konfiguriert sind.
    </p>
    <button class="fpc-btn purple" onclick="fpcValidateHtaccess()">&#128269; .htaccess pruefen</button>
    <div id="htaccess-result" style="margin-top:12px;"></div>
</div>

<!-- TAB 3: URLs -->
<div class="fpc-tab-panel <?php echo $active_tab === 'urls' ? 'active' : ''; ?>" id="panel-urls">
    <div class="fpc-section-title">&#128279; Gecachte URLs durchsuchen</div>
    <div class="fpc-input-group">
        <input type="text" class="fpc-input" id="url-search" placeholder="Suchen... (z.B. autoflowering, growshop, blog)" oninput="fpcSearchUrls()">
        <button class="fpc-btn dark" onclick="fpcLoadUrls(1)">&#128269; Suchen</button>
        <button class="fpc-btn purple" onclick="fpcExportUrls()">&#128190; CSV Export</button>
    </div>
    <div id="url-count" style="color:var(--fpc-text2); font-size:13px; margin-bottom:12px;"></div>
    <div id="urls-table"></div>
    <div id="urls-pagination" class="fpc-pagination"></div>
</div>

<!-- TAB 4: LOGS -->
<div class="fpc-tab-panel <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" id="panel-logs">
    <div class="fpc-section-title">&#128196; Log-Viewer</div>
    <div class="fpc-btn-group">
        <button class="fpc-btn dark" onclick="fpcLoadLog('preloader')" id="btn-log-preloader">Preloader-Log</button>
        <button class="fpc-btn dark" onclick="fpcLoadLog('rebuild')" id="btn-log-rebuild">Rebuild-Log</button>
        <button class="fpc-btn dark" onclick="fpcLoadLog('healthcheck')" id="btn-log-healthcheck">Health-Check-Log</button>
        <button class="fpc-btn teal" onclick="fpcAutoRefreshLog()" id="btn-log-auto">&#8635; Auto-Refresh: Aus</button>
    </div>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <span style="color:var(--fpc-text2); font-size:12px;" id="log-info">Waehlen Sie einen Log-Typ...</span>
        <select class="fpc-input" style="width:120px;" id="log-lines" onchange="fpcReloadLog()">
            <option value="50">50 Zeilen</option>
            <option value="100" selected>100 Zeilen</option>
            <option value="200">200 Zeilen</option>
            <option value="500">500 Zeilen</option>
        </select>
    </div>
    <div class="fpc-log" id="log-content">(Noch kein Log geladen)</div>
</div>

<!-- TAB 5: MONITORING -->
<div class="fpc-tab-panel <?php echo $active_tab === 'monitoring' ? 'active' : ''; ?>" id="panel-monitoring">
    <div class="fpc-section-title">&#128200; Cache-Monitoring</div>
    <p style="color:var(--fpc-text2); font-size:13px; margin-bottom:16px;">
        Testet zufaellige gecachte URLs auf Erreichbarkeit, FPC-Status und Redirect-Verhalten.
    </p>
    <div class="fpc-btn-group">
        <button class="fpc-btn teal" onclick="fpcRunMonitor(20)" id="btn-monitor">&#9654; Test starten (20 URLs)</button>
        <button class="fpc-btn blue" onclick="fpcRunMonitor(50)">&#9654; Grosser Test (50 URLs)</button>
    </div>
    <div class="fpc-charts" style="margin-top:20px;">
        <div class="fpc-chart-card">
            <div class="fpc-chart-title">HIT-Rate Verlauf</div>
            <canvas id="chart-monitor-hitrate" height="250"></canvas>
        </div>
        <div class="fpc-chart-card">
            <div class="fpc-chart-title">TTFB Verlauf (ms)</div>
            <canvas id="chart-monitor-ttfb" height="250"></canvas>
        </div>
    </div>
    <div class="fpc-section-title" style="margin-top:20px;">Letztes Testergebnis</div>
    <div id="monitor-results"></div>
    <div class="fpc-section-title" style="margin-top:20px;">Test-Historie</div>
    <div id="monitor-history"></div>
</div>

<!-- TAB 6: HEALTH-CHECK -->
<div class="fpc-tab-panel <?php echo $active_tab === 'healthcheck' ? 'active' : ''; ?>" id="panel-healthcheck">
    <div class="fpc-section-title">&#128154; Health-Check</div>
    <div class="fpc-btn-group">
        <button class="fpc-btn teal" onclick="fpcRunHealthcheck()">&#9654; Jetzt pruefen</button>
        <button class="fpc-btn dark" onclick="fpcLoadHealthcheck()">&#8635; Daten aktualisieren</button>
    </div>
    <div id="healthcheck-content">
        <p style="color:var(--fpc-text2);">Lade Health-Check Daten...</p>
    </div>
</div>

<!-- TAB 7: STATISTIK -->
<div class="fpc-tab-panel <?php echo $active_tab === 'statistik' ? 'active' : ''; ?>" id="panel-statistik">
    <div class="fpc-section-title">&#128202; Besucherstatistik</div>
    <div class="fpc-btn-group">
        <button class="fpc-btn dark" onclick="fpcLoadVisitorStats(7)">7 Tage</button>
        <button class="fpc-btn teal" onclick="fpcLoadVisitorStats(30)">30 Tage</button>
        <button class="fpc-btn dark" onclick="fpcLoadVisitorStats(90)">90 Tage</button>
    </div>
    <div id="visitor-stats-content">
        <p style="color:var(--fpc-text2);">Lade Besucherstatistik...</p>
    </div>
</div>

<!-- TAB 8: FEHLER-LOG -->
<div class="fpc-tab-panel <?php echo $active_tab === 'fehlerlog' ? 'active' : ''; ?>" id="panel-fehlerlog">
    <div class="fpc-section-title">&#9888; PHP Fehler-Log</div>
    <div class="fpc-btn-group">
        <button class="fpc-btn dark" onclick="fpcLoadErrorLog('')">Alle Fehler</button>
        <button class="fpc-btn teal" onclick="fpcLoadErrorLog('fpc')">Nur FPC-Fehler</button>
        <button class="fpc-btn orange" onclick="fpcLoadErrorLog('fatal')">Nur Fatal</button>
        <button class="fpc-btn red" onclick="fpcLoadErrorLog('error')">Nur Errors</button>
    </div>
    <div style="display:flex; gap:8px; margin-bottom:12px;">
        <input type="text" class="fpc-input" id="error-filter" placeholder="Eigener Filter..." style="flex:1;">
        <button class="fpc-btn dark" onclick="fpcLoadErrorLog(document.getElementById('error-filter').value)">Filtern</button>
        <select class="fpc-input" style="width:120px;" id="error-lines">
            <option value="50">50 Zeilen</option>
            <option value="100" selected>100 Zeilen</option>
            <option value="200">200 Zeilen</option>
            <option value="500">500 Zeilen</option>
        </select>
    </div>
    <div id="error-log-content" class="fpc-log">(Noch nicht geladen)</div>
</div>

</div><!-- /fpc-content -->
</div><!-- /fpc-wrap -->

<!-- TOAST -->
<div class="fpc-toast" id="fpc-toast"></div>

<!-- ============================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    var BASE = 'fpc_dashboard.php';
    var currentLogType = null;
    var autoRefreshInterval = null;
    var currentUrlPage = 1;
    var searchTimeout = null;

    // Charts
    var chartCategories = null;
    var chartLastStats = null;
    var chartMonitorHitrate = null;
    var chartMonitorTtfb = null;
    var chartVisitorDaily = null;
    var chartVisitorHours = null;
    var chartVisitorDevices = null;
    var chartVisitorReferrers = null;
    var chartHealthTrend = null;

    // ============================================================
    // TAB-NAVIGATION
    // ============================================================
    document.querySelectorAll('.fpc-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = this.dataset.tab;
            document.querySelectorAll('.fpc-tab').forEach(function(t) { t.classList.remove('active'); });
            document.querySelectorAll('.fpc-tab-panel').forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            var panel = document.getElementById('panel-' + target);
            if (panel) panel.classList.add('active');
            // URL aktualisieren ohne Reload
            history.replaceState(null, '', BASE + '?tab=' + target);
            // Tab-spezifische Daten laden
            if (target === 'urls') fpcLoadUrls(1);
            if (target === 'monitoring') fpcLoadMonitorData();
            if (target === 'steuerung') fpcLoadCustomUrls();
            if (target === 'healthcheck') fpcLoadHealthcheck();
            if (target === 'statistik') fpcLoadVisitorStats(30);
            if (target === 'fehlerlog') fpcLoadErrorLog('');
        });
    });

    // ============================================================
    // UHR
    // ============================================================
    function updateClock() {
        var now = new Date();
        document.getElementById('fpc-clock').textContent = now.toLocaleTimeString('de-DE');
    }
    updateClock();
    setInterval(updateClock, 1000);

    // ============================================================
    // TOAST
    // ============================================================
    function fpcToast(msg, isError) {
        var el = document.getElementById('fpc-toast');
        el.textContent = msg;
        el.className = 'fpc-toast show' + (isError ? ' error' : '');
        setTimeout(function() { el.className = 'fpc-toast'; }, 4000);
    }

    // ============================================================
    // AJAX HELPER
    // ============================================================
    async function fpcGet(params) {
        var url = BASE + '?' + new URLSearchParams(params).toString();
        var res = await fetch(url);
        return res.json();
    }

    async function fpcPost(params, body) {
        var url = BASE + '?' + new URLSearchParams(params).toString();
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(body).toString()
        });
        return res.json();
    }

    // ============================================================
    // DASHBOARD STATUS
    // ============================================================
    async function fpcLoadStatus() {
        try {
            var d = await fpcGet({ ajax: 'status' });
            document.getElementById('kpi-files').textContent = d.files.toLocaleString('de-DE');
            document.getElementById('kpi-size').textContent = d.size_formatted;
            document.getElementById('kpi-oldest').textContent = d.oldest || '--';
            document.getElementById('kpi-newest').textContent = d.newest || '--';

            if (d.rebuild_running) {
                document.getElementById('kpi-rebuild').innerHTML = '<span style="color:var(--fpc-green)">&#9654; Laeuft</span>';
                document.getElementById('kpi-rebuild-sub').textContent = 'Seit: ' + (d.rebuild_started || '?');
                document.getElementById('btn-stop').style.display = '';
                document.getElementById('btn-rebuild').style.display = 'none';
            } else {
                document.getElementById('kpi-rebuild').innerHTML = '<span style="color:var(--fpc-text2)">&#9632; Gestoppt</span>';
                document.getElementById('kpi-rebuild-sub').textContent = '';
                document.getElementById('btn-stop').style.display = 'none';
                document.getElementById('btn-rebuild').style.display = '';
            }

            if (d.last_run) {
                document.getElementById('kpi-lastrun').textContent = d.last_run;
                if (d.last_stats) {
                    document.getElementById('kpi-lastrun-sub').textContent =
                        d.last_stats.cached + ' gecacht, ' + d.last_stats.skipped + ' uebersprungen, ' + d.last_stats.errors + ' Fehler';
                }
            }

            // Health-Score
            if (d.health_score !== null) {
                document.getElementById('kpi-health').innerHTML = '<span class="fpc-health-score grade-' + d.health_grade.toLowerCase() + '">' + d.health_grade + '</span>';
                document.getElementById('kpi-health-sub').textContent = 'Score: ' + d.health_score + '/100';
            }

            // Charts
            if (d.categories && Object.keys(d.categories).length > 0) renderCategoryChart(d.categories);
            if (d.last_stats) renderLastStatsChart(d.last_stats);
        } catch (e) {
            console.error('Status-Fehler:', e);
        }
    }

    function renderCategoryChart(cats) {
        if (typeof Chart === 'undefined') { console.warn('Chart.js nicht geladen'); return; }
        var ctx = document.getElementById('chart-categories').getContext('2d');
        var labels = Object.keys(cats);
        var values = Object.values(cats);
        var colors = generateColors(labels.length);
        if (chartCategories) chartCategories.destroy();
        chartCategories = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
            options: {
                responsive: true,
                plugins: { legend: { position: 'right', labels: { color: '#8899aa', font: { size: 11 } } } }
            }
        });
    }

    function renderLastStatsChart(stats) {
        if (typeof Chart === 'undefined') return;
        var ctx = document.getElementById('chart-laststats').getContext('2d');
        if (chartLastStats) chartLastStats.destroy();
        chartLastStats = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Gecacht', 'Uebersprungen', 'Fehler'],
                datasets: [{ data: [stats.cached, stats.skipped, stats.errors], backgroundColor: ['#00d4aa', '#ffa502', '#ff4757'], borderWidth: 0 }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { color: '#8899aa' }, grid: { color: '#2a3a4a' } }, x: { ticks: { color: '#8899aa' }, grid: { display: false } } }
            }
        });
    }

    function generateColors(count) {
        var base = ['#00d4aa','#00a8ff','#2ed573','#ffa502','#ff4757','#a55eea','#45aaf2','#fed330','#26de81','#fc5c65','#778ca3','#4b7bec','#eb3b5a','#20bf6b','#f7b731'];
        while (base.length < count) base = base.concat(base);
        return base.slice(0, count);
    }

    // ============================================================
    // STEUERUNG
    // ============================================================
    window.fpcRebuild = async function() {
        if (!confirm('Cache neu aufbauen? Dies kann einige Minuten dauern.')) return;
        var r = await fpcGet({ ajax: 'rebuild' });
        fpcToast(r.msg, !r.ok);
        fpcLoadStatus();
    };

    window.fpcStopRebuild = async function() {
        var r = await fpcGet({ ajax: 'stop' });
        fpcToast(r.msg, !r.ok);
        fpcLoadStatus();
    };

    window.fpcFlush = async function() {
        if (!confirm('ACHTUNG: Gesamten Cache leeren? Alle gecachten Seiten werden geloescht!')) return;
        var r = await fpcGet({ ajax: 'flush' });
        fpcToast(r.msg, !r.ok);
        fpcLoadStatus();
    };

    window.fpcCacheSingle = async function() {
        var url = document.getElementById('single-url').value.trim();
        if (!url) return;
        var r = await fpcPost({ ajax: 'cache_url' }, { url: url });
        document.getElementById('single-url-result').innerHTML = '<p style="color:' + (r.ok ? 'var(--fpc-green)' : 'var(--fpc-red)') + ';margin-top:8px;">' + r.msg + '</p>';
        fpcToast(r.msg, !r.ok);
    };

    window.fpcAddCustomUrl = async function() {
        var url = document.getElementById('custom-url-input').value.trim();
        if (!url) return;
        var r = await fpcPost({ ajax: 'add_custom_url' }, { url: url });
        fpcToast(r.msg, !r.ok);
        document.getElementById('custom-url-input').value = '';
        fpcLoadCustomUrls();
    };

    window.fpcRemoveCustomUrl = async function(url) {
        var r = await fpcPost({ ajax: 'remove_custom_url' }, { url: url });
        fpcToast(r.msg, !r.ok);
        fpcLoadCustomUrls();
    };

    window.fpcCacheCustomUrl = async function(url) {
        var r = await fpcPost({ ajax: 'cache_url' }, { url: url });
        fpcToast(r.msg, !r.ok);
    };

    async function fpcLoadCustomUrls() {
        var r = await fpcGet({ ajax: 'custom_urls' });
        var el = document.getElementById('custom-urls-list');
        if (!r || r.length === 0) { el.innerHTML = '<p style="color:var(--fpc-text2);">Keine eigenen URLs definiert.</p>'; return; }
        var html = '<table class="fpc-table"><thead><tr><th>URL</th><th style="width:200px;">Aktionen</th></tr></thead><tbody>';
        r.forEach(function(url) {
            html += '<tr><td><code>' + url + '</code></td><td>';
            html += '<button class="fpc-btn teal" style="padding:4px 10px;font-size:11px;" onclick="fpcCacheCustomUrl(\'' + url + '\')">Cachen</button> ';
            html += '<button class="fpc-btn red" style="padding:4px 10px;font-size:11px;" onclick="fpcRemoveCustomUrl(\'' + url + '\')">Entfernen</button>';
            html += '</td></tr>';
        });
        html += '</tbody></table>';
        el.innerHTML = html;
    }

    // htaccess Validator
    window.fpcValidateHtaccess = async function() {
        var r = await fpcGet({ ajax: 'validate_htaccess' });
        var el = document.getElementById('htaccess-result');
        var html = '<div style="margin-top:12px;">';
        html += '<p style="font-size:16px;font-weight:700;color:' + (r.ok ? 'var(--fpc-green)' : 'var(--fpc-orange)') + ';">';
        html += (r.ok ? '&#10004; ' : '&#9888; ') + r.msg + ' (Score: ' + r.score + '%)</p>';
        html += '<table class="fpc-table" style="margin-top:12px;"><thead><tr><th>Check</th><th>Status</th></tr></thead><tbody>';
        r.checks.forEach(function(c) {
            html += '<tr><td>' + c.name + '</td><td>' + (c.ok ? '<span class="sev-ok">OK</span>' : '<span class="sev-error">FEHLT</span>') + '</td></tr>';
        });
        html += '</tbody></table></div>';
        el.innerHTML = html;
    };

    // ============================================================
    // URLs
    // ============================================================
    window.fpcLoadUrls = async function(page) {
        currentUrlPage = page;
        var search = document.getElementById('url-search').value;
        var r = await fpcGet({ ajax: 'urls', search: search, page: page });
        document.getElementById('url-count').textContent = r.total + ' URLs gefunden (Seite ' + r.page + '/' + r.pages + ')';

        var html = '<table class="fpc-table"><thead><tr><th>URL</th><th>Groesse</th><th>Gecacht</th><th>Alter</th><th>Aktion</th></tr></thead><tbody>';
        r.urls.forEach(function(u) {
            var ageColor = u.age_h > 48 ? 'var(--fpc-red)' : (u.age_h > 24 ? 'var(--fpc-orange)' : 'var(--fpc-text2)');
            html += '<tr>';
            html += '<td><a href="https://mr-hanf.de' + u.path + '" target="_blank" style="color:var(--fpc-teal);text-decoration:none;">' + u.path + '</a></td>';
            html += '<td>' + (u.size / 1024).toFixed(1) + ' KB</td>';
            html += '<td>' + u.cached + '</td>';
            html += '<td style="color:' + ageColor + ';">' + u.age_h + 'h</td>';
            html += '<td><button class="fpc-btn red" style="padding:3px 8px;font-size:11px;" onclick="fpcRemoveUrl(\'' + u.path + '\')">&#128465;</button></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        document.getElementById('urls-table').innerHTML = html;

        // Pagination
        var phtml = '';
        for (var i = 1; i <= r.pages; i++) {
            phtml += '<button class="' + (i === r.page ? 'active' : '') + '" onclick="fpcLoadUrls(' + i + ')">' + i + '</button>';
        }
        document.getElementById('urls-pagination').innerHTML = phtml;
    };

    window.fpcRemoveUrl = async function(path) {
        var r = await fpcPost({ ajax: 'remove_url' }, { path: path });
        fpcToast(r.msg, !r.ok);
        fpcLoadUrls(currentUrlPage);
    };

    window.fpcSearchUrls = function() {
        if (searchTimeout) clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { fpcLoadUrls(1); }, 300);
    };

    window.fpcExportUrls = function() {
        window.open(BASE + '?ajax=export_urls', '_blank');
    };

    // ============================================================
    // LOGS
    // ============================================================
    window.fpcLoadLog = async function(type) {
        currentLogType = type;
        var lines = document.getElementById('log-lines').value;
        var r = await fpcGet({ ajax: 'log', type: type, lines: lines });
        document.getElementById('log-content').textContent = r.content;
        document.getElementById('log-info').textContent = 'Datei: ' + r.file + ' | Typ: ' + type;
        document.getElementById('log-content').scrollTop = document.getElementById('log-content').scrollHeight;
        // Button-Highlighting
        document.querySelectorAll('[id^="btn-log-"]').forEach(function(b) { b.style.background = '#2a3a4a'; });
        var btn = document.getElementById('btn-log-' + type);
        if (btn) btn.style.background = 'var(--fpc-teal)';
    };

    window.fpcReloadLog = function() {
        if (currentLogType) fpcLoadLog(currentLogType);
    };

    window.fpcAutoRefreshLog = function() {
        var btn = document.getElementById('btn-log-auto');
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
            btn.innerHTML = '&#8635; Auto-Refresh: Aus';
            btn.style.background = 'var(--fpc-teal)';
        } else {
            autoRefreshInterval = setInterval(function() { if (currentLogType) fpcLoadLog(currentLogType); }, 3000);
            btn.innerHTML = '&#8635; Auto-Refresh: An (3s)';
            btn.style.background = 'var(--fpc-green)';
        }
    };

    // ============================================================
    // MONITORING
    // ============================================================
    window.fpcRunMonitor = async function(count) {
        document.getElementById('btn-monitor').textContent = 'Teste...';
        document.getElementById('btn-monitor').disabled = true;
        try {
            var r = await fpcPost({ ajax: 'run_monitor' }, { count: count });
            fpcToast(r.msg, !r.ok);
            if (r.ok && r.data) renderMonitorResults(r.data);
            fpcLoadMonitorData();
        } finally {
            document.getElementById('btn-monitor').textContent = '▶ Test starten (20 URLs)';
            document.getElementById('btn-monitor').disabled = false;
        }
    };

    async function fpcLoadMonitorData() {
        var data = await fpcGet({ ajax: 'monitor_data' });
        if (data.latest) renderMonitorResults(data.latest);
        if (data.runs && data.runs.length > 0) renderMonitorCharts(data);
        renderMonitorHistory(data);
    }

    function renderMonitorResults(run) {
        if (!run || !run.results) return;
        var html = '<p style="color:var(--fpc-text2);margin-bottom:8px;">' + run.timestamp + ' | ' + run.tested + ' URLs | HIT-Rate: <b style="color:var(--fpc-teal);">' + run.hit_rate + '%</b> | Ø TTFB: <b>' + run.avg_ttfb + 'ms</b></p>';
        html += '<table class="fpc-table"><thead><tr><th>URL</th><th>HTTP</th><th>FPC</th><th>TTFB</th></tr></thead><tbody>';
        run.results.forEach(function(r) {
            var fpcColor = r.fpc === 'HIT' ? 'var(--fpc-green)' : 'var(--fpc-red)';
            var httpColor = r.http === 200 ? 'var(--fpc-green)' : (r.http >= 300 && r.http < 400 ? 'var(--fpc-orange)' : 'var(--fpc-red)');
            html += '<tr><td><a href="https://mr-hanf.de' + r.url + '" target="_blank" style="color:var(--fpc-teal);text-decoration:none;">' + r.url + '</a></td>';
            html += '<td style="color:' + httpColor + ';">' + r.http + '</td>';
            html += '<td style="color:' + fpcColor + ';font-weight:700;">' + r.fpc + '</td>';
            html += '<td>' + r.ttfb + 'ms</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('monitor-results').innerHTML = html;
    }

    function renderMonitorCharts(data) {
        if (typeof Chart === 'undefined' || !data.runs || data.runs.length === 0) return;
        var labels = data.runs.map(function(r) { return r.timestamp.substring(5, 16); });
        var hitrates = data.runs.map(function(r) { return r.hit_rate; });
        var ttfbs = data.runs.map(function(r) { return r.avg_ttfb; });

        var ctxHit = document.getElementById('chart-monitor-hitrate').getContext('2d');
        if (chartMonitorHitrate) chartMonitorHitrate.destroy();
        chartMonitorHitrate = new Chart(ctxHit, {
            type: 'line',
            data: { labels: labels, datasets: [{ label: 'HIT-Rate %', data: hitrates, borderColor: '#00d4aa', backgroundColor: 'rgba(0,212,170,0.1)', fill: true, tension: 0.3 }] },
            options: { responsive: true, plugins: { legend: { labels: { color: '#8899aa' } } }, scales: { y: { min: 0, max: 100, ticks: { color: '#8899aa' }, grid: { color: '#2a3a4a' } }, x: { ticks: { color: '#8899aa', maxRotation: 45 }, grid: { display: false } } } }
        });

        var ctxTtfb = document.getElementById('chart-monitor-ttfb').getContext('2d');
        if (chartMonitorTtfb) chartMonitorTtfb.destroy();
        chartMonitorTtfb = new Chart(ctxTtfb, {
            type: 'line',
            data: { labels: labels, datasets: [{ label: 'Ø TTFB (ms)', data: ttfbs, borderColor: '#00a8ff', backgroundColor: 'rgba(0,168,255,0.1)', fill: true, tension: 0.3 }] },
            options: { responsive: true, plugins: { legend: { labels: { color: '#8899aa' } } }, scales: { y: { ticks: { color: '#8899aa' }, grid: { color: '#2a3a4a' } }, x: { ticks: { color: '#8899aa', maxRotation: 45 }, grid: { display: false } } } }
        });
    }

    function renderMonitorHistory(data) {
        if (!data.runs || data.runs.length === 0) {
            document.getElementById('monitor-history').innerHTML = '<p style="color:var(--fpc-text2);">Noch keine Tests durchgefuehrt.</p>';
            return;
        }
        var html = '<table class="fpc-table"><thead><tr><th>Zeitpunkt</th><th>URLs</th><th>HITs</th><th>HIT-Rate</th><th>Ø TTFB</th><th>Fehler</th></tr></thead><tbody>';
        data.runs.slice().reverse().forEach(function(r) {
            html += '<tr><td>' + r.timestamp + '</td><td>' + r.tested + '</td><td>' + r.hits + '</td>';
            html += '<td style="color:' + (r.hit_rate >= 90 ? 'var(--fpc-green)' : (r.hit_rate >= 70 ? 'var(--fpc-orange)' : 'var(--fpc-red)')) + ';font-weight:700;">' + r.hit_rate + '%</td>';
            html += '<td>' + r.avg_ttfb + 'ms</td><td style="color:' + (r.errors > 0 ? 'var(--fpc-red)' : 'var(--fpc-text2)') + ';">' + r.errors + '</td></tr>';
        });
        html += '</tbody></table>';
        document.getElementById('monitor-history').innerHTML = html;
    }

    // ============================================================
    // HEALTH-CHECK (TAB 6)
    // ============================================================
    window.fpcRunHealthcheck = async function() {
        var r = await fpcGet({ ajax: 'run_healthcheck' });
        fpcToast(r.msg, !r.ok);
        setTimeout(fpcLoadHealthcheck, 5000);
    };

    window.fpcLoadHealthcheck = async function() {
        var data = await fpcGet({ ajax: 'healthcheck' });
        var el = document.getElementById('healthcheck-content');

        if (!data.available) {
            el.innerHTML = '<p style="color:var(--fpc-orange);">' + data.msg + '</p>';
            return;
        }

        var s = data.latest ? data.latest.summary : null;
        if (!s) { el.innerHTML = '<p style="color:var(--fpc-text2);">Keine Daten verfuegbar.</p>'; return; }

        var gradeClass = 'grade-' + s.health_grade.toLowerCase();
        var html = '';

        // Health-Score Header
        html += '<div style="display:flex;align-items:center;gap:24px;margin-bottom:24px;">';
        html += '<div class="fpc-health-score ' + gradeClass + '">' + s.health_grade + '</div>';
        html += '<div><div style="font-size:24px;font-weight:700;">Score: ' + s.health_score + '/100</div>';
        html += '<div style="color:var(--fpc-text2);">Letzter Check: ' + s.timestamp + ' | Dauer: ' + s.duration_sec + 's | ' + s.tested + ' URLs getestet</div></div>';
        html += '</div>';

        // KPIs
        html += '<div class="fpc-kpis">';
        html += '<div class="fpc-kpi green"><div class="fpc-kpi-label">HIT-Rate</div><div class="fpc-kpi-value">' + s.hit_rate + '%</div></div>';
        html += '<div class="fpc-kpi blue"><div class="fpc-kpi-label">Ø TTFB</div><div class="fpc-kpi-value">' + s.avg_ttfb + 'ms</div><div class="fpc-kpi-sub">Min: ' + s.ttfb_min + 'ms / Max: ' + s.ttfb_max + 'ms</div></div>';
        html += '<div class="fpc-kpi orange"><div class="fpc-kpi-label">Fehler</div><div class="fpc-kpi-value">' + s.errors + '</div><div class="fpc-kpi-sub">Rate: ' + s.error_rate + '%</div></div>';
        html += '<div class="fpc-kpi purple"><div class="fpc-kpi-label">Redirects</div><div class="fpc-kpi-value">' + s.redirects + '</div></div>';
        html += '<div class="fpc-kpi teal"><div class="fpc-kpi-label">Veraltet (>24h)</div><div class="fpc-kpi-value">' + s.stale_count + '</div></div>';
        html += '<div class="fpc-kpi ' + (s.ssl && s.ssl.valid ? 'green' : 'red') + '"><div class="fpc-kpi-label">SSL</div><div class="fpc-kpi-value">' + (s.ssl && s.ssl.valid ? '&#10004;' : '&#10008;') + '</div><div class="fpc-kpi-sub">' + (s.ssl ? 'Ablauf: ' + s.ssl.expires + ' (' + s.ssl.days_left + ' Tage)' : '') + '</div></div>';
        html += '</div>';

        // Fehler-Liste
        if (s.errors_list && s.errors_list.length > 0) {
            html += '<div class="fpc-section-title" style="margin-top:20px;color:var(--fpc-red);">Fehler (' + s.errors_list.length + ')</div>';
            html += '<table class="fpc-table"><thead><tr><th>URL</th><th>Severity</th><th>HTTP</th><th>Probleme</th></tr></thead><tbody>';
            s.errors_list.forEach(function(e) {
                html += '<tr><td><a href="https://mr-hanf.de' + e.url + '" target="_blank" style="color:var(--fpc-teal);">' + e.url + '</a></td>';
                html += '<td><span class="sev-' + e.severity + '">' + e.severity.toUpperCase() + '</span></td>';
                html += '<td>' + e.http + '</td><td>' + e.issues.join(', ') + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        // Langsame URLs
        if (s.slow_urls && s.slow_urls.length > 0) {
            html += '<div class="fpc-section-title" style="margin-top:20px;color:var(--fpc-orange);">Langsame URLs (' + s.slow_urls.length + ')</div>';
            html += '<table class="fpc-table"><thead><tr><th>URL</th><th>TTFB</th></tr></thead><tbody>';
            s.slow_urls.forEach(function(u) {
                html += '<tr><td><a href="https://mr-hanf.de' + u.url + '" target="_blank" style="color:var(--fpc-teal);">' + u.url + '</a></td>';
                html += '<td style="color:var(--fpc-orange);font-weight:700;">' + u.ttfb + 'ms</td></tr>';
            });
            html += '</tbody></table>';
        }

        // Redirect-URLs
        if (s.redirect_urls && s.redirect_urls.length > 0) {
            html += '<div class="fpc-section-title" style="margin-top:20px;">Redirects (' + s.redirect_urls.length + ')</div>';
            html += '<table class="fpc-table"><thead><tr><th>URL</th><th>Code</th><th>Ziel</th></tr></thead><tbody>';
            s.redirect_urls.forEach(function(r) {
                html += '<tr><td>' + r.url + '</td><td>' + r.code + '</td><td style="color:var(--fpc-blue);">' + r.target + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        // Trend-Chart
        if (data.runs && data.runs.length > 1) {
            html += '<div class="fpc-section-title" style="margin-top:20px;">Health-Score Trend</div>';
            html += '<div class="fpc-chart-card"><canvas id="chart-health-trend" height="200"></canvas></div>';
        }

        el.innerHTML = html;

        // Trend-Chart rendern
        if (data.runs && data.runs.length > 1 && typeof Chart !== 'undefined') {
            var trendCtx = document.getElementById('chart-health-trend');
            if (trendCtx) {
                var tLabels = data.runs.map(function(r) { return r.timestamp.substring(0, 10); });
                var tScores = data.runs.map(function(r) { return r.health_score; });
                var tHitRates = data.runs.map(function(r) { return r.hit_rate; });
                if (chartHealthTrend) chartHealthTrend.destroy();
                chartHealthTrend = new Chart(trendCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: tLabels,
                        datasets: [
                            { label: 'Health-Score', data: tScores, borderColor: '#00d4aa', backgroundColor: 'rgba(0,212,170,0.1)', fill: true, tension: 0.3 },
                            { label: 'HIT-Rate %', data: tHitRates, borderColor: '#00a8ff', backgroundColor: 'rgba(0,168,255,0.1)', fill: true, tension: 0.3 }
                        ]
                    },
                    options: { responsive: true, plugins: { legend: { labels: { color: '#8899aa' } } }, scales: { y: { min: 0, max: 100, ticks: { color: '#8899aa' }, grid: { color: '#2a3a4a' } }, x: { ticks: { color: '#8899aa' }, grid: { display: false } } } }
                });
            }
        }
    };

    // ============================================================
    // STATISTIK (TAB 7)
    // ============================================================
    window.fpcLoadVisitorStats = async function(days) {
        var data = await fpcGet({ ajax: 'visitor_stats', days: days });
        var el = document.getElementById('visitor-stats-content');

        if (!data.available) {
            el.innerHTML = '<p style="color:var(--fpc-orange);">' + data.msg + '</p>';
            return;
        }

        var t = data.totals;
        var html = '';

        // KPIs
        html += '<div class="fpc-kpis">';
        html += '<div class="fpc-kpi teal"><div class="fpc-kpi-label">Seitenaufrufe</div><div class="fpc-kpi-value">' + t.pageviews.toLocaleString('de-DE') + '</div><div class="fpc-kpi-sub">Letzte ' + days + ' Tage</div></div>';
        html += '<div class="fpc-kpi blue"><div class="fpc-kpi-label">Besucher</div><div class="fpc-kpi-value">' + t.visitors.toLocaleString('de-DE') + '</div></div>';
        html += '<div class="fpc-kpi ' + (t.bounce_rate > 70 ? 'red' : (t.bounce_rate > 50 ? 'orange' : 'green')) + '"><div class="fpc-kpi-label">Absprungrate</div><div class="fpc-kpi-value">' + t.bounce_rate + '%</div></div>';
        html += '<div class="fpc-kpi purple"><div class="fpc-kpi-label">Ø Verweildauer</div><div class="fpc-kpi-value">' + formatDuration(t.avg_duration) + '</div></div>';
        html += '<div class="fpc-kpi green"><div class="fpc-kpi-label">Ø Seiten/Besuch</div><div class="fpc-kpi-value">' + t.avg_pages + '</div></div>';
        html += '</div>';

        // Charts
        html += '<div class="fpc-charts">';
        html += '<div class="fpc-chart-card"><div class="fpc-chart-title">Besucher & Seitenaufrufe</div><canvas id="chart-visitor-daily" height="250"></canvas></div>';
        html += '<div class="fpc-chart-card"><div class="fpc-chart-title">Tageszeit-Verteilung</div><canvas id="chart-visitor-hours" height="250"></canvas></div>';
        html += '</div>';
        html += '<div class="fpc-charts">';
        html += '<div class="fpc-chart-card"><div class="fpc-chart-title">Geraetetypen</div><canvas id="chart-visitor-devices" height="250"></canvas></div>';
        html += '<div class="fpc-chart-card"><div class="fpc-chart-title">Traffic-Quellen</div><canvas id="chart-visitor-referrers" height="250"></canvas></div>';
        html += '</div>';

        // Top-Seiten
        if (data.top_pages && Object.keys(data.top_pages).length > 0) {
            html += '<div class="fpc-section-title" style="margin-top:20px;">Top-Seiten</div>';
            html += '<table class="fpc-table"><thead><tr><th>Seite</th><th>Aufrufe</th></tr></thead><tbody>';
            var pages = Object.entries(data.top_pages);
            pages.forEach(function(p) {
                html += '<tr><td><a href="https://mr-hanf.de' + p[0] + '" target="_blank" style="color:var(--fpc-teal);text-decoration:none;">' + p[0] + '</a></td>';
                html += '<td>' + p[1].toLocaleString('de-DE') + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        el.innerHTML = html;

        // Charts rendern
        if (typeof Chart === 'undefined') return;

        // Daily Chart
        if (data.daily && data.daily.length > 0) {
            var dLabels = data.daily.map(function(d) { return d.date.substring(5); });
            var dPV = data.daily.map(function(d) { return d.pageviews; });
            var dVis = data.daily.map(function(d) { return d.visitors; });
            var dBounce = data.daily.map(function(d) { return d.bounce_rate; });

            var ctxDaily = document.getElementById('chart-visitor-daily');
            if (ctxDaily) {
                if (chartVisitorDaily) chartVisitorDaily.destroy();
                chartVisitorDaily = new Chart(ctxDaily.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: dLabels,
                        datasets: [
                            { label: 'Seitenaufrufe', data: dPV, borderColor: '#00d4aa', backgroundColor: 'rgba(0,212,170,0.1)', fill: true, tension: 0.3, yAxisID: 'y' },
                            { label: 'Besucher', data: dVis, borderColor: '#00a8ff', backgroundColor: 'rgba(0,168,255,0.1)', fill: true, tension: 0.3, yAxisID: 'y' },
                            { label: 'Absprungrate %', data: dBounce, borderColor: '#ff4757', borderDash: [5,5], fill: false, tension: 0.3, yAxisID: 'y1' }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { labels: { color: '#8899aa' } } },
                        scales: {
                            y: { position: 'left', ticks: { color: '#8899aa' }, grid: { color: '#2a3a4a' } },
                            y1: { position: 'right', min: 0, max: 100, ticks: { color: '#ff4757' }, grid: { display: false } },
                            x: { ticks: { color: '#8899aa', maxRotation: 45 }, grid: { display: false } }
                        }
                    }
                });
            }
        }

        // Hours Chart
        if (data.hours) {
            var hLabels = []; for (var h = 0; h < 24; h++) hLabels.push(h + ':00');
            var ctxHours = document.getElementById('chart-visitor-hours');
            if (ctxHours) {
                if (chartVisitorHours) chartVisitorHours.destroy();
                chartVisitorHours = new Chart(ctxHours.getContext('2d'), {
                    type: 'bar',
                    data: { labels: hLabels, datasets: [{ label: 'Aufrufe', data: data.hours, backgroundColor: 'rgba(0,212,170,0.6)', borderWidth: 0 }] },
                    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { ticks: { color: '#8899aa' }, grid: { color: '#2a3a4a' } }, x: { ticks: { color: '#8899aa' }, grid: { display: false } } } }
                });
            }
        }

        // Devices Chart
        if (data.devices) {
            var ctxDev = document.getElementById('chart-visitor-devices');
            if (ctxDev) {
                if (chartVisitorDevices) chartVisitorDevices.destroy();
                chartVisitorDevices = new Chart(ctxDev.getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: ['Desktop', 'Mobile', 'Tablet'], datasets: [{ data: [data.devices.desktop || 0, data.devices.mobile || 0, data.devices.tablet || 0], backgroundColor: ['#00a8ff', '#00d4aa', '#ffa502'], borderWidth: 0 }] },
                    options: { responsive: true, plugins: { legend: { position: 'right', labels: { color: '#8899aa' } } } }
                });
            }
        }

        // Referrers Chart
        if (data.top_referrers && Object.keys(data.top_referrers).length > 0) {
            var ctxRef = document.getElementById('chart-visitor-referrers');
            if (ctxRef) {
                if (chartVisitorReferrers) chartVisitorReferrers.destroy();
                chartVisitorReferrers = new Chart(ctxRef.getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: Object.keys(data.top_referrers), datasets: [{ data: Object.values(data.top_referrers), backgroundColor: generateColors(Object.keys(data.top_referrers).length), borderWidth: 0 }] },
                    options: { responsive: true, plugins: { legend: { position: 'right', labels: { color: '#8899aa' } } } }
                });
            }
        }
    };

    function formatDuration(seconds) {
        if (!seconds || seconds <= 0) return '0s';
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        if (m > 0) return m + 'm ' + s + 's';
        return s + 's';
    }

    // ============================================================
    // FEHLER-LOG (TAB 8)
    // ============================================================
    window.fpcLoadErrorLog = async function(filter) {
        var lines = document.getElementById('error-lines').value;
        var data = await fpcGet({ ajax: 'error_log', lines: lines, filter: filter });
        var el = document.getElementById('error-log-content');

        if (!data.entries || data.entries.length === 0) {
            el.innerHTML = data.content || '(Keine Eintraege gefunden)';
            return;
        }

        var html = '';
        data.entries.forEach(function(e) {
            var color = 'var(--fpc-text2)';
            if (e.severity === 'critical') color = 'var(--fpc-red)';
            else if (e.severity === 'error') color = '#e84118';
            else if (e.severity === 'warning') color = 'var(--fpc-orange)';
            else if (e.severity === 'notice') color = 'var(--fpc-yellow)';

            var prefix = e.fpc_related ? '<span style="color:var(--fpc-teal);font-weight:700;">[FPC] </span>' : '';
            html += '<div style="color:' + color + ';border-bottom:1px solid #1a2736;padding:2px 0;">' + prefix + escapeHtml(e.line) + '</div>';
        });

        el.innerHTML = html;
        el.scrollTop = el.scrollHeight;
    };

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // ============================================================
    // INIT
    // ============================================================
    try {
        fpcLoadStatus();
        setInterval(fpcLoadStatus, 30000);

        var activeTab = '<?php echo $active_tab; ?>';
        if (activeTab === 'urls') fpcLoadUrls(1);
        if (activeTab === 'monitoring') fpcLoadMonitorData();
        if (activeTab === 'steuerung') fpcLoadCustomUrls();
        if (activeTab === 'healthcheck') fpcLoadHealthcheck();
        if (activeTab === 'statistik') fpcLoadVisitorStats(30);
        if (activeTab === 'fehlerlog') fpcLoadErrorLog('');
    } catch (initErr) {
        console.error('FPC Dashboard Init-Fehler:', initErr);
    }

});
</script>
</body>
</html>
