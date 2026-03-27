<?php
/**
 * Mr. Hanf FPC Schaltzentrale v1.0
 *
 * Vollstaendiges Dashboard fuer das Full Page Cache System.
 * Wird als eigenstaendige Admin-Seite unter Statistiken eingebunden.
 *
 * Tabs:
 *   1. Dashboard    - KPI-Kacheln, Charts, Uebersicht
 *   2. Steuerung    - Cache leeren, neu aufbauen, einzelne URLs cachen
 *   3. URLs         - Alle gecachten URLs durchsuchen, filtern, verwalten
 *   4. Logs         - Preloader-Log, Rebuild-Log, Live-Ansicht
 *   5. Monitoring   - Automatische Tests, Redirect-Pruefung, Historie
 *
 * @version   1.0.0
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

// Aktiven Tab ermitteln
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';
$allowed_tabs = array('dashboard', 'steuerung', 'urls', 'logs', 'monitoring');
if (!in_array($active_tab, $allowed_tabs)) $active_tab = 'dashboard';

// ============================================================
// AJAX-ENDPUNKTE (JSON-Responses)
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_GET['ajax']) {

        // --- Status-Daten fuer Dashboard ---
        case 'status':
            $data = fpc_get_status($cache_dir, $pid_file, $log_file);
            echo json_encode($data);
            exit;

        // --- Log-Inhalt laden ---
        case 'log':
            $type = isset($_GET['type']) ? $_GET['type'] : 'preloader';
            $lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
            $file = ($type === 'rebuild') ? $rebuild_log : $log_file;
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
    }
    exit;
}

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function fpc_get_status($cache_dir, $pid_file, $log_file) {
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

                // Kategorie aus Pfad ableiten
                $rel = str_replace($cache_dir, '', $f->getPath());
                $parts = explode('/', trim($rel, '/'));
                $cat = !empty($parts[0]) ? $parts[0] : 'startseite';
                if (!isset($categories[$cat])) $categories[$cat] = 0;
                $categories[$cat]++;
            }
        }
    }

    // Letzter Preloader-Lauf
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

    // Rebuild-Status
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

    // Top-Kategorien sortieren
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
        'avg_file_size'    => $files > 0 ? fpc_format_bytes((int)($size / $files)) : '0',
        'timestamp'        => date('Y-m-d H:i:s'),
    );
}

function fpc_tail_file($file, $lines = 100) {
    if (!is_file($file)) return '(Datei nicht vorhanden)';
    $data = @file_get_contents($file);
    if ($data === false) return '(Datei nicht lesbar)';
    $arr = explode("\n", trim($data));
    return implode("\n", array_slice($arr, -$lines));
}

function fpc_format_bytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' Bytes';
}

function fpc_list_cached_urls($cache_dir, $search = '', $page = 1, $per_page = 50) {
    $all = array();
    if (!is_dir($cache_dir)) return array('urls' => array(), 'total' => 0, 'page' => 1, 'pages' => 0);

    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'html') continue;
        $rel = str_replace($cache_dir, '', $f->getPathname());
        $rel = str_replace('/index.html', '', $rel);
        if ($rel === '' || $rel === 'index.html') $rel = '/';
        else $rel = '/' . $rel . '/';

        if ($search !== '' && stripos($rel, $search) === false) continue;

        $all[] = array(
            'path'    => $rel,
            'size'    => $f->getSize(),
            'size_f'  => fpc_format_bytes($f->getSize()),
            'cached'  => date('Y-m-d H:i', $f->getMTime()),
            'age_h'   => round((time() - $f->getMTime()) / 3600, 1),
        );
    }

    // Nach Pfad sortieren
    usort($all, function($a, $b) { return strcmp($a['path'], $b['path']); });

    $total = count($all);
    $pages = max(1, ceil($total / $per_page));
    $offset = ($page - 1) * $per_page;
    $urls = array_slice($all, $offset, $per_page);

    return array('urls' => $urls, 'total' => $total, 'page' => $page, 'pages' => $pages);
}

function fpc_cache_single_url($url, $cache_dir, $base_dir) {
    // URL normalisieren
    if (strpos($url, 'http') !== 0) {
        $shop_url = defined('HTTPS_SERVER') ? rtrim(HTTPS_SERVER, '/') : (defined('HTTP_SERVER') ? rtrim(HTTP_SERVER, '/') : '');
        $url = $shop_url . '/' . ltrim($url, '/');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_COOKIE         => '',
    ));
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $ttfb = round(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000);
    curl_close($ch);

    if ($html === false || $code != 200) {
        return array('ok' => false, 'msg' => 'HTTP ' . $code . ' - Seite konnte nicht geladen werden');
    }

    if (strlen($html) < 1000 || stripos($html, '<body') === false) {
        return array('ok' => false, 'msg' => 'Ungueltige HTML-Antwort (' . strlen($html) . ' Bytes)');
    }

    // Session-IDs entfernen
    $html = preg_replace('/MODsid=[a-zA-Z0-9]+/', '', $html);
    $html .= "\n<!-- FPC-VALID -->\n";
    $html .= '<!-- FPC cached: ' . date('Y-m-d H:i:s') . ' | Dashboard -->' . "\n";

    // Pfad aus finaler URL ableiten
    $parsed = parse_url($final_url);
    $path = isset($parsed['path']) ? $parsed['path'] : '/';
    $clean = trim($path, '/');
    $cache_file = ($clean === '') ? $cache_dir . 'index.html' : $cache_dir . $clean . '/index.html';

    $dir = dirname($cache_file);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);

    $bytes = @file_put_contents($cache_file, $html);
    if ($bytes === false) {
        return array('ok' => false, 'msg' => 'Schreibfehler: ' . $cache_file);
    }

    return array('ok' => true, 'msg' => 'Gecacht: ' . $path . ' (' . fpc_format_bytes($bytes) . ', ' . $ttfb . 'ms TTFB)', 'path' => $path);
}

function fpc_remove_cached_url($cache_dir, $path) {
    $clean = trim($path, '/');
    $file = ($clean === '') ? $cache_dir . 'index.html' : $cache_dir . $clean . '/index.html';

    if (!is_file($file)) {
        return array('ok' => false, 'msg' => 'Datei nicht gefunden: ' . $path);
    }

    @unlink($file);
    // Leere Verzeichnisse aufraeumen
    $dir = dirname($file);
    while ($dir !== $cache_dir && $dir !== dirname($cache_dir) && is_dir($dir)) {
        $items = @scandir($dir);
        if ($items !== false && count($items) <= 2) { // nur . und ..
            @rmdir($dir);
            $dir = dirname($dir);
        } else {
            break;
        }
    }

    return array('ok' => true, 'msg' => 'Entfernt: ' . $path);
}

function fpc_flush_cache($dir) {
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $name = $item->getFilename();
        if ($item->isDir()) {
            @rmdir($item->getRealPath());
        } elseif ($name !== '.gitkeep' && $name !== 'preloader.log' && $name !== 'rebuild.pid'
                && $name !== 'rebuild_manual.log' && $name !== 'monitor.json' && $name !== 'custom_urls.txt') {
            @unlink($item->getRealPath());
        }
    }
}

function fpc_trigger_rebuild($base_dir, $cache_dir, $pid_file) {
    $preloader = $base_dir . 'fpc_preloader.php';
    if (!is_file($preloader)) {
        return array('ok' => false, 'msg' => 'fpc_preloader.php nicht gefunden');
    }

    // Pruefen ob bereits laeuft
    if (is_file($pid_file)) {
        $content = file_get_contents($pid_file);
        $lines = explode("\n", trim($content));
        $pid = (int)$lines[0];
        if ($pid > 0) {
            $running = function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir('/proc/' . $pid);
            if ($running) return array('ok' => false, 'msg' => 'Rebuild laeuft bereits (PID ' . $pid . ')');
        }
    }

    // PHP-Binary finden
    $php = 'php';
    foreach (array('/usr/local/bin/php', '/usr/bin/php', '/usr/bin/php8.1', '/usr/bin/php8.2', PHP_BINDIR . '/php') as $p) {
        if (is_executable($p)) { $php = $p; break; }
    }

    $rebuild_log = $cache_dir . 'rebuild_manual.log';
    $cmd = sprintf('cd %s && nohup %s %s >> %s 2>&1 & echo $!',
        escapeshellarg(rtrim($base_dir, '/')),
        escapeshellarg($php),
        escapeshellarg($preloader),
        escapeshellarg($rebuild_log)
    );
    $pid = trim(shell_exec($cmd));

    if (!empty($pid) && is_numeric($pid)) {
        file_put_contents($pid_file, $pid . "\n" . date('Y-m-d H:i:s'));
        return array('ok' => true, 'msg' => 'Rebuild gestartet (PID ' . $pid . ')');
    }
    return array('ok' => false, 'msg' => 'Konnte Preloader nicht starten');
}

function fpc_stop_rebuild($pid_file) {
    if (!is_file($pid_file)) return;
    $content = file_get_contents($pid_file);
    $lines = explode("\n", trim($content));
    $pid = (int)$lines[0];
    if ($pid > 0) {
        if (function_exists('posix_kill')) posix_kill($pid, 15);
        else @exec('kill ' . (int)$pid . ' 2>/dev/null');
    }
    @unlink($pid_file);
}

function fpc_get_monitor_data($monitor_log) {
    if (!is_file($monitor_log)) return array('runs' => array());
    $data = @json_decode(file_get_contents($monitor_log), true);
    return is_array($data) ? $data : array('runs' => array());
}

function fpc_run_monitor_test($cache_dir, $monitor_log, $base_dir, $count = 20) {
    // Zufaellige gecachte URLs waehlen
    $all_urls = array();
    if (is_dir($cache_dir)) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'html') {
                $rel = str_replace($cache_dir, '', $f->getPathname());
                $rel = str_replace('/index.html', '', $rel);
                if ($rel === '' || $rel === 'index.html') $rel = '/';
                else $rel = '/' . $rel . '/';
                $all_urls[] = $rel;
            }
        }
    }

    if (empty($all_urls)) return array('ok' => false, 'msg' => 'Keine gecachten URLs vorhanden');

    shuffle($all_urls);
    $test_urls = array_slice($all_urls, 0, min($count, count($all_urls)));

    $shop_url = defined('HTTPS_SERVER') ? rtrim(HTTPS_SERVER, '/') : (defined('HTTP_SERVER') ? rtrim(HTTP_SERVER, '/') : '');
    $results = array();
    $hits = 0; $misses = 0; $errors = 0; $redirects = 0;
    $ttfb_sum = 0; $ttfb_count = 0;

    foreach ($test_urls as $path) {
        $full_url = $shop_url . $path;
        $ch = curl_init($full_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'FPC-Monitor/1.0',
        ));
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ttfb = round(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers_raw = substr($response, 0, $header_size);
        $fpc_status = 'MISS';
        if (preg_match('/X-FPC-Cache:\s*(\S+)/i', $headers_raw, $hm)) {
            $fpc_status = strtoupper(trim($hm[1]));
        }

        $is_redirect = ($code >= 300 && $code < 400);
        if ($is_redirect) $redirects++;

        if ($fpc_status === 'HIT') $hits++;
        else $misses++;

        if ($code >= 400) $errors++;

        $ttfb_sum += $ttfb;
        $ttfb_count++;

        $results[] = array(
            'url'      => $path,
            'http'     => $code,
            'fpc'      => $fpc_status,
            'ttfb'     => $ttfb,
            'redirect' => $is_redirect,
        );
    }

    $total = count($results);
    $hit_rate = $total > 0 ? round(($hits / $total) * 100, 1) : 0;
    $avg_ttfb = $ttfb_count > 0 ? round($ttfb_sum / $ttfb_count) : 0;

    $run = array(
        'timestamp' => date('Y-m-d H:i:s'),
        'total'     => $total,
        'hits'      => $hits,
        'misses'    => $misses,
        'errors'    => $errors,
        'redirects' => $redirects,
        'hit_rate'  => $hit_rate,
        'avg_ttfb'  => $avg_ttfb,
        'results'   => $results,
    );

    // An Monitor-Log anhaengen
    $data = fpc_get_monitor_data($monitor_log);
    $data['runs'][] = $run;
    // Max 100 Runs behalten
    if (count($data['runs']) > 100) $data['runs'] = array_slice($data['runs'], -100);
    @file_put_contents($monitor_log, json_encode($data, JSON_PRETTY_PRINT));

    return array('ok' => true, 'run' => $run);
}

function fpc_add_custom_url($file, $url) {
    $url = trim($url);
    if (strpos($url, '/') !== 0 && strpos($url, 'http') !== 0) $url = '/' . $url;
    $existing = fpc_get_custom_urls($file);
    if (in_array($url, $existing['urls'])) {
        return array('ok' => false, 'msg' => 'URL existiert bereits');
    }
    @file_put_contents($file, $url . "\n", FILE_APPEND);
    return array('ok' => true, 'msg' => 'URL hinzugefuegt: ' . $url);
}

function fpc_get_custom_urls($file) {
    if (!is_file($file)) return array('urls' => array());
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array('urls' => array_values(array_unique(array_map('trim', $lines))));
}

function fpc_remove_custom_url($file, $url) {
    if (!is_file($file)) return array('ok' => false, 'msg' => 'Datei nicht vorhanden');
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lines = array_filter($lines, function($l) use ($url) { return trim($l) !== trim($url); });
    file_put_contents($file, implode("\n", $lines) . "\n");
    return array('ok' => true, 'msg' => 'URL entfernt: ' . $url);
}

// ============================================================
// HTML-AUSGABE
// ============================================================
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FPC Schaltzentrale</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
/* ============================================================
   FPC SCHALTZENTRALE - CSS
   ============================================================ */
:root {
    --fpc-bg: #0f1923;
    --fpc-surface: #1a2733;
    --fpc-surface2: #243442;
    --fpc-border: #2d4050;
    --fpc-accent: #00d4aa;
    --fpc-accent2: #00a8ff;
    --fpc-danger: #ff4757;
    --fpc-warn: #ffa502;
    --fpc-text: #e8edf2;
    --fpc-text2: #8899aa;
    --fpc-success: #2ed573;
    --fpc-radius: 8px;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

.fpc-wrap {
    font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
    background: var(--fpc-bg);
    color: var(--fpc-text);
    min-height: 100vh;
    padding: 0;
}

/* Header */
.fpc-header {
    background: linear-gradient(135deg, #1a2733 0%, #0d2137 100%);
    border-bottom: 1px solid var(--fpc-border);
    padding: 20px 30px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.fpc-header h1 {
    font-size: 22px;
    font-weight: 600;
    color: var(--fpc-accent);
    display: flex;
    align-items: center;
    gap: 10px;
}
.fpc-header h1 span { font-size: 26px; }
.fpc-header-right {
    display: flex;
    align-items: center;
    gap: 15px;
    font-size: 13px;
    color: var(--fpc-text2);
}
.fpc-version {
    background: var(--fpc-surface2);
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    color: var(--fpc-accent);
    border: 1px solid var(--fpc-border);
}

/* Tab-Navigation */
.fpc-tabs {
    display: flex;
    background: var(--fpc-surface);
    border-bottom: 2px solid var(--fpc-border);
    padding: 0 30px;
    gap: 0;
    overflow-x: auto;
}
.fpc-tab {
    padding: 14px 24px;
    font-size: 14px;
    font-weight: 500;
    color: var(--fpc-text2);
    text-decoration: none;
    border-bottom: 3px solid transparent;
    transition: all 0.2s;
    white-space: nowrap;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
}
.fpc-tab:hover {
    color: var(--fpc-text);
    background: rgba(255,255,255,0.03);
}
.fpc-tab.active {
    color: var(--fpc-accent);
    border-bottom-color: var(--fpc-accent);
    background: rgba(0,212,170,0.05);
}
.fpc-tab-icon { font-size: 16px; }

/* Content */
.fpc-content {
    padding: 24px 30px;
    max-width: 1400px;
}
.fpc-tab-panel { display: none; }
.fpc-tab-panel.active { display: block; }

/* KPI Cards */
.fpc-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.fpc-kpi {
    background: var(--fpc-surface);
    border: 1px solid var(--fpc-border);
    border-radius: var(--fpc-radius);
    padding: 20px;
    position: relative;
    overflow: hidden;
}
.fpc-kpi::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
}
.fpc-kpi.green::before { background: var(--fpc-success); }
.fpc-kpi.blue::before { background: var(--fpc-accent2); }
.fpc-kpi.orange::before { background: var(--fpc-warn); }
.fpc-kpi.red::before { background: var(--fpc-danger); }
.fpc-kpi.teal::before { background: var(--fpc-accent); }
.fpc-kpi-label {
    font-size: 12px;
    color: var(--fpc-text2);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}
.fpc-kpi-value {
    font-size: 28px;
    font-weight: 700;
    line-height: 1.1;
}
.fpc-kpi-sub {
    font-size: 12px;
    color: var(--fpc-text2);
    margin-top: 6px;
}

/* Charts */
.fpc-charts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}
.fpc-chart-card {
    background: var(--fpc-surface);
    border: 1px solid var(--fpc-border);
    border-radius: var(--fpc-radius);
    padding: 20px;
}
.fpc-chart-title {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 16px;
    color: var(--fpc-text);
}

/* Buttons */
.fpc-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border: none;
    border-radius: var(--fpc-radius);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    color: #fff;
}
.fpc-btn:hover { transform: translateY(-1px); filter: brightness(1.1); }
.fpc-btn:active { transform: translateY(0); }
.fpc-btn.green { background: var(--fpc-success); }
.fpc-btn.red { background: var(--fpc-danger); }
.fpc-btn.blue { background: var(--fpc-accent2); }
.fpc-btn.teal { background: var(--fpc-accent); color: #0f1923; }
.fpc-btn.orange { background: var(--fpc-warn); color: #0f1923; }
.fpc-btn.dark { background: var(--fpc-surface2); border: 1px solid var(--fpc-border); }
.fpc-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.fpc-btn-group { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }

/* Tables */
.fpc-table-wrap {
    background: var(--fpc-surface);
    border: 1px solid var(--fpc-border);
    border-radius: var(--fpc-radius);
    overflow: hidden;
    margin-bottom: 20px;
}
.fpc-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.fpc-table th {
    background: var(--fpc-surface2);
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: var(--fpc-text2);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--fpc-border);
}
.fpc-table td {
    padding: 10px 16px;
    border-bottom: 1px solid var(--fpc-border);
    color: var(--fpc-text);
}
.fpc-table tr:last-child td { border-bottom: none; }
.fpc-table tr:hover td { background: rgba(255,255,255,0.02); }

/* Log Viewer */
.fpc-log {
    background: #0a1018;
    border: 1px solid var(--fpc-border);
    border-radius: var(--fpc-radius);
    padding: 16px;
    font-family: 'Cascadia Code', 'Fira Code', 'Consolas', monospace;
    font-size: 12px;
    line-height: 1.6;
    color: #8fbcbb;
    max-height: 500px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-all;
}
.fpc-log .log-error { color: var(--fpc-danger); }
.fpc-log .log-warn { color: var(--fpc-warn); }
.fpc-log .log-ok { color: var(--fpc-success); }
.fpc-log .log-info { color: var(--fpc-accent2); }

/* Input */
.fpc-input {
    background: var(--fpc-surface2);
    border: 1px solid var(--fpc-border);
    border-radius: var(--fpc-radius);
    padding: 10px 14px;
    color: var(--fpc-text);
    font-size: 13px;
    outline: none;
    transition: border-color 0.2s;
    width: 100%;
}
.fpc-input:focus { border-color: var(--fpc-accent); }
.fpc-input-group {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}
.fpc-input-group .fpc-input { flex: 1; }

/* Status Badge */
.fpc-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.fpc-badge.hit { background: rgba(46,213,115,0.15); color: var(--fpc-success); }
.fpc-badge.miss { background: rgba(255,71,87,0.15); color: var(--fpc-danger); }
.fpc-badge.running { background: rgba(0,212,170,0.15); color: var(--fpc-accent); }
.fpc-badge.stopped { background: rgba(136,153,170,0.15); color: var(--fpc-text2); }

/* Pagination */
.fpc-pagination {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 16px;
}
.fpc-pagination button {
    background: var(--fpc-surface2);
    border: 1px solid var(--fpc-border);
    color: var(--fpc-text);
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}
.fpc-pagination button:hover { border-color: var(--fpc-accent); }
.fpc-pagination button.active { background: var(--fpc-accent); color: #0f1923; border-color: var(--fpc-accent); }

/* Section Title */
.fpc-section-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--fpc-border);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Toast Notification */
.fpc-toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--fpc-surface);
    border: 1px solid var(--fpc-border);
    border-radius: var(--fpc-radius);
    padding: 14px 20px;
    font-size: 13px;
    z-index: 9999;
    box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    transform: translateY(100px);
    opacity: 0;
    transition: all 0.3s;
    max-width: 400px;
}
.fpc-toast.show { transform: translateY(0); opacity: 1; }
.fpc-toast.success { border-left: 4px solid var(--fpc-success); }
.fpc-toast.error { border-left: 4px solid var(--fpc-danger); }
.fpc-toast.info { border-left: 4px solid var(--fpc-accent2); }

/* Spinner */
.fpc-spinner {
    display: inline-block;
    width: 16px; height: 16px;
    border: 2px solid var(--fpc-border);
    border-top-color: var(--fpc-accent);
    border-radius: 50%;
    animation: fpc-spin 0.8s linear infinite;
}
@keyframes fpc-spin { to { transform: rotate(360deg); } }

/* Responsive */
@media (max-width: 900px) {
    .fpc-charts { grid-template-columns: 1fr; }
    .fpc-kpis { grid-template-columns: repeat(2, 1fr); }
    .fpc-content { padding: 16px; }
}
</style>
</head>
<body>
<div class="fpc-wrap">

<!-- HEADER -->
<div class="fpc-header">
    <h1><span>&#9881;</span> FPC Schaltzentrale</h1>
    <div class="fpc-header-right">
        <span id="fpc-clock"></span>
        <span class="fpc-version">v8.0.9</span>
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
</div>

<!-- ============================================================ -->
<!-- TAB 1: DASHBOARD -->
<!-- ============================================================ -->
<div class="fpc-content">
<div class="fpc-tab-panel <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>" id="panel-dashboard">

    <!-- KPI Cards -->
    <div class="fpc-kpis">
        <div class="fpc-kpi teal">
            <div class="fpc-kpi-label">Gecachte Seiten</div>
            <div class="fpc-kpi-value" id="kpi-files">--</div>
            <div class="fpc-kpi-sub" id="kpi-files-sub"></div>
        </div>
        <div class="fpc-kpi blue">
            <div class="fpc-kpi-label">Cache-Groesse</div>
            <div class="fpc-kpi-value" id="kpi-size">--</div>
            <div class="fpc-kpi-sub" id="kpi-size-sub"></div>
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
        <div class="fpc-kpi blue">
            <div class="fpc-kpi-label">Aelteste Datei</div>
            <div class="fpc-kpi-value" id="kpi-oldest" style="font-size:16px;">--</div>
        </div>
        <div class="fpc-kpi teal">
            <div class="fpc-kpi-label">Neueste Datei</div>
            <div class="fpc-kpi-value" id="kpi-newest" style="font-size:16px;">--</div>
        </div>
    </div>

    <!-- Charts -->
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

<!-- ============================================================ -->
<!-- TAB 2: STEUERUNG -->
<!-- ============================================================ -->
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
        URLs die zusaetzlich zum Sitemap-Preloader gecacht werden sollen. Diese werden in <code>cache/fpc/custom_urls.txt</code> gespeichert.
    </p>
    <div class="fpc-input-group">
        <input type="text" class="fpc-input" id="custom-url-input" placeholder="Neue URL hinzufuegen...">
        <button class="fpc-btn blue" onclick="fpcAddCustomUrl()">Hinzufuegen</button>
    </div>
    <div id="custom-urls-list"></div>
</div>

<!-- ============================================================ -->
<!-- TAB 3: URLs -->
<!-- ============================================================ -->
<div class="fpc-tab-panel <?php echo $active_tab === 'urls' ? 'active' : ''; ?>" id="panel-urls">

    <div class="fpc-section-title">&#128279; Gecachte URLs durchsuchen</div>
    <div class="fpc-input-group">
        <input type="text" class="fpc-input" id="url-search" placeholder="Suchen... (z.B. autoflowering, growshop, blog)" oninput="fpcSearchUrls()">
        <button class="fpc-btn dark" onclick="fpcLoadUrls(1)">&#128269; Suchen</button>
    </div>
    <div id="url-count" style="color:var(--fpc-text2); font-size:13px; margin-bottom:12px;"></div>
    <div id="urls-table"></div>
    <div id="urls-pagination" class="fpc-pagination"></div>
</div>

<!-- ============================================================ -->
<!-- TAB 4: LOGS -->
<!-- ============================================================ -->
<div class="fpc-tab-panel <?php echo $active_tab === 'logs' ? 'active' : ''; ?>" id="panel-logs">

    <div class="fpc-section-title">&#128196; Log-Viewer</div>
    <div class="fpc-btn-group">
        <button class="fpc-btn dark" onclick="fpcLoadLog('preloader')" id="btn-log-preloader">Preloader-Log</button>
        <button class="fpc-btn dark" onclick="fpcLoadLog('rebuild')" id="btn-log-rebuild">Rebuild-Log</button>
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

<!-- ============================================================ -->
<!-- TAB 5: MONITORING -->
<!-- ============================================================ -->
<div class="fpc-tab-panel <?php echo $active_tab === 'monitoring' ? 'active' : ''; ?>" id="panel-monitoring">

    <div class="fpc-section-title">&#128200; Cache-Monitoring</div>
    <p style="color:var(--fpc-text2); font-size:13px; margin-bottom:16px;">
        Testet zufaellige gecachte URLs auf Erreichbarkeit, FPC-Status und Redirect-Verhalten.
    </p>
    <div class="fpc-btn-group">
        <button class="fpc-btn teal" onclick="fpcRunMonitor(20)" id="btn-monitor">&#9654; Test starten (20 URLs)</button>
        <button class="fpc-btn blue" onclick="fpcRunMonitor(50)">&#9654; Grosser Test (50 URLs)</button>
    </div>

    <!-- Monitor Charts -->
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

    <!-- Letzter Test-Ergebnis -->
    <div class="fpc-section-title" style="margin-top:20px;">Letztes Testergebnis</div>
    <div id="monitor-results"></div>

    <!-- Historie -->
    <div class="fpc-section-title" style="margin-top:20px;">Test-Historie</div>
    <div id="monitor-history"></div>
</div>

</div><!-- /fpc-content -->
</div><!-- /fpc-wrap -->

<!-- ============================================================ -->
<!-- TOAST -->
<!-- ============================================================ -->
<div class="fpc-toast" id="fpc-toast"></div>

<!-- ============================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================ -->
<script>
(function() {
    'use strict';

    const BASE = 'fpc_dashboard.php';
    let currentLogType = null;
    let autoRefreshInterval = null;
    let currentUrlPage = 1;
    let searchTimeout = null;

    // Charts
    let chartCategories = null;
    let chartLastStats = null;
    let chartMonitorHitrate = null;
    let chartMonitorTtfb = null;

    // ============================================================
    // TAB-NAVIGATION
    // ============================================================
    document.querySelectorAll('.fpc-tab').forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            const target = this.dataset.tab;
            document.querySelectorAll('.fpc-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.fpc-tab-panel').forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('panel-' + target).classList.add('active');

            // Tab-spezifische Aktionen
            if (target === 'urls') fpcLoadUrls(1);
            if (target === 'monitoring') fpcLoadMonitorData();
            if (target === 'steuerung') fpcLoadCustomUrls();

            // URL aktualisieren ohne Reload
            history.replaceState(null, '', BASE + '?tab=' + target);
        });
    });

    // ============================================================
    // CLOCK
    // ============================================================
    function updateClock() {
        const now = new Date();
        document.getElementById('fpc-clock').textContent = now.toLocaleString('de-DE');
    }
    setInterval(updateClock, 1000);
    updateClock();

    // ============================================================
    // TOAST
    // ============================================================
    window.fpcToast = function(msg, type) {
        type = type || 'info';
        const el = document.getElementById('fpc-toast');
        el.className = 'fpc-toast ' + type;
        el.textContent = msg;
        el.classList.add('show');
        setTimeout(() => el.classList.remove('show'), 4000);
    };

    // ============================================================
    // AJAX HELPER
    // ============================================================
    async function fpcGet(params) {
        const url = BASE + '?' + new URLSearchParams(params).toString();
        const res = await fetch(url);
        return res.json();
    }

    async function fpcPost(params, body) {
        const url = BASE + '?' + new URLSearchParams(params).toString();
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(body).toString()
        });
        return res.json();
    }

    // ============================================================
    // DASHBOARD - Status laden
    // ============================================================
    async function fpcLoadStatus() {
        try {
            const d = await fpcGet({ ajax: 'status' });

            document.getElementById('kpi-files').textContent = d.files;
            document.getElementById('kpi-files-sub').textContent = 'Ø ' + d.avg_file_size + ' pro Datei';
            document.getElementById('kpi-size').textContent = d.size_formatted;
            document.getElementById('kpi-oldest').textContent = d.oldest || 'Kein Cache';
            document.getElementById('kpi-newest').textContent = d.newest || 'Kein Cache';

            if (d.rebuild_running) {
                document.getElementById('kpi-rebuild').innerHTML = '<span class="fpc-badge running">&#9679; Laeuft</span>';
                document.getElementById('kpi-rebuild-sub').textContent = 'Seit ' + (d.rebuild_started || '?');
                document.getElementById('btn-rebuild').style.display = 'none';
                document.getElementById('btn-stop').style.display = '';
            } else {
                document.getElementById('kpi-rebuild').innerHTML = '<span class="fpc-badge stopped">&#9679; Inaktiv</span>';
                document.getElementById('kpi-rebuild-sub').textContent = '';
                document.getElementById('btn-rebuild').style.display = '';
                document.getElementById('btn-stop').style.display = 'none';
            }

            if (d.last_run) {
                document.getElementById('kpi-lastrun').textContent = d.last_run;
                if (d.last_stats) {
                    document.getElementById('kpi-lastrun-sub').textContent =
                        'Neu: ' + d.last_stats.cached + ' | Skip: ' + d.last_stats.skipped + ' | Fehler: ' + d.last_stats.errors;
                }
            }

            // Category Chart
            if (d.categories && Object.keys(d.categories).length > 0) {
                renderCategoryChart(d.categories);
            }

            // Last Stats Chart
            if (d.last_stats) {
                renderLastStatsChart(d.last_stats);
            }

        } catch (e) {
            console.error('Status-Fehler:', e);
        }
    }

    function renderCategoryChart(cats) {
        const ctx = document.getElementById('chart-categories').getContext('2d');
        const labels = Object.keys(cats);
        const values = Object.values(cats);
        const colors = generateColors(labels.length);

        if (chartCategories) chartCategories.destroy();
        chartCategories = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'right', labels: { color: '#8899aa', font: { size: 11 } } }
                }
            }
        });
    }

    function renderLastStatsChart(stats) {
        const ctx = document.getElementById('chart-laststats').getContext('2d');
        if (chartLastStats) chartLastStats.destroy();
        chartLastStats = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Neu gecacht', 'Uebersprungen', 'Fehler'],
                datasets: [{
                    data: [stats.cached, stats.skipped, stats.errors],
                    backgroundColor: ['#2ed573', '#00a8ff', '#ff4757'],
                    borderWidth: 0,
                    borderRadius: 4,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#8899aa' }, grid: { color: '#2d4050' } },
                    y: { ticks: { color: '#8899aa' }, grid: { color: '#2d4050' }, beginAtZero: true }
                }
            }
        });
    }

    function generateColors(n) {
        const base = ['#00d4aa','#00a8ff','#2ed573','#ffa502','#ff4757','#a55eea','#45aaf2','#fed330','#26de81','#fc5c65','#778ca3','#4b7bec','#eb3b5a','#20bf6b','#f7b731'];
        while (base.length < n) base.push('#' + Math.floor(Math.random()*16777215).toString(16).padStart(6,'0'));
        return base.slice(0, n);
    }

    // ============================================================
    // STEUERUNG
    // ============================================================
    window.fpcRebuild = async function() {
        if (!confirm('Cache jetzt neu aufbauen? Der Preloader wird im Hintergrund gestartet.')) return;
        const r = await fpcGet({ ajax: 'rebuild' });
        fpcToast(r.msg, r.ok ? 'success' : 'error');
        fpcLoadStatus();
    };

    window.fpcStopRebuild = async function() {
        if (!confirm('Laufenden Rebuild wirklich stoppen?')) return;
        const r = await fpcGet({ ajax: 'stop' });
        fpcToast(r.msg, r.ok ? 'success' : 'error');
        fpcLoadStatus();
    };

    window.fpcFlush = async function() {
        if (!confirm('ACHTUNG: Gesamten Cache wirklich leeren? Alle gecachten Seiten werden geloescht!')) return;
        const r = await fpcGet({ ajax: 'flush' });
        fpcToast(r.msg, r.ok ? 'success' : 'error');
        fpcLoadStatus();
    };

    window.fpcCacheSingle = async function() {
        const url = document.getElementById('single-url').value.trim();
        if (!url) { fpcToast('Bitte URL eingeben', 'error'); return; }
        document.getElementById('single-url-result').innerHTML = '<span class="fpc-spinner"></span> Wird gecacht...';
        const r = await fpcPost({ ajax: 'cache_url' }, { url: url });
        document.getElementById('single-url-result').innerHTML =
            '<div style="padding:8px; margin-top:8px; border-radius:4px; background:' + (r.ok ? 'rgba(46,213,115,0.1)' : 'rgba(255,71,87,0.1)') + '; color:' + (r.ok ? 'var(--fpc-success)' : 'var(--fpc-danger)') + ';">' + r.msg + '</div>';
        if (r.ok) document.getElementById('single-url').value = '';
    };

    // Custom URLs
    window.fpcAddCustomUrl = async function() {
        const url = document.getElementById('custom-url-input').value.trim();
        if (!url) { fpcToast('Bitte URL eingeben', 'error'); return; }
        const r = await fpcPost({ ajax: 'add_custom_url' }, { url: url });
        fpcToast(r.msg, r.ok ? 'success' : 'error');
        if (r.ok) { document.getElementById('custom-url-input').value = ''; fpcLoadCustomUrls(); }
    };

    window.fpcRemoveCustomUrl = async function(url) {
        if (!confirm('URL entfernen: ' + url + '?')) return;
        const r = await fpcPost({ ajax: 'remove_custom_url' }, { url: url });
        fpcToast(r.msg, r.ok ? 'success' : 'error');
        fpcLoadCustomUrls();
    };

    async function fpcLoadCustomUrls() {
        const r = await fpcGet({ ajax: 'custom_urls' });
        const el = document.getElementById('custom-urls-list');
        if (!r.urls || r.urls.length === 0) {
            el.innerHTML = '<p style="color:var(--fpc-text2); font-size:13px;">Keine eigenen URLs definiert.</p>';
            return;
        }
        let html = '<div class="fpc-table-wrap"><table class="fpc-table"><thead><tr><th>URL</th><th style="width:140px;">Aktionen</th></tr></thead><tbody>';
        r.urls.forEach(u => {
            html += '<tr><td><code>' + u + '</code></td><td>'
                  + '<button class="fpc-btn teal" style="padding:4px 10px; font-size:11px;" onclick="fpcCacheSingleDirect(\'' + u + '\')">Cachen</button> '
                  + '<button class="fpc-btn red" style="padding:4px 10px; font-size:11px;" onclick="fpcRemoveCustomUrl(\'' + u + '\')">Entfernen</button>'
                  + '</td></tr>';
        });
        html += '</tbody></table></div>';
        el.innerHTML = html;
    }

    window.fpcCacheSingleDirect = async function(url) {
        fpcToast('Wird gecacht: ' + url, 'info');
        const r = await fpcPost({ ajax: 'cache_url' }, { url: url });
        fpcToast(r.msg, r.ok ? 'success' : 'error');
    };

    // ============================================================
    // URL-VERWALTUNG
    // ============================================================
    window.fpcLoadUrls = async function(page) {
        currentUrlPage = page || 1;
        const search = document.getElementById('url-search').value.trim();
        const r = await fpcGet({ ajax: 'urls', search: search, page: currentUrlPage });

        document.getElementById('url-count').textContent = r.total + ' gecachte URLs' + (search ? ' (Filter: "' + search + '")' : '');

        let html = '<div class="fpc-table-wrap"><table class="fpc-table"><thead><tr><th>Pfad</th><th>Groesse</th><th>Gecacht am</th><th>Alter (h)</th><th>Aktionen</th></tr></thead><tbody>';
        if (r.urls.length === 0) {
            html += '<tr><td colspan="5" style="text-align:center; color:var(--fpc-text2);">Keine URLs gefunden</td></tr>';
        }
        r.urls.forEach(u => {
            const ageColor = u.age_h > 24 ? 'var(--fpc-danger)' : u.age_h > 12 ? 'var(--fpc-warn)' : 'var(--fpc-success)';
            html += '<tr>'
                  + '<td><code style="font-size:12px;">' + u.path + '</code></td>'
                  + '<td>' + u.size_f + '</td>'
                  + '<td>' + u.cached + '</td>'
                  + '<td style="color:' + ageColor + ';">' + u.age_h + 'h</td>'
                  + '<td>'
                  + '<button class="fpc-btn teal" style="padding:3px 8px; font-size:11px;" onclick="fpcRecacheUrl(\'' + u.path + '\')">&#8635;</button> '
                  + '<button class="fpc-btn red" style="padding:3px 8px; font-size:11px;" onclick="fpcRemoveUrl(\'' + u.path + '\')">&#10005;</button>'
                  + '</td></tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('urls-table').innerHTML = html;

        // Pagination
        let pag = '';
        if (r.pages > 1) {
            if (currentUrlPage > 1) pag += '<button onclick="fpcLoadUrls(' + (currentUrlPage-1) + ')">&#9664; Zurueck</button>';
            for (let i = 1; i <= r.pages; i++) {
                if (i === currentUrlPage) pag += '<button class="active">' + i + '</button>';
                else if (Math.abs(i - currentUrlPage) < 4 || i === 1 || i === r.pages) pag += '<button onclick="fpcLoadUrls(' + i + ')">' + i + '</button>';
                else if (Math.abs(i - currentUrlPage) === 4) pag += '<span style="color:var(--fpc-text2);">...</span>';
            }
            if (currentUrlPage < r.pages) pag += '<button onclick="fpcLoadUrls(' + (currentUrlPage+1) + ')">Weiter &#9654;</button>';
        }
        document.getElementById('urls-pagination').innerHTML = pag;
    };

    window.fpcSearchUrls = function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => fpcLoadUrls(1), 300);
    };

    window.fpcRecacheUrl = async function(path) {
        fpcToast('Wird neu gecacht: ' + path, 'info');
        const r = await fpcPost({ ajax: 'cache_url' }, { url: path });
        fpcToast(r.msg, r.ok ? 'success' : 'error');
        fpcLoadUrls(currentUrlPage);
    };

    window.fpcRemoveUrl = async function(path) {
        if (!confirm('Aus Cache entfernen: ' + path + '?')) return;
        const r = await fpcPost({ ajax: 'remove_url' }, { path: path });
        fpcToast(r.msg, r.ok ? 'success' : 'error');
        fpcLoadUrls(currentUrlPage);
    };

    // ============================================================
    // LOG-VIEWER
    // ============================================================
    window.fpcLoadLog = async function(type) {
        currentLogType = type;
        const lines = document.getElementById('log-lines').value;
        document.getElementById('btn-log-preloader').classList.toggle('teal', type === 'preloader');
        document.getElementById('btn-log-preloader').classList.toggle('dark', type !== 'preloader');
        document.getElementById('btn-log-rebuild').classList.toggle('teal', type === 'rebuild');
        document.getElementById('btn-log-rebuild').classList.toggle('dark', type !== 'rebuild');

        const r = await fpcGet({ ajax: 'log', type: type, lines: lines });
        document.getElementById('log-info').textContent = r.file + ' (letzte ' + lines + ' Zeilen)';

        // Syntax-Highlighting
        let content = r.content || '';
        content = content.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        content = content.replace(/^(.*FEHLER.*)$/gm, '<span class="log-error">$1</span>');
        content = content.replace(/^(.*ABBRUCH.*)$/gm, '<span class="log-error">$1</span>');
        content = content.replace(/^(.*UNGUELTIG.*)$/gm, '<span class="log-warn">$1</span>');
        content = content.replace(/^(.*REDIRECT.*)$/gm, '<span class="log-warn">$1</span>');
        content = content.replace(/^(.*Fertig.*)$/gm, '<span class="log-ok">$1</span>');
        content = content.replace(/^(.*Gecacht:.*)$/gm, '<span class="log-ok">$1</span>');
        content = content.replace(/^(.*Start:.*)$/gm, '<span class="log-info">$1</span>');

        const el = document.getElementById('log-content');
        el.innerHTML = content;
        el.scrollTop = el.scrollHeight;
    };

    window.fpcReloadLog = function() {
        if (currentLogType) fpcLoadLog(currentLogType);
    };

    window.fpcAutoRefreshLog = function() {
        const btn = document.getElementById('btn-log-auto');
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
            btn.textContent = '↻ Auto-Refresh: Aus';
            btn.classList.remove('green');
            btn.classList.add('teal');
        } else {
            if (!currentLogType) { fpcToast('Bitte zuerst einen Log-Typ waehlen', 'error'); return; }
            autoRefreshInterval = setInterval(() => fpcLoadLog(currentLogType), 3000);
            btn.textContent = '↻ Auto-Refresh: An (3s)';
            btn.classList.remove('teal');
            btn.classList.add('green');
        }
    };

    // ============================================================
    // MONITORING
    // ============================================================
    window.fpcRunMonitor = async function(count) {
        document.getElementById('btn-monitor').disabled = true;
        document.getElementById('btn-monitor').innerHTML = '<span class="fpc-spinner"></span> Test laeuft...';
        fpcToast('Monitoring-Test gestartet (' + count + ' URLs)...', 'info');

        try {
            const r = await fpcPost({ ajax: 'run_monitor' }, { count: count });
            if (r.ok && r.run) {
                fpcToast('Test abgeschlossen: HIT-Rate ' + r.run.hit_rate + '%', 'success');
                renderMonitorResults(r.run);
            } else {
                fpcToast(r.msg || 'Fehler beim Test', 'error');
            }
        } catch (e) {
            fpcToast('Fehler: ' + e.message, 'error');
        }

        document.getElementById('btn-monitor').disabled = false;
        document.getElementById('btn-monitor').innerHTML = '&#9654; Test starten (20 URLs)';
        fpcLoadMonitorData();
    };

    function renderMonitorResults(run) {
        let html = '<div class="fpc-kpis" style="margin-bottom:16px;">'
            + '<div class="fpc-kpi green"><div class="fpc-kpi-label">HIT-Rate</div><div class="fpc-kpi-value">' + run.hit_rate + '%</div></div>'
            + '<div class="fpc-kpi blue"><div class="fpc-kpi-label">Ø TTFB</div><div class="fpc-kpi-value">' + run.avg_ttfb + 'ms</div></div>'
            + '<div class="fpc-kpi teal"><div class="fpc-kpi-label">HITs / MISSes</div><div class="fpc-kpi-value">' + run.hits + ' / ' + run.misses + '</div></div>'
            + '<div class="fpc-kpi ' + (run.redirects > 0 ? 'orange' : 'green') + '"><div class="fpc-kpi-label">Redirects</div><div class="fpc-kpi-value">' + run.redirects + '</div></div>'
            + '</div>';

        html += '<div class="fpc-table-wrap"><table class="fpc-table"><thead><tr><th>URL</th><th>HTTP</th><th>FPC</th><th>TTFB</th><th>Redirect</th></tr></thead><tbody>';
        run.results.forEach(r => {
            const fpcClass = r.fpc === 'HIT' ? 'hit' : 'miss';
            const httpColor = r.http >= 400 ? 'var(--fpc-danger)' : r.http >= 300 ? 'var(--fpc-warn)' : 'var(--fpc-success)';
            html += '<tr>'
                + '<td><code style="font-size:11px;">' + r.url + '</code></td>'
                + '<td style="color:' + httpColor + ';">' + r.http + '</td>'
                + '<td><span class="fpc-badge ' + fpcClass + '">' + r.fpc + '</span></td>'
                + '<td>' + r.ttfb + 'ms</td>'
                + '<td>' + (r.redirect ? '&#9888; Ja' : '&#10003;') + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('monitor-results').innerHTML = html;
    }

    async function fpcLoadMonitorData() {
        const data = await fpcGet({ ajax: 'monitor_data' });
        if (!data.runs || data.runs.length === 0) {
            document.getElementById('monitor-history').innerHTML = '<p style="color:var(--fpc-text2);">Noch keine Tests durchgefuehrt.</p>';
            return;
        }

        // Charts
        const labels = data.runs.map(r => r.timestamp.substring(5, 16));
        const hitrates = data.runs.map(r => r.hit_rate);
        const ttfbs = data.runs.map(r => r.avg_ttfb);

        const ctxHit = document.getElementById('chart-monitor-hitrate').getContext('2d');
        if (chartMonitorHitrate) chartMonitorHitrate.destroy();
        chartMonitorHitrate = new Chart(ctxHit, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'HIT-Rate %',
                    data: hitrates,
                    borderColor: '#2ed573',
                    backgroundColor: 'rgba(46,213,115,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4,
                    pointBackgroundColor: '#2ed573',
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { labels: { color: '#8899aa' } } },
                scales: {
                    x: { ticks: { color: '#8899aa', maxRotation: 45 }, grid: { color: '#2d4050' } },
                    y: { min: 0, max: 100, ticks: { color: '#8899aa' }, grid: { color: '#2d4050' } }
                }
            }
        });

        const ctxTtfb = document.getElementById('chart-monitor-ttfb').getContext('2d');
        if (chartMonitorTtfb) chartMonitorTtfb.destroy();
        chartMonitorTtfb = new Chart(ctxTtfb, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Ø TTFB (ms)',
                    data: ttfbs,
                    borderColor: '#00a8ff',
                    backgroundColor: 'rgba(0,168,255,0.1)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4,
                    pointBackgroundColor: '#00a8ff',
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { labels: { color: '#8899aa' } } },
                scales: {
                    x: { ticks: { color: '#8899aa', maxRotation: 45 }, grid: { color: '#2d4050' } },
                    y: { ticks: { color: '#8899aa' }, grid: { color: '#2d4050' }, beginAtZero: true }
                }
            }
        });

        // Historie-Tabelle
        let html = '<div class="fpc-table-wrap"><table class="fpc-table"><thead><tr><th>Zeitpunkt</th><th>URLs</th><th>HIT-Rate</th><th>HITs</th><th>MISSes</th><th>Redirects</th><th>Ø TTFB</th></tr></thead><tbody>';
        data.runs.slice().reverse().forEach(r => {
            const hitColor = r.hit_rate >= 90 ? 'var(--fpc-success)' : r.hit_rate >= 70 ? 'var(--fpc-warn)' : 'var(--fpc-danger)';
            html += '<tr>'
                + '<td>' + r.timestamp + '</td>'
                + '<td>' + r.total + '</td>'
                + '<td style="color:' + hitColor + '; font-weight:bold;">' + r.hit_rate + '%</td>'
                + '<td>' + r.hits + '</td>'
                + '<td>' + r.misses + '</td>'
                + '<td>' + (r.redirects || 0) + '</td>'
                + '<td>' + r.avg_ttfb + 'ms</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('monitor-history').innerHTML = html;
    }

    // ============================================================
    // INIT
    // ============================================================
    fpcLoadStatus();
    setInterval(fpcLoadStatus, 30000); // Alle 30s aktualisieren

    // Tab-spezifische Init
    const activeTab = '<?php echo $active_tab; ?>';
    if (activeTab === 'urls') fpcLoadUrls(1);
    if (activeTab === 'monitoring') fpcLoadMonitorData();
    if (activeTab === 'steuerung') fpcLoadCustomUrls();

})();
</script>
</body>
</html>
