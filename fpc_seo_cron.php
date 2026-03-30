<?php
/**
 * Mr. Hanf FPC - SEO Cronjob Runner v11.0
 *
 * Fuehrt automatische SEO-Scans aus:
 * - Schema.org Scan (alle 14 Tage)
 * - Core Web Vitals Test (alle 14 Tage)
 * - hreflang Audit (alle 14 Tage)
 * - llms.txt Auto-Update (alle 14 Tage)
 * - Keyword Monitor Refresh (taeglich)
 *
 * Crontab-Eintrag (taeglich um 03:00):
 *   0 3 * * * php /pfad/zu/fpc_seo_cron.php >> /pfad/zu/logs/seo_cron.log 2>&1
 *
 * @version   11.0.0
 * @date      2026-03-30
 */

// Konfiguration laden
$base = dirname(__FILE__) . '/';
$settings_file = $base . 'data/fpc/settings.json';

if (!file_exists($settings_file)) {
    echo date('Y-m-d H:i:s') . " [ERROR] Settings-Datei nicht gefunden: {$settings_file}\n";
    exit(1);
}

$settings = json_decode(file_get_contents($settings_file), true);
if (!is_array($settings)) {
    echo date('Y-m-d H:i:s') . " [ERROR] Settings-Datei ungueltig\n";
    exit(1);
}

$domain = $settings['domain'] ?? 'mr-hanf.de';
$webroot = $settings['webroot'] ?? '/var/www/html/';

// Klassen laden
require_once $base . 'fpc_seo_schema.php';
require_once $base . 'fpc_seo_cwv.php';
require_once $base . 'fpc_seo_llms.php';
require_once $base . 'fpc_seo_extended.php';

// Letzte Ausfuehrungszeiten laden
$cron_state_file = $base . 'data/fpc/seo_cron_state.json';
$cron_state = [];
if (file_exists($cron_state_file)) {
    $cron_state = json_decode(file_get_contents($cron_state_file), true) ?: [];
}

$now = time();
$today = date('Y-m-d');

echo date('Y-m-d H:i:s') . " [INFO] SEO Cronjob gestartet\n";

// ============================================================
// 1. SCHEMA.ORG SCAN
// ============================================================
$schema_interval = intval($settings['cron_schema_interval'] ?? 14) * 86400;
$schema_last = $cron_state['schema_scan'] ?? 0;

if (($now - $schema_last) >= $schema_interval) {
    echo date('Y-m-d H:i:s') . " [RUN] Schema.org Voll-Scan...\n";
    try {
        $schema = new FPC_SEO_Schema($domain);
        $result = $schema->fullScan();
        echo date('Y-m-d H:i:s') . " [OK] Schema.org Scan: " . ($result['msg'] ?? 'abgeschlossen') . "\n";
        $cron_state['schema_scan'] = $now;
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " [ERROR] Schema.org Scan: " . $e->getMessage() . "\n";
    }
} else {
    $next = date('Y-m-d', $schema_last + $schema_interval);
    echo date('Y-m-d H:i:s') . " [SKIP] Schema.org Scan (naechster: {$next})\n";
}

// ============================================================
// 2. CORE WEB VITALS
// ============================================================
$cwv_interval = intval($settings['cron_cwv_interval'] ?? 14) * 86400;
$cwv_last = $cron_state['cwv_test'] ?? 0;
$cwv_api_key = $settings['google_pagespeed_api_key'] ?? '';

if (($now - $cwv_last) >= $cwv_interval && !empty($cwv_api_key)) {
    echo date('Y-m-d H:i:s') . " [RUN] Core Web Vitals Batch-Test...\n";
    try {
        $cwv = new FPC_SEO_CWV($domain, $cwv_api_key);
        $result = $cwv->batchTest(20);
        echo date('Y-m-d H:i:s') . " [OK] CWV Test: " . ($result['msg'] ?? 'abgeschlossen') . "\n";
        $cron_state['cwv_test'] = $now;
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " [ERROR] CWV Test: " . $e->getMessage() . "\n";
    }
} elseif (empty($cwv_api_key)) {
    echo date('Y-m-d H:i:s') . " [SKIP] CWV Test (kein API Key konfiguriert)\n";
} else {
    $next = date('Y-m-d', $cwv_last + $cwv_interval);
    echo date('Y-m-d H:i:s') . " [SKIP] CWV Test (naechster: {$next})\n";
}

// ============================================================
// 3. HREFLANG AUDIT
// ============================================================
$hreflang_interval = intval($settings['cron_hreflang_interval'] ?? 14) * 86400;
$hreflang_last = $cron_state['hreflang_audit'] ?? 0;

if (($now - $hreflang_last) >= $hreflang_interval) {
    echo date('Y-m-d H:i:s') . " [RUN] hreflang Audit...\n";
    try {
        $ext = new FPC_SEO_Extended($domain, $webroot);
        $languages = ['de', 'en', 'fr', 'es'];
        $result = $ext->hreflangFullAudit($languages);
        echo date('Y-m-d H:i:s') . " [OK] hreflang Audit: " . ($result['stats']['total_scanned'] ?? 0) . " URLs gescannt\n";
        $cron_state['hreflang_audit'] = $now;
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " [ERROR] hreflang Audit: " . $e->getMessage() . "\n";
    }
} else {
    $next = date('Y-m-d', $hreflang_last + $hreflang_interval);
    echo date('Y-m-d H:i:s') . " [SKIP] hreflang Audit (naechster: {$next})\n";
}

// ============================================================
// 4. LLMS.TXT AUTO-UPDATE
// ============================================================
$llms_interval = intval($settings['cron_llms_interval'] ?? 14) * 86400;
$llms_last = $cron_state['llms_update'] ?? 0;

if (($now - $llms_last) >= $llms_interval) {
    echo date('Y-m-d H:i:s') . " [RUN] llms.txt Auto-Update...\n";
    try {
        $llms = new FPC_SEO_LLMS($domain, $webroot);

        // Shop-Daten sammeln (vereinfacht fuer Cronjob)
        $shop_data = [
            'shop_name' => 'Mr. Hanf',
            'description' => 'Europas führender Online-Shop für Hanfsamen und Cannabis-Samen mit über 20 Jahren Erfahrung.',
            'url' => "https://{$domain}",
            'languages' => ['de', 'en', 'fr', 'es'],
            'categories' => [],
            'top_products' => [],
        ];

        $result = $llms->generateLlmsTxt($shop_data);
        if ($result && isset($result['content'])) {
            $llms->saveLlmsTxt($result['content']);
            echo date('Y-m-d H:i:s') . " [OK] llms.txt aktualisiert\n";
        }
        $cron_state['llms_update'] = $now;
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " [ERROR] llms.txt Update: " . $e->getMessage() . "\n";
    }
} else {
    $next = date('Y-m-d', $llms_last + $llms_interval);
    echo date('Y-m-d H:i:s') . " [SKIP] llms.txt Update (naechster: {$next})\n";
}

// ============================================================
// 5. KEYWORD MONITOR (taeglich)
// ============================================================
$kw_last_date = $cron_state['keyword_refresh_date'] ?? '';

if ($kw_last_date !== $today) {
    echo date('Y-m-d H:i:s') . " [RUN] Keyword Monitor Refresh...\n";
    try {
        $ext = new FPC_SEO_Extended($domain, $webroot);
        $gsc_config = [
            'service_account_path' => $settings['gsc_service_account_path'] ?? '',
            'property' => $settings['gsc_property'] ?? "sc-domain:{$domain}",
        ];
        $result = $ext->keywordMonitorRefresh($gsc_config);
        echo date('Y-m-d H:i:s') . " [OK] Keyword Monitor: " . ($result['msg'] ?? 'aktualisiert') . "\n";
        $cron_state['keyword_refresh_date'] = $today;
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " [ERROR] Keyword Monitor: " . $e->getMessage() . "\n";
    }
} else {
    echo date('Y-m-d H:i:s') . " [SKIP] Keyword Monitor (heute bereits aktualisiert)\n";
}

// ============================================================
// State speichern
// ============================================================
$state_dir = dirname($cron_state_file);
if (!is_dir($state_dir)) {
    @mkdir($state_dir, 0777, true);
}
file_put_contents($cron_state_file, json_encode($cron_state, JSON_PRETTY_PRINT));

echo date('Y-m-d H:i:s') . " [INFO] SEO Cronjob beendet\n";
echo "---\n";
