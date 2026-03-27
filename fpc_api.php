<?php
/**
 * Mr. Hanf FPC Management API v1.0
 *
 * Secure REST-style API for remote FPC management.
 * Allows external tools (Manus AI, monitoring, scripts) to:
 *   - Read/change settings
 *   - Flush cache (full or single URL)
 *   - Start/stop preloader
 *   - Query status, health, performance
 *   - Enable/disable FPC module
 *
 * Authentication: Bearer Token or ?token= query parameter
 *
 * Usage:
 *   curl -H "Authorization: Bearer YOUR_TOKEN" "https://mr-hanf.de/fpc_api.php?action=status"
 *   curl "https://mr-hanf.de/fpc_api.php?action=status&token=YOUR_TOKEN"
 *
 * @version   1.0.0
 * @date      2026-03-28
 */

// ============================================================
// CONFIGURATION
// ============================================================
$API_TOKEN = 'Ql58SV-__h4SNetL3a3du2s4JjpgRVYq63GPKrGk6WA';
$CACHE_DIR = __DIR__ . '/cache/fpc/';
$LOG_DIR   = $CACHE_DIR . 'logs/';

// ============================================================
// AUTHENTICATION
// ============================================================
header('Content-Type: application/json; charset=utf-8');
header('X-FPC-API: 1.0');

// Get token from Authorization header or query param
$token = '';
$auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
    $token = $m[1];
} elseif (isset($_GET['token'])) {
    $token = $_GET['token'];
}

if (!hash_equals($API_TOKEN, $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Provide valid token.']);
    exit;
}

// ============================================================
// DATABASE CONNECTION
// ============================================================
$shop_dir = __DIR__ . '/';
$db = null;

function fpc_api_db() {
    global $db, $shop_dir;
    if ($db !== null) return $db;

    if (!is_file($shop_dir . 'includes/configure.php')) {
        return null;
    }
    if (!defined('_VALID_XTC')) define('_VALID_XTC', true);
    require_once($shop_dir . 'includes/configure.php');

    $db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
    if ($db->connect_error) {
        $db = null;
        return null;
    }
    $db->set_charset('utf8');
    return $db;
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function fpc_api_get_config() {
    $db = fpc_api_db();
    if (!$db) return [];
    $config = [];
    $r = $db->query("SELECT configuration_key, configuration_value FROM configuration WHERE configuration_key LIKE 'MODULE_MRHANF_FPC_%'");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $config[$row['configuration_key']] = $row['configuration_value'];
        }
    }
    return $config;
}

function fpc_api_set_config($key, $value) {
    $db = fpc_api_db();
    if (!$db) return false;
    $safe_key = $db->real_escape_string($key);
    $safe_val = $db->real_escape_string($value);
    return $db->query("UPDATE configuration SET configuration_value = '{$safe_val}', last_modified = NOW() WHERE configuration_key = '{$safe_key}'");
}

function fpc_api_count_cache() {
    global $CACHE_DIR;
    $count = 0;
    $size = 0;
    if (is_dir($CACHE_DIR)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iter as $f) {
            if ($f->isFile() && $f->getExtension() === 'html') {
                $count++;
                $size += $f->getSize();
            }
        }
    }
    return ['files' => $count, 'size_bytes' => $size, 'size_mb' => round($size / 1048576, 2)];
}

function fpc_api_read_daily_logs($days = 1) {
    global $LOG_DIR;
    $entries = [];
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $file = $LOG_DIR . "requests_{$date}.log";
        if (is_file($file)) {
            $fh = fopen($file, 'r');
            if ($fh) {
                while (($line = fgets($fh)) !== false) {
                    $entry = json_decode(trim($line), true);
                    if ($entry) $entries[] = $entry;
                }
                fclose($fh);
            }
        }
    }
    return $entries;
}

function fpc_api_log($msg) {
    global $CACHE_DIR;
    $log = $CACHE_DIR . 'api_access.log';
    $line = date('Y-m-d H:i:s') . ' | ' . $_SERVER['REMOTE_ADDR'] . ' | ' . $msg . "\n";
    @file_put_contents($log, $line, FILE_APPEND | LOCK_EX);
}

// ============================================================
// ROUTING
// ============================================================
$action = isset($_GET['action']) ? $_GET['action'] : '';
fpc_api_log("action={$action}");

switch ($action) {

    // ----------------------------------------------------------
    // STATUS: Full system overview
    // ----------------------------------------------------------
    case 'status':
        $config = fpc_api_get_config();
        $cache = fpc_api_count_cache();
        $preloader_running = is_file($CACHE_DIR . 'preloader.lock');
        $preloader_pid = $preloader_running ? trim(@file_get_contents($CACHE_DIR . 'preloader.lock')) : null;

        // Today's request stats
        $logs = fpc_api_read_daily_logs(1);
        $hits = 0; $misses = 0; $bypasses = 0;
        foreach ($logs as $e) {
            $s = isset($e['status']) ? $e['status'] : '';
            if ($s === 'HIT') $hits++;
            elseif ($s === 'MISS') $misses++;
            elseif ($s === 'BYPASS') $bypasses++;
        }
        $total_req = $hits + $misses + $bypasses;
        $hit_rate = $total_req > 0 ? round($hits / $total_req * 100, 1) : 0;

        echo json_encode([
            'ok' => true,
            'fpc_enabled' => isset($config['MODULE_MRHANF_FPC_STATUS']) && $config['MODULE_MRHANF_FPC_STATUS'] === 'True',
            'cache' => $cache,
            'preloader_running' => $preloader_running,
            'preloader_pid' => $preloader_pid,
            'today' => [
                'requests' => $total_req,
                'hits' => $hits,
                'misses' => $misses,
                'bypasses' => $bypasses,
                'hit_rate' => $hit_rate,
            ],
            'config' => [
                'cache_time' => isset($config['MODULE_MRHANF_FPC_CACHE_TIME']) ? (int)$config['MODULE_MRHANF_FPC_CACHE_TIME'] : 86400,
                'preload_limit' => isset($config['MODULE_MRHANF_FPC_PRELOAD_LIMIT']) ? (int)$config['MODULE_MRHANF_FPC_PRELOAD_LIMIT'] : 500,
                'excluded_pages' => isset($config['MODULE_MRHANF_FPC_EXCLUDED_PAGES']) ? $config['MODULE_MRHANF_FPC_EXCLUDED_PAGES'] : '',
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
        break;

    // ----------------------------------------------------------
    // SETTINGS: Read all settings
    // ----------------------------------------------------------
    case 'settings':
        $config = fpc_api_get_config();
        echo json_encode([
            'ok' => true,
            'settings' => $config,
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
        break;

    // ----------------------------------------------------------
    // SET: Change a setting
    // ----------------------------------------------------------
    case 'set':
        $key = isset($_GET['key']) ? $_GET['key'] : (isset($_POST['key']) ? $_POST['key'] : '');
        $value = isset($_GET['value']) ? $_GET['value'] : (isset($_POST['value']) ? $_POST['value'] : '');

        // Security: only allow FPC config keys
        if (!preg_match('/^MODULE_MRHANF_FPC_/', $key)) {
            echo json_encode(['ok' => false, 'error' => 'Only MODULE_MRHANF_FPC_* keys allowed.']);
            break;
        }

        $result = fpc_api_set_config($key, $value);
        fpc_api_log("SET {$key} = {$value} | result=" . ($result ? 'ok' : 'fail'));
        echo json_encode([
            'ok' => (bool)$result,
            'key' => $key,
            'value' => $value,
            'msg' => $result ? 'Setting updated.' : 'Failed to update setting.',
        ], JSON_PRETTY_PRINT);
        break;

    // ----------------------------------------------------------
    // ENABLE / DISABLE FPC
    // ----------------------------------------------------------
    case 'enable':
        $result = fpc_api_set_config('MODULE_MRHANF_FPC_STATUS', 'True');
        fpc_api_log("ENABLE FPC | result=" . ($result ? 'ok' : 'fail'));
        echo json_encode(['ok' => (bool)$result, 'msg' => 'FPC enabled.']);
        break;

    case 'disable':
        $result = fpc_api_set_config('MODULE_MRHANF_FPC_STATUS', 'False');
        fpc_api_log("DISABLE FPC | result=" . ($result ? 'ok' : 'fail'));
        echo json_encode(['ok' => (bool)$result, 'msg' => 'FPC disabled.']);
        break;

    // ----------------------------------------------------------
    // FLUSH: Clear entire cache or single URL
    // ----------------------------------------------------------
    case 'flush':
        $url = isset($_GET['url']) ? $_GET['url'] : '';

        if ($url) {
            // Flush single URL
            $path = parse_url($url, PHP_URL_PATH);
            $path = rtrim($path, '/');
            if ($path === '') $path = '/';
            $file = $CACHE_DIR . ltrim($path, '/') . '/index.html';
            if (is_file($file)) {
                unlink($file);
                fpc_api_log("FLUSH single: {$path}");
                echo json_encode(['ok' => true, 'msg' => "Flushed: {$path}", 'file' => $file]);
            } else {
                echo json_encode(['ok' => false, 'msg' => "Not cached: {$path}"]);
            }
        } else {
            // Flush all
            $count = 0;
            if (is_dir($CACHE_DIR)) {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($CACHE_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iter as $f) {
                    if ($f->isFile() && $f->getExtension() === 'html') {
                        @unlink($f->getPathname());
                        $count++;
                    }
                }
            }
            fpc_api_log("FLUSH all: {$count} files");
            echo json_encode(['ok' => true, 'msg' => "Flushed {$count} cached files.", 'count' => $count]);
        }
        break;

    // ----------------------------------------------------------
    // PRELOADER: Start / Stop / Status
    // ----------------------------------------------------------
    case 'preloader_start':
        if (is_file($CACHE_DIR . 'preloader.lock')) {
            echo json_encode(['ok' => false, 'msg' => 'Preloader already running.']);
        } else {
            $cmd = 'cd ' . escapeshellarg(__DIR__) . ' && /usr/local/bin/php fpc_preloader.php >> ' . escapeshellarg($CACHE_DIR . 'preloader.log') . ' 2>&1 &';
            exec($cmd);
            fpc_api_log("PRELOADER START");
            echo json_encode(['ok' => true, 'msg' => 'Preloader started.']);
        }
        break;

    case 'preloader_stop':
        $lockfile = $CACHE_DIR . 'preloader.lock';
        if (is_file($lockfile)) {
            $pid = trim(file_get_contents($lockfile));
            if ($pid && is_numeric($pid)) {
                posix_kill((int)$pid, 15); // SIGTERM
            }
            @unlink($lockfile);
            fpc_api_log("PRELOADER STOP pid={$pid}");
            echo json_encode(['ok' => true, 'msg' => "Preloader stopped (PID {$pid})."]);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Preloader not running.']);
        }
        break;

    case 'preloader_status':
        $running = is_file($CACHE_DIR . 'preloader.lock');
        $log_tail = '';
        $log_file = $CACHE_DIR . 'preloader.log';
        if (is_file($log_file)) {
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $log_tail = implode("\n", array_slice($lines, -20));
        }
        echo json_encode([
            'ok' => true,
            'running' => $running,
            'pid' => $running ? trim(@file_get_contents($CACHE_DIR . 'preloader.lock')) : null,
            'last_log' => $log_tail,
        ], JSON_PRETTY_PRINT);
        break;

    // ----------------------------------------------------------
    // HEALTH: Latest healthcheck results
    // ----------------------------------------------------------
    case 'health':
        $hc_file = $CACHE_DIR . 'healthcheck.json';
        if (is_file($hc_file)) {
            $data = json_decode(file_get_contents($hc_file), true);
            $summary = isset($data['latest']['summary']) ? $data['latest']['summary'] : [];
            echo json_encode([
                'ok' => true,
                'health_score' => isset($summary['health_score']) ? $summary['health_score'] : null,
                'health_grade' => isset($summary['health_grade']) ? $summary['health_grade'] : null,
                'tested' => isset($summary['tested']) ? $summary['tested'] : 0,
                'errors' => isset($summary['errors']) ? $summary['errors'] : 0,
                'hit_rate' => isset($summary['hit_rate']) ? $summary['hit_rate'] : 0,
                'avg_ttfb' => isset($summary['avg_ttfb']) ? $summary['avg_ttfb'] : 0,
                'ssl' => isset($summary['ssl']) ? $summary['ssl'] : [],
                'timestamp' => isset($summary['timestamp']) ? $summary['timestamp'] : null,
            ], JSON_PRETTY_PRINT);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'No healthcheck data. Run healthcheck first.']);
        }
        break;

    // ----------------------------------------------------------
    // HEALTH RUN: Trigger healthcheck
    // ----------------------------------------------------------
    case 'health_run':
        $cmd = 'cd ' . escapeshellarg(__DIR__) . ' && /usr/local/bin/php fpc_healthcheck.php >> ' . escapeshellarg($CACHE_DIR . 'healthcheck_cron.log') . ' 2>&1 &';
        exec($cmd);
        fpc_api_log("HEALTHCHECK RUN");
        echo json_encode(['ok' => true, 'msg' => 'Healthcheck started in background.']);
        break;

    // ----------------------------------------------------------
    // PERFORMANCE: Today's performance metrics
    // ----------------------------------------------------------
    case 'performance':
        $logs = fpc_api_read_daily_logs(1);
        $hits = 0; $misses = 0; $bypasses = 0;
        $ttfb_hit = []; $ttfb_miss = [];
        foreach ($logs as $e) {
            $s = isset($e['status']) ? $e['status'] : '';
            $t = isset($e['ttfb']) ? (float)$e['ttfb'] : 0;
            if ($s === 'HIT') { $hits++; $ttfb_hit[] = $t; }
            elseif ($s === 'MISS') { $misses++; $ttfb_miss[] = $t; }
            elseif ($s === 'BYPASS') { $bypasses++; }
        }
        echo json_encode([
            'ok' => true,
            'today' => [
                'total' => count($logs),
                'hits' => $hits,
                'misses' => $misses,
                'bypasses' => $bypasses,
                'hit_rate' => count($logs) > 0 ? round($hits / count($logs) * 100, 1) : 0,
                'avg_ttfb_hit' => count($ttfb_hit) > 0 ? round(array_sum($ttfb_hit) / count($ttfb_hit)) : 0,
                'avg_ttfb_miss' => count($ttfb_miss) > 0 ? round(array_sum($ttfb_miss) / count($ttfb_miss)) : 0,
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
        break;

    // ----------------------------------------------------------
    // ERRORS: Recent error URLs
    // ----------------------------------------------------------
    case 'errors':
        $days = isset($_GET['days']) ? min((int)$_GET['days'], 30) : 1;
        $logs = fpc_api_read_daily_logs($days);
        $errors = [];
        foreach ($logs as $e) {
            $code = isset($e['http_code']) ? (int)$e['http_code'] : 200;
            if ($code >= 400) {
                $errors[] = [
                    'url' => isset($e['url']) ? $e['url'] : '',
                    'http_code' => $code,
                    'time' => isset($e['time']) ? $e['time'] : '',
                ];
            }
        }
        echo json_encode([
            'ok' => true,
            'error_count' => count($errors),
            'errors' => array_slice($errors, -100), // last 100
            'timestamp' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
        break;

    // ----------------------------------------------------------
    // OPCACHE: Reset OPcache
    // ----------------------------------------------------------
    case 'opcache_reset':
        if (function_exists('opcache_reset')) {
            opcache_reset();
            fpc_api_log("OPCACHE RESET");
            echo json_encode(['ok' => true, 'msg' => 'OPcache cleared.']);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'OPcache not available.']);
        }
        break;

    // ----------------------------------------------------------
    // HELP: List all endpoints
    // ----------------------------------------------------------
    case 'help':
    case '':
        echo json_encode([
            'ok' => true,
            'api' => 'FPC Management API v1.0',
            'endpoints' => [
                'status'           => 'GET  - Full system overview (cache, config, today stats)',
                'settings'         => 'GET  - Read all FPC settings from database',
                'set'              => 'GET  - Change setting: &key=MODULE_MRHANF_FPC_*&value=...',
                'enable'           => 'GET  - Enable FPC module',
                'disable'          => 'GET  - Disable FPC module',
                'flush'            => 'GET  - Flush all cache (or single: &url=/path/)',
                'preloader_start'  => 'GET  - Start preloader in background',
                'preloader_stop'   => 'GET  - Stop running preloader',
                'preloader_status' => 'GET  - Preloader status + last 20 log lines',
                'health'           => 'GET  - Latest healthcheck results',
                'health_run'       => 'GET  - Trigger healthcheck in background',
                'performance'      => 'GET  - Today performance metrics (hits, TTFB)',
                'errors'           => 'GET  - Recent error URLs (&days=1..30)',
                'opcache_reset'    => 'GET  - Clear PHP OPcache',
                'help'             => 'GET  - This help page',
            ],
            'auth' => 'Bearer token via Authorization header or ?token= query parameter',
        ], JSON_PRETTY_PRINT);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Unknown action: {$action}. Use action=help for available endpoints."]);
        break;
}

// Close DB if open
if ($db !== null) $db->close();
