<?php
/**
 * Mr. Hanf FPC - SEO Tab v11.0 AJAX Handler
 *
 * Routet alle AJAX-Anfragen vom SEO Tab Frontend an die entsprechenden
 * Backend-Klassen. Wird von fpc_dashboard.php eingebunden.
 *
 * @version   11.0.0
 * @date      2026-03-30
 */

// Sicherstellen dass die Klassen geladen sind
$seo_ajax_classes_loaded = false;

function seo_ensure_classes($settings) {
    global $seo_ajax_classes_loaded;
    if ($seo_ajax_classes_loaded) return;

    $base = dirname(__FILE__) . '/';

    // Bestehende Klassen
    if (file_exists($base . 'fpc_seo.php')) require_once $base . 'fpc_seo.php';
    if (file_exists($base . 'fpc_ga4.php')) require_once $base . 'fpc_ga4.php';
    if (file_exists($base . 'fpc_sistrix.php')) require_once $base . 'fpc_sistrix.php';
    if (file_exists($base . 'fpc_ai.php')) require_once $base . 'fpc_ai.php';

    // Neue v11.0 Klassen
    if (file_exists($base . 'fpc_seo_schema.php')) require_once $base . 'fpc_seo_schema.php';
    if (file_exists($base . 'fpc_seo_cwv.php')) require_once $base . 'fpc_seo_cwv.php';
    if (file_exists($base . 'fpc_seo_indexing.php')) require_once $base . 'fpc_seo_indexing.php';
    if (file_exists($base . 'fpc_seo_llms.php')) require_once $base . 'fpc_seo_llms.php';
    if (file_exists($base . 'fpc_seo_extended.php')) require_once $base . 'fpc_seo_extended.php';

    $seo_ajax_classes_loaded = true;
}

/**
 * Haupt-Router fuer SEO AJAX Aktionen
 * Wird aus fpc_dashboard.php aufgerufen wenn action mit 'seo_' beginnt
 *
 * @param string $action Die AJAX-Aktion
 * @param array $settings FPC Einstellungen (API Keys etc.)
 * @param array $post POST-Daten
 */
function handle_seo_ajax($action, $settings, $post = []) {
    seo_ensure_classes($settings);

    $domain = $settings['domain'] ?? 'mr-hanf.de';
    $webroot = $settings['webroot'] ?? '/var/www/html/';

    // JSON Response Header
    header('Content-Type: application/json; charset=utf-8');

    try {
        switch ($action) {

            // ============================================================
            // DASHBOARD OVERVIEW
            // ============================================================
            case 'seo_dashboard_overview':
                echo json_encode(seo_get_dashboard_overview($settings, $domain));
                break;

            // ============================================================
            // HREFLANG
            // ============================================================
            case 'seo_hreflang_audit':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                $languages = ['de', 'en', 'fr', 'es'];
                $result = $ext->hreflangFullAudit($languages);
                echo json_encode($result);
                break;

            case 'seo_hreflang_scan':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                $url = $post['url'] ?? '';
                $result = $ext->hreflangScanUrl($url, ['de', 'en', 'fr', 'es']);
                echo json_encode($result);
                break;

            // ============================================================
            // ROBOTS.TXT
            // ============================================================
            case 'seo_robotstxt_get':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                echo json_encode($ext->robotsTxtGet());
                break;

            case 'seo_robotstxt_save':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                $content = $post['content'] ?? '';
                echo json_encode($ext->robotsTxtSave($content));
                break;

            case 'seo_robotstxt_validate':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                $content = $post['content'] ?? '';
                echo json_encode($ext->robotsTxtValidate($content));
                break;

            // ============================================================
            // SITEMAP
            // ============================================================
            case 'seo_sitemap_validate':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                echo json_encode($ext->sitemapValidate());
                break;

            // ============================================================
            // SCHEMA.ORG
            // ============================================================
            case 'seo_schema_stats':
                $schema = new FPC_SEO_Schema($domain);
                $stats = $schema->getStats();
                echo json_encode($stats);
                break;

            case 'seo_schema_scan':
                $schema = new FPC_SEO_Schema($domain);
                $full = isset($post['full']) && $post['full'];
                if ($full) {
                    $result = $schema->fullScan();
                } else {
                    $result = ['ok' => false, 'msg' => 'Bitte full=true angeben'];
                }
                echo json_encode($result);
                break;

            case 'seo_schema_scan_url':
                $schema = new FPC_SEO_Schema($domain);
                $url = $post['url'] ?? '';
                $result = $schema->scanUrl($url);
                echo json_encode($result);
                break;

            // ============================================================
            // CORE WEB VITALS
            // ============================================================
            case 'seo_cwv_results':
                $api_key = $settings['google_pagespeed_api_key'] ?? '';
                $cwv = new FPC_SEO_CWV($domain, $api_key);
                echo json_encode($cwv->getLatestResults());
                break;

            case 'seo_cwv_test':
                $api_key = $settings['google_pagespeed_api_key'] ?? '';
                $cwv = new FPC_SEO_CWV($domain, $api_key);
                if (isset($post['batch']) && $post['batch']) {
                    $limit = intval($post['limit'] ?? 20);
                    $result = $cwv->batchTest($limit);
                } else {
                    $url = $post['url'] ?? '';
                    $result = $cwv->testUrl($url);
                }
                echo json_encode($result);
                break;

            // ============================================================
            // INDEXING API
            // ============================================================
            case 'seo_indexing_quota':
                $sa_path = $settings['gsc_service_account_path'] ?? '';
                $idx = new FPC_SEO_Indexing($sa_path);
                echo json_encode($idx->getQuota());
                break;

            case 'seo_indexing_log':
                $sa_path = $settings['gsc_service_account_path'] ?? '';
                $idx = new FPC_SEO_Indexing($sa_path);
                $limit = intval($post['limit'] ?? 20);
                echo json_encode($idx->getLog($limit));
                break;

            case 'seo_indexing_submit':
                $sa_path = $settings['gsc_service_account_path'] ?? '';
                $idx = new FPC_SEO_Indexing($sa_path);
                $url = $post['url'] ?? '';
                $type = $post['type'] ?? 'URL_UPDATED';
                $result = $idx->submitUrl($url, $type);
                echo json_encode($result);
                break;

            case 'seo_indexing_submit_batch':
                $sa_path = $settings['gsc_service_account_path'] ?? '';
                $idx = new FPC_SEO_Indexing($sa_path);
                $urls = json_decode($post['urls'] ?? '[]', true);
                $type = $post['type'] ?? 'URL_UPDATED';
                $result = $idx->submitBatch($urls, $type);
                echo json_encode($result);
                break;

            // ============================================================
            // KEYWORD MONITOR
            // ============================================================
            case 'seo_keyword_monitor':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                echo json_encode($ext->keywordMonitorGet());
                break;

            case 'seo_keyword_refresh':
                // Nutzt GSC API fuer aktuelle Keyword-Daten
                $gsc_config = [
                    'service_account_path' => $settings['gsc_service_account_path'] ?? '',
                    'property' => $settings['gsc_property'] ?? "sc-domain:{$domain}",
                ];
                $ext = new FPC_SEO_Extended($domain, $webroot);
                $result = $ext->keywordMonitorRefresh($gsc_config);
                echo json_encode($result);
                break;

            // ============================================================
            // KI-ANALYSE (bestehend, erweitert)
            // ============================================================
            case 'seo_ai_analysis':
                // Delegiert an bestehende fpc_ai.php
                if (class_exists('FPC_AI')) {
                    $ai = new FPC_AI($settings);
                    $result = $ai->runSeoAnalysis($post);
                    echo json_encode($result);
                } else {
                    echo json_encode(['ok' => false, 'msg' => 'KI-Modul nicht verfügbar']);
                }
                break;

            // ============================================================
            // LLMS.TXT
            // ============================================================
            case 'seo_llms_get':
                $llms = new FPC_SEO_LLMS($domain, $webroot);
                echo json_encode($llms->getLlmsTxt());
                break;

            case 'seo_llms_save':
                $llms = new FPC_SEO_LLMS($domain, $webroot);
                $content = $post['content'] ?? '';
                echo json_encode($llms->saveLlmsTxt($content));
                break;

            case 'seo_llms_generate':
                $llms = new FPC_SEO_LLMS($domain, $webroot);
                $shop_data = seo_get_shop_data_for_llms($settings);
                echo json_encode($llms->generateLlmsTxt($shop_data));
                break;

            // ============================================================
            // AI-CRAWLER
            // ============================================================
            case 'seo_aicrawler_status':
                $llms = new FPC_SEO_LLMS($domain, $webroot);
                echo json_encode($llms->getAiCrawlerStatus());
                break;

            case 'seo_aicrawler_set':
                $llms = new FPC_SEO_LLMS($domain, $webroot);
                $bot = $post['bot'] ?? '';
                $allowed = ($post['action'] ?? 'allow') === 'allow';
                echo json_encode($llms->setAiCrawlerRule($bot, $allowed));
                break;

            case 'seo_aicrawler_recommended':
                $llms = new FPC_SEO_LLMS($domain, $webroot);
                echo json_encode($llms->applyRecommendedConfig());
                break;

            case 'seo_geo_tips':
                $llms = new FPC_SEO_LLMS($domain, $webroot);
                echo json_encode($llms->getGeoTips());
                break;

            // ============================================================
            // META-TAGS
            // ============================================================
            case 'seo_meta_audit':
            case 'seo_meta_audit_run':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                $db = seo_get_db_connection($settings);
                if ($db) {
                    echo json_encode($ext->metaTagAudit($db));
                } else {
                    echo json_encode(['ok' => false, 'msg' => 'Keine DB-Verbindung']);
                }
                break;

            // ============================================================
            // CONTENT AUDIT
            // ============================================================
            case 'seo_content_audit':
            case 'seo_content_audit_run':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                $db = seo_get_db_connection($settings);
                if ($db) {
                    echo json_encode($ext->contentAudit($db));
                } else {
                    echo json_encode(['ok' => false, 'msg' => 'Keine DB-Verbindung']);
                }
                break;

            // ============================================================
            // INTERNAL LINKS
            // ============================================================
            case 'seo_internal_links':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                echo json_encode($ext->internalLinksGet());
                break;

            case 'seo_internal_links_run':
                $ext = new FPC_SEO_Extended($domain, $webroot);
                echo json_encode($ext->internalLinksCrawl());
                break;

            // ============================================================
            // DEFAULT
            // ============================================================
            default:
                echo json_encode(['ok' => false, 'msg' => "Unbekannte SEO-Aktion: {$action}"]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'ok' => false,
            'msg' => 'Fehler: ' . $e->getMessage(),
            'trace' => (defined('FPC_DEBUG') && FPC_DEBUG) ? $e->getTraceAsString() : null,
        ]);
    }

    exit;
}

// ============================================================
// HELPER FUNKTIONEN
// ============================================================

/**
 * Dashboard Overview Daten sammeln
 */
function seo_get_dashboard_overview($settings, $domain) {
    $data = [
        'health_score' => 0,
        'redirect_count' => 0,
        'error_404_count' => 0,
        'scan_issues' => 0,
        'sistrix_visibility' => '—',
        'si_trend' => 'neutral',
        'si_change' => '',
        'redirect_hits_today' => 0,
        'new_404_today' => 0,
        'last_scan' => '—',
        'schema_coverage' => 0,
    ];

    // Redirects zaehlen
    $redirect_file = dirname(__FILE__) . '/data/seo/redirects.json';
    if (file_exists($redirect_file)) {
        $redirects = json_decode(file_get_contents($redirect_file), true);
        if (is_array($redirects)) {
            $data['redirect_count'] = count($redirects);
        }
    }

    // 404-Fehler zaehlen
    $log_404_file = dirname(__FILE__) . '/data/seo/404_log.json';
    if (file_exists($log_404_file)) {
        $log404 = json_decode(file_get_contents($log_404_file), true);
        if (is_array($log404)) {
            $data['error_404_count'] = count($log404);
            // Neue heute
            $today = date('Y-m-d');
            $new_today = 0;
            foreach ($log404 as $entry) {
                if (isset($entry['first_seen']) && strpos($entry['first_seen'], $today) === 0) {
                    $new_today++;
                }
            }
            $data['new_404_today'] = $new_today;
        }
    }

    // Scan-Probleme
    $scan_file = dirname(__FILE__) . '/data/seo/scan_results.json';
    if (file_exists($scan_file)) {
        $scan = json_decode(file_get_contents($scan_file), true);
        if (is_array($scan)) {
            $issues = 0;
            foreach ($scan as $result) {
                if (isset($result['issues']) && is_array($result['issues'])) {
                    $issues += count($result['issues']);
                }
            }
            $data['scan_issues'] = $issues;
            $data['last_scan'] = $scan['timestamp'] ?? '—';
        }
    }

    // Sistrix Sichtbarkeit
    if (!empty($settings['sistrix_api_key'])) {
        try {
            $sistrix = new FPC_Sistrix($settings['sistrix_api_key'], $domain);
            $vis = $sistrix->getCurrentVisibility();
            if ($vis && !isset($vis['error'])) {
                $val = $vis['answer'][0]['value'] ?? null;
                if ($val !== null) {
                    $data['sistrix_visibility'] = number_format($val, 2);
                }
            }
        } catch (Exception $e) {
            // Sistrix nicht verfuegbar
        }
    }

    // Schema.org Coverage
    $schema_file = dirname(__FILE__) . '/cache/fpc/schema/stats.json';
    if (file_exists($schema_file)) {
        $schema_stats = json_decode(file_get_contents($schema_file), true);
        if (isset($schema_stats['overall_coverage'])) {
            $data['schema_coverage'] = $schema_stats['overall_coverage'];
        }
    }

    // Health Score berechnen
    $score = 50; // Basis
    if ($data['error_404_count'] < 10) $score += 10;
    elseif ($data['error_404_count'] < 50) $score += 5;
    if ($data['redirect_count'] > 0) $score += 5; // Redirects gepflegt
    if ($data['schema_coverage'] > 50) $score += 10;
    if ($data['scan_issues'] < 10) $score += 10;
    elseif ($data['scan_issues'] < 50) $score += 5;
    if ($data['sistrix_visibility'] !== '—') $score += 5;
    // llms.txt vorhanden?
    $webroot = $settings['webroot'] ?? '/var/www/html/';
    if (file_exists($webroot . 'llms.txt')) $score += 5;
    // robots.txt vorhanden?
    if (file_exists($webroot . 'robots.txt')) $score += 5;

    $data['health_score'] = min(100, max(0, $score));

    return $data;
}

/**
 * DB-Verbindung herstellen
 */
function seo_get_db_connection($settings) {
    $host = $settings['db_host'] ?? 'localhost';
    $user = $settings['db_user'] ?? '';
    $pass = $settings['db_pass'] ?? '';
    $name = $settings['db_name'] ?? '';

    if (empty($user) || empty($name)) {
        // Versuche configure.php einzulesen
        $configure = $settings['webroot'] . 'includes/configure.php';
        if (file_exists($configure)) {
            $content = file_get_contents($configure);
            if (preg_match("/define\('DB_SERVER',\s*'([^']+)'\)/", $content, $m)) $host = $m[1];
            if (preg_match("/define\('DB_SERVER_USERNAME',\s*'([^']+)'\)/", $content, $m)) $user = $m[1];
            if (preg_match("/define\('DB_SERVER_PASSWORD',\s*'([^']+)'\)/", $content, $m)) $pass = $m[1];
            if (preg_match("/define\('DB_DATABASE',\s*'([^']+)'\)/", $content, $m)) $name = $m[1];
        }
    }

    if (empty($user) || empty($name)) return null;

    try {
        $db = new mysqli($host, $user, $pass, $name);
        if ($db->connect_error) return null;
        $db->set_charset('utf8mb4');
        return $db;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Shop-Daten fuer llms.txt Generierung sammeln
 */
function seo_get_shop_data_for_llms($settings) {
    $data = [
        'shop_name' => 'Mr. Hanf',
        'description' => 'Europas führender Online-Shop für Hanfsamen und Cannabis-Samen mit über 20 Jahren Erfahrung.',
        'url' => 'https://mr-hanf.de',
        'languages' => ['de', 'en', 'fr', 'es'],
        'categories' => [],
        'top_products' => [],
        'content_pages' => [],
    ];

    // Kategorien aus DB laden
    $db = seo_get_db_connection($settings);
    if ($db) {
        // Top-Kategorien
        $result = $db->query("SELECT c.categories_id, cd.categories_name
            FROM categories c
            JOIN categories_description cd ON c.categories_id = cd.categories_id
            WHERE cd.language_id = 1 AND c.parent_id = 0 AND c.categories_status = 1
            ORDER BY c.sort_order LIMIT 20");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data['categories'][] = $row['categories_name'];
            }
        }

        // Top-Produkte (nach Bestellungen)
        $result = $db->query("SELECT pd.products_name
            FROM products p
            JOIN products_description pd ON p.products_id = pd.products_id
            WHERE pd.language_id = 1 AND p.products_status = 1
            ORDER BY p.products_ordered DESC LIMIT 20");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data['top_products'][] = $row['products_name'];
            }
        }

        $db->close();
    }

    return $data;
}
