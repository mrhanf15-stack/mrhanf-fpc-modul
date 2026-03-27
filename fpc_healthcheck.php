<?php
/**
 * Mr. Hanf FPC Health-Check v1.0
 *
 * Taeglicher Cron-Job (04:00 Uhr) — testet alle gecachten URLs auf:
 *   - Erreichbarkeit (HTTP-Status)
 *   - FPC-Cache-Status (HIT/MISS)
 *   - Redirect-Verhalten (Ketten, Loops)
 *   - TTFB (Time To First Byte)
 *   - HTML-Validitaet (Mindestgroesse, Body-Tag, FPC-VALID Marker)
 *   - Cache-Freshness (Alter der Cache-Dateien)
 *   - SSL-Zertifikat-Pruefung
 *
 * Ergebnisse werden als JSON in cache/fpc/healthcheck.json gespeichert
 * und im FPC Dashboard unter dem Tab "Health-Check" angezeigt.
 *
 * Crontab:  0 4 * * * cd /path/to/shop && /usr/local/bin/php fpc_healthcheck.php >> cache/fpc/healthcheck_cron.log 2>&1
 *
 * @version   1.0.0
 * @date      2026-03-27
 */

// ============================================================
// KONFIGURATION
// ============================================================
$shop_url   = 'https://mr-hanf.de';
$cache_dir  = __DIR__ . '/cache/fpc/';
$output_file = $cache_dir . 'healthcheck.json';
$log_file   = $cache_dir . 'healthcheck_cron.log';
$max_urls   = 200;  // Maximal zu testende URLs
$timeout    = 15;   // Timeout pro URL in Sekunden
$max_history = 90;  // Maximale Anzahl gespeicherter Laeufe (90 Tage)

// Sicherstellen dass Verzeichnis existiert
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0777, true);
}

$start_time = microtime(true);
fpc_log("=== FPC Health-Check gestartet: " . date('Y-m-d H:i:s') . " ===");

// ============================================================
// 1. ALLE GECACHTEN URLs SAMMELN
// ============================================================
$cached_urls = array();
if (is_dir($cache_dir)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if (!$f->isFile() || $f->getExtension() !== 'html') continue;
        $rel = str_replace($cache_dir, '', $f->getPathname());
        $rel = str_replace('/index.html', '', $rel);
        if ($rel === '' || $rel === 'index.html') $path = '/';
        else $path = '/' . $rel . '/';

        $cached_urls[] = array(
            'path'       => $path,
            'file'       => $f->getPathname(),
            'size'       => $f->getSize(),
            'cached_at'  => date('Y-m-d H:i:s', $f->getMTime()),
            'age_hours'  => round((time() - $f->getMTime()) / 3600, 1),
        );
    }
}

$total_cached = count($cached_urls);
fpc_log("Gefunden: {$total_cached} gecachte URLs");

// Limitieren falls zu viele
if ($total_cached > $max_urls) {
    shuffle($cached_urls);
    $cached_urls = array_slice($cached_urls, 0, $max_urls);
    fpc_log("Limitiert auf {$max_urls} URLs (Zufallsauswahl)");
}

// Nach Pfad sortieren
usort($cached_urls, function($a, $b) { return strcmp($a['path'], $b['path']); });

// ============================================================
// 2. SSL-ZERTIFIKAT PRUEFEN
// ============================================================
$ssl_info = fpc_check_ssl($shop_url);
fpc_log("SSL: " . ($ssl_info['valid'] ? 'OK' : 'FEHLER') . " | Ablauf: " . $ssl_info['expires']);

// ============================================================
// 3. URLS TESTEN
// ============================================================
$results = array();
$stats = array(
    'total'       => 0,
    'ok'          => 0,
    'errors'      => 0,
    'redirects'   => 0,
    'hits'        => 0,
    'misses'      => 0,
    'stale'       => 0,  // Cache aelter als 24h
    'invalid_html'=> 0,
    'slow'        => 0,  // TTFB > 2000ms
    'ttfb_sum'    => 0,
    'ttfb_min'    => PHP_INT_MAX,
    'ttfb_max'    => 0,
    'size_sum'    => 0,
    'http_codes'  => array(),
    'categories'  => array(),
    'errors_list' => array(),
    'warnings_list' => array(),
    'slow_urls'   => array(),
    'redirect_urls' => array(),
);

$batch_size = 5;
$batches = array_chunk($cached_urls, $batch_size);

foreach ($batches as $batch_idx => $batch) {
    // Multi-cURL fuer parallele Requests
    $mh = curl_multi_init();
    $handles = array();

    foreach ($batch as $idx => $url_info) {
        $ch = curl_init($shop_url . $url_info['path']);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_ENCODING       => '',
        ));
        curl_multi_add_handle($mh, $ch);
        $handles[] = array('ch' => $ch, 'info' => $url_info);
    }

    // Ausfuehren
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    // Ergebnisse auswerten
    foreach ($handles as $h) {
        $ch = $h['ch'];
        $url_info = $h['info'];
        $response = curl_multi_getcontent($ch);
        $curl_info = curl_getinfo($ch);
        $curl_error = curl_error($ch);

        $code = $curl_info['http_code'];
        $ttfb = round($curl_info['starttransfer_time'] * 1000);
        $total_time = round($curl_info['total_time'] * 1000);
        $header_size = $curl_info['header_size'];
        $redirect_url = $curl_info['redirect_url'];

        // FPC-Header extrahieren
        $headers_raw = substr($response, 0, $header_size);
        $fpc_status = 'NONE';
        if (preg_match('/X-FPC-Cache:\s*(\S+)/i', $headers_raw, $hm)) {
            $fpc_status = strtoupper(trim($hm[1]));
        }

        // Kategorie aus Pfad
        $path_parts = explode('/', trim($url_info['path'], '/'));
        $category = !empty($path_parts[0]) ? $path_parts[0] : 'startseite';

        // HTML-Body pruefen
        $body = substr($response, $header_size);
        $has_body_tag = stripos($body, '<body') !== false;
        $has_fpc_marker = strpos($body, 'FPC-VALID') !== false;
        $html_valid = ($has_body_tag && strlen($body) > 1000);

        // Severity bestimmen
        $severity = 'ok';
        $issues = array();

        if ($code === 0) {
            $severity = 'critical';
            $issues[] = 'Timeout/Nicht erreichbar: ' . $curl_error;
        } elseif ($code >= 500) {
            $severity = 'critical';
            $issues[] = 'Server-Fehler HTTP ' . $code;
        } elseif ($code >= 400) {
            $severity = 'error';
            $issues[] = 'Client-Fehler HTTP ' . $code;
        } elseif ($code >= 300) {
            $severity = 'warning';
            $issues[] = 'Redirect ' . $code . ' → ' . $redirect_url;
            $stats['redirects']++;
            $stats['redirect_urls'][] = array(
                'url' => $url_info['path'],
                'code' => $code,
                'target' => $redirect_url,
            );
        }

        if ($code === 200 && !$html_valid) {
            $severity = max_severity($severity, 'warning');
            $issues[] = 'HTML ungueltig (Groesse: ' . strlen($body) . ' Bytes, Body-Tag: ' . ($has_body_tag ? 'Ja' : 'Nein') . ')';
            $stats['invalid_html']++;
        }

        if ($code === 200 && !$has_fpc_marker) {
            $issues[] = 'FPC-VALID Marker fehlt im HTML';
        }

        if ($ttfb > 2000) {
            $severity = max_severity($severity, 'warning');
            $issues[] = 'Langsam: TTFB ' . $ttfb . 'ms';
            $stats['slow']++;
            $stats['slow_urls'][] = array('url' => $url_info['path'], 'ttfb' => $ttfb);
        }

        if ($url_info['age_hours'] > 24) {
            $stats['stale']++;
            if ($url_info['age_hours'] > 48) {
                $issues[] = 'Cache sehr alt: ' . $url_info['age_hours'] . 'h';
            }
        }

        // Statistiken aktualisieren
        $stats['total']++;
        if ($code >= 200 && $code < 300) $stats['ok']++;
        if ($code >= 400 || $code === 0) $stats['errors']++;
        if ($fpc_status === 'HIT') $stats['hits']++;
        else $stats['misses']++;
        $stats['ttfb_sum'] += $ttfb;
        if ($ttfb < $stats['ttfb_min'] && $ttfb > 0) $stats['ttfb_min'] = $ttfb;
        if ($ttfb > $stats['ttfb_max']) $stats['ttfb_max'] = $ttfb;
        $stats['size_sum'] += $url_info['size'];

        if (!isset($stats['http_codes'][$code])) $stats['http_codes'][$code] = 0;
        $stats['http_codes'][$code]++;

        if (!isset($stats['categories'][$category])) {
            $stats['categories'][$category] = array('total' => 0, 'ok' => 0, 'errors' => 0, 'hits' => 0);
        }
        $stats['categories'][$category]['total']++;
        if ($code >= 200 && $code < 300) $stats['categories'][$category]['ok']++;
        if ($code >= 400 || $code === 0) $stats['categories'][$category]['errors']++;
        if ($fpc_status === 'HIT') $stats['categories'][$category]['hits']++;

        if ($severity === 'critical' || $severity === 'error') {
            $stats['errors_list'][] = array(
                'url'      => $url_info['path'],
                'severity' => $severity,
                'http'     => $code,
                'issues'   => $issues,
            );
        } elseif ($severity === 'warning') {
            $stats['warnings_list'][] = array(
                'url'      => $url_info['path'],
                'severity' => $severity,
                'http'     => $code,
                'issues'   => $issues,
            );
        }

        $results[] = array(
            'path'        => $url_info['path'],
            'category'    => $category,
            'http'        => $code,
            'fpc'         => $fpc_status,
            'ttfb'        => $ttfb,
            'total_time'  => $total_time,
            'size'        => $url_info['size'],
            'age_hours'   => $url_info['age_hours'],
            'cached_at'   => $url_info['cached_at'],
            'severity'    => $severity,
            'issues'      => $issues,
            'html_valid'  => $html_valid,
            'redirect'    => $redirect_url ?: null,
        );

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    // Fortschritt loggen
    $done = ($batch_idx + 1) * $batch_size;
    if ($done % 25 === 0 || $done >= count($cached_urls)) {
        fpc_log("Fortschritt: {$done}/" . count($cached_urls) . " URLs getestet");
    }
}

// ============================================================
// 4. ZUSAMMENFASSUNG BERECHNEN
// ============================================================
$duration = round(microtime(true) - $start_time, 1);
$hit_rate = $stats['total'] > 0 ? round(($stats['hits'] / $stats['total']) * 100, 1) : 0;
$avg_ttfb = $stats['total'] > 0 ? round($stats['ttfb_sum'] / $stats['total']) : 0;
$error_rate = $stats['total'] > 0 ? round(($stats['errors'] / $stats['total']) * 100, 1) : 0;

// Health-Score berechnen (0-100)
$health_score = 100;
$health_score -= $error_rate * 2;                          // Fehler stark bestrafen
$health_score -= max(0, (100 - $hit_rate)) * 0.3;         // Niedrige HIT-Rate
$health_score -= min(20, $stats['redirects'] * 2);         // Redirects
$health_score -= min(10, $stats['slow'] * 1);              // Langsame URLs
$health_score -= min(10, $stats['invalid_html'] * 2);      // Ungueltige HTML
$health_score -= min(10, max(0, ($stats['stale'] / max(1, $stats['total'])) * 20)); // Veraltete Seiten
if (!$ssl_info['valid']) $health_score -= 20;              // SSL-Problem
$health_score = max(0, min(100, round($health_score)));

// Health-Grade
$health_grade = 'A';
if ($health_score < 90) $health_grade = 'B';
if ($health_score < 75) $health_grade = 'C';
if ($health_score < 60) $health_grade = 'D';
if ($health_score < 40) $health_grade = 'F';

// Slow URLs sortieren (langsamste zuerst)
usort($stats['slow_urls'], function($a, $b) { return $b['ttfb'] - $a['ttfb']; });
$stats['slow_urls'] = array_slice($stats['slow_urls'], 0, 20);

// Errors sortieren (kritischste zuerst)
usort($stats['errors_list'], function($a, $b) {
    $order = array('critical' => 0, 'error' => 1, 'warning' => 2);
    return ($order[$a['severity']] ?? 3) - ($order[$b['severity']] ?? 3);
});

$summary = array(
    'timestamp'      => date('Y-m-d H:i:s'),
    'duration_sec'   => $duration,
    'total_cached'   => $total_cached,
    'tested'         => $stats['total'],
    'ok'             => $stats['ok'],
    'errors'         => $stats['errors'],
    'redirects'      => $stats['redirects'],
    'hits'           => $stats['hits'],
    'misses'         => $stats['misses'],
    'hit_rate'       => $hit_rate,
    'error_rate'     => $error_rate,
    'avg_ttfb'       => $avg_ttfb,
    'ttfb_min'       => $stats['ttfb_min'] < PHP_INT_MAX ? $stats['ttfb_min'] : 0,
    'ttfb_max'       => $stats['ttfb_max'],
    'stale_count'    => $stats['stale'],
    'slow_count'     => $stats['slow'],
    'invalid_html'   => $stats['invalid_html'],
    'health_score'   => $health_score,
    'health_grade'   => $health_grade,
    'ssl'            => $ssl_info,
    'http_codes'     => $stats['http_codes'],
    'categories'     => $stats['categories'],
    'errors_list'    => $stats['errors_list'],
    'warnings_list'  => array_slice($stats['warnings_list'], 0, 50),
    'slow_urls'      => $stats['slow_urls'],
    'redirect_urls'  => array_slice($stats['redirect_urls'], 0, 30),
);

// ============================================================
// 5. ERGEBNISSE SPEICHERN
// ============================================================
$existing = array('runs' => array());
if (is_file($output_file)) {
    $existing = @json_decode(file_get_contents($output_file), true);
    if (!is_array($existing)) $existing = array('runs' => array());
}

// Detaillierte Ergebnisse nur fuer den letzten Lauf speichern (Platzsparen)
$run_data = array(
    'summary' => $summary,
    'results' => $results,
);

$existing['latest'] = $run_data;
$existing['runs'][] = $summary;

// Historie begrenzen
if (count($existing['runs']) > $max_history) {
    $existing['runs'] = array_slice($existing['runs'], -$max_history);
}

$bytes = @file_put_contents($output_file, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ============================================================
// 6. LOG-ZUSAMMENFASSUNG
// ============================================================
fpc_log("=== ERGEBNIS ===");
fpc_log("Health-Score: {$health_score}/100 (Grade: {$health_grade})");
fpc_log("Getestet: {$stats['total']} URLs in {$duration}s");
fpc_log("OK: {$stats['ok']} | Fehler: {$stats['errors']} | Redirects: {$stats['redirects']}");
fpc_log("HIT-Rate: {$hit_rate}% ({$stats['hits']} HIT / {$stats['misses']} MISS)");
fpc_log("TTFB: Ø {$avg_ttfb}ms (Min: " . ($stats['ttfb_min'] < PHP_INT_MAX ? $stats['ttfb_min'] : 0) . "ms / Max: {$stats['ttfb_max']}ms)");
fpc_log("Veraltet (>24h): {$stats['stale']} | Langsam (>2s): {$stats['slow']} | HTML ungueltig: {$stats['invalid_html']}");
fpc_log("SSL: " . ($ssl_info['valid'] ? 'OK' : 'FEHLER') . " (Ablauf: {$ssl_info['expires']})");
fpc_log("Gespeichert: " . fpc_format_bytes($bytes) . " → " . $output_file);

if (!empty($stats['errors_list'])) {
    fpc_log("--- FEHLER ---");
    foreach (array_slice($stats['errors_list'], 0, 10) as $err) {
        fpc_log("  [{$err['severity']}] {$err['url']} (HTTP {$err['http']}): " . implode(', ', $err['issues']));
    }
}

fpc_log("=== Health-Check beendet: " . date('Y-m-d H:i:s') . " ===\n");

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function fpc_check_ssl($url) {
    $parsed = parse_url($url);
    $host = $parsed['host'];
    $port = isset($parsed['port']) ? $parsed['port'] : 443;

    $result = array('valid' => false, 'expires' => 'Unbekannt', 'issuer' => '', 'days_left' => 0);

    $ctx = stream_context_create(array('ssl' => array(
        'capture_peer_cert' => true,
        'verify_peer' => false,
    )));

    $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$client) return $result;

    $params = stream_context_get_params($client);
    if (isset($params['options']['ssl']['peer_certificate'])) {
        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
        if ($cert) {
            $expires = $cert['validTo_time_t'];
            $result['valid'] = ($expires > time());
            $result['expires'] = date('Y-m-d', $expires);
            $result['days_left'] = max(0, round(($expires - time()) / 86400));
            $result['issuer'] = isset($cert['issuer']['O']) ? $cert['issuer']['O'] : '';
        }
    }
    fclose($client);
    return $result;
}

function max_severity($current, $new) {
    $order = array('ok' => 0, 'warning' => 1, 'error' => 2, 'critical' => 3);
    $c = isset($order[$current]) ? $order[$current] : 0;
    $n = isset($order[$new]) ? $order[$new] : 0;
    return $n > $c ? $new : $current;
}

function fpc_format_bytes($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' Bytes';
}

function fpc_log($msg) {
    global $cache_dir;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    @file_put_contents($cache_dir . 'healthcheck_cron.log', $line, FILE_APPEND);
}
?>
