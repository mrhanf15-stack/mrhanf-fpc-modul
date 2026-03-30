<?php
/**
 * Mr. Hanf FPC - Schema.org Scanner & Audit v1.0.0
 *
 * Scannt Seiten auf Schema.org Structured Data (JSON-LD),
 * erstellt Audit-Reports mit prozentualer Abdeckung,
 * speichert Verlauf fuer Trend-Analyse.
 *
 * Features:
 *   - Einzelne URL scannen (JSON-LD extrahieren und validieren)
 *   - Batch-Scan (mehrere URLs)
 *   - Voll-Scan (alle Sitemap-URLs, fuer Cronjob)
 *   - Audit-Report mit Abdeckung pro Schema-Typ
 *   - Verlaufs-Tracking (History)
 *   - Empfehlungen pro Seitentyp
 *
 * @version   1.0.0
 * @date      2026-03-30
 */

class FpcSeoSchema {

    private $base_dir;
    private $cache_dir;
    private $audit_file;
    private $history_file;
    private $config_file;

    // Erwartete Schema-Typen pro Seitentyp
    private $expected_schemas = array(
        'product' => array('Product', 'BreadcrumbList', 'Offer', 'AggregateRating'),
        'category' => array('ItemList', 'BreadcrumbList'),
        'homepage' => array('Organization', 'WebSite', 'SearchAction'),
        'content' => array('Article', 'BreadcrumbList'),
        'faq' => array('FAQPage', 'BreadcrumbList'),
        'howto' => array('HowTo', 'BreadcrumbList'),
    );

    public function __construct($base_dir) {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->cache_dir = $this->base_dir . 'cache/fpc/seo/';
        $this->audit_file = $this->cache_dir . 'schema_audit.json';
        $this->history_file = $this->cache_dir . 'schema_history.json';
        $this->config_file = $this->cache_dir . 'schema_config.json';

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755, true);
        }
    }

    // ================================================================
    // EINZELNE URL SCANNEN
    // ================================================================

    /**
     * Scannt eine einzelne URL auf Schema.org JSON-LD
     * @param string $url Vollstaendige URL oder relativer Pfad
     * @return array Scan-Ergebnis mit gefundenen Schemas
     */
    public function scanUrl($url) {
        // URL normalisieren
        if (strpos($url, 'http') !== 0) {
            $url = 'https://mr-hanf.de' . (strpos($url, '/') === 0 ? '' : '/') . $url;
        }

        $result = array(
            'url' => $url,
            'timestamp' => date('Y-m-d H:i:s'),
            'http_status' => 0,
            'schemas_found' => array(),
            'schema_types' => array(),
            'has_jsonld' => false,
            'has_microdata' => false,
            'has_rdfa' => false,
            'issues' => array(),
            'recommendations' => array(),
            'page_type' => 'unknown',
            'score' => 0,
        );

        // HTTP Request
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWREDIRECTS => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'MrHanf-SEO-Scanner/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $html = curl_exec($ch);
        $result['http_status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$html || $result['http_status'] >= 400) {
            $result['issues'][] = 'HTTP Fehler: Status ' . $result['http_status'];
            return $result;
        }

        // Seitentyp erkennen
        $result['page_type'] = $this->detectPageType($url, $html);

        // JSON-LD extrahieren
        $jsonld_blocks = $this->extractJsonLd($html);
        if (!empty($jsonld_blocks)) {
            $result['has_jsonld'] = true;
            foreach ($jsonld_blocks as $block) {
                $parsed = @json_decode($block, true);
                if ($parsed) {
                    $types = $this->extractTypes($parsed);
                    $result['schema_types'] = array_merge($result['schema_types'], $types);
                    $result['schemas_found'][] = array(
                        'raw' => $block,
                        'parsed' => $parsed,
                        'types' => $types,
                        'valid_json' => true,
                    );
                    // Validierung pro Schema
                    $validation = $this->validateSchema($parsed, $result['page_type']);
                    if (!empty($validation['issues'])) {
                        $result['issues'] = array_merge($result['issues'], $validation['issues']);
                    }
                } else {
                    $result['schemas_found'][] = array(
                        'raw' => substr($block, 0, 500),
                        'parsed' => null,
                        'types' => array(),
                        'valid_json' => false,
                    );
                    $result['issues'][] = 'Ungültiges JSON-LD gefunden (JSON Parse Error)';
                }
            }
        }

        // Microdata prüfen
        if (preg_match('/itemscope|itemtype|itemprop/i', $html)) {
            $result['has_microdata'] = true;
        }

        // RDFa prüfen
        if (preg_match('/typeof=|property=.*content=/i', $html)) {
            $result['has_rdfa'] = true;
        }

        // Unique Types
        $result['schema_types'] = array_unique($result['schema_types']);

        // Empfehlungen basierend auf Seitentyp
        $result['recommendations'] = $this->getRecommendations($result);

        // Score berechnen
        $result['score'] = $this->calculateScore($result);

        return $result;
    }

    // ================================================================
    // BATCH & FULL SCAN
    // ================================================================

    /**
     * Batch-Scan mehrerer URLs
     */
    public function scanBatch($urls, $limit = 50) {
        $results = array();
        $urls = array_slice($urls, 0, $limit);

        foreach ($urls as $url) {
            $results[] = $this->scanUrl($url);
            usleep(200000); // 200ms Pause zwischen Requests
        }

        return $results;
    }

    /**
     * Voll-Scan aller Sitemap-URLs (fuer Cronjob)
     * Scannt max. 500 URLs pro Durchlauf
     */
    public function runFullScan($max_urls = 500) {
        $start_time = time();

        // Sitemap-URLs laden
        $urls = $this->getSitemapUrls();
        if (empty($urls)) {
            return array('ok' => false, 'msg' => 'Keine URLs in Sitemap gefunden');
        }

        // Sampling: Bei >500 URLs eine repraesentative Stichprobe nehmen
        if (count($urls) > $max_urls) {
            // Erste 50 (wichtigste), dann zufaellige Stichprobe
            $important = array_slice($urls, 0, 50);
            $remaining = array_slice($urls, 50);
            shuffle($remaining);
            $sampled = array_slice($remaining, 0, $max_urls - 50);
            $urls = array_merge($important, $sampled);
        }

        $results = array();
        $stats = array(
            'total_scanned' => 0,
            'with_jsonld' => 0,
            'with_microdata' => 0,
            'without_schema' => 0,
            'errors' => 0,
            'schema_type_counts' => array(),
            'page_type_counts' => array(),
            'avg_score' => 0,
            'scores' => array(),
        );

        foreach ($urls as $url) {
            $scan = $this->scanUrl($url);
            $results[] = $scan;
            $stats['total_scanned']++;

            if ($scan['has_jsonld']) $stats['with_jsonld']++;
            if ($scan['has_microdata']) $stats['with_microdata']++;
            if (!$scan['has_jsonld'] && !$scan['has_microdata'] && !$scan['has_rdfa']) {
                $stats['without_schema']++;
            }
            if ($scan['http_status'] >= 400) $stats['errors']++;

            foreach ($scan['schema_types'] as $type) {
                if (!isset($stats['schema_type_counts'][$type])) {
                    $stats['schema_type_counts'][$type] = 0;
                }
                $stats['schema_type_counts'][$type]++;
            }

            $pt = $scan['page_type'];
            if (!isset($stats['page_type_counts'][$pt])) {
                $stats['page_type_counts'][$pt] = array('total' => 0, 'with_schema' => 0, 'avg_score' => 0, 'scores' => array());
            }
            $stats['page_type_counts'][$pt]['total']++;
            if ($scan['has_jsonld'] || $scan['has_microdata']) {
                $stats['page_type_counts'][$pt]['with_schema']++;
            }
            $stats['page_type_counts'][$pt]['scores'][] = $scan['score'];
            $stats['scores'][] = $scan['score'];

            // Timeout-Schutz: Max 10 Minuten
            if ((time() - $start_time) > 600) break;

            usleep(200000); // 200ms Pause
        }

        // Durchschnitte berechnen
        if (!empty($stats['scores'])) {
            $stats['avg_score'] = round(array_sum($stats['scores']) / count($stats['scores']), 1);
        }
        foreach ($stats['page_type_counts'] as &$pt_stats) {
            if (!empty($pt_stats['scores'])) {
                $pt_stats['avg_score'] = round(array_sum($pt_stats['scores']) / count($pt_stats['scores']), 1);
            }
            unset($pt_stats['scores']); // Scores-Array nicht speichern
        }
        unset($stats['scores']);

        // Sortierung: Schema-Typen nach Häufigkeit
        arsort($stats['schema_type_counts']);

        $audit = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'duration_seconds' => time() - $start_time,
            'total_sitemap_urls' => count($this->getSitemapUrls()),
            'stats' => $stats,
            'results' => $results,
            'overall_coverage' => $stats['total_scanned'] > 0
                ? round(($stats['with_jsonld'] + $stats['with_microdata'] - min($stats['with_jsonld'], $stats['with_microdata'])) / $stats['total_scanned'] * 100, 1)
                : 0,
        );

        // Ergebnisse speichern
        $this->saveAudit($audit);
        $this->addToHistory($audit);

        return array(
            'ok' => true,
            'msg' => $stats['total_scanned'] . ' URLs gescannt in ' . (time() - $start_time) . 's',
            'stats' => $stats,
            'overall_coverage' => $audit['overall_coverage'],
            'avg_score' => $stats['avg_score'],
        );
    }

    // ================================================================
    // ERGEBNISSE & HISTORY
    // ================================================================

    /**
     * Letzte Audit-Ergebnisse abrufen
     */
    public function getAuditResults() {
        if (!is_file($this->audit_file)) {
            return array('ok' => false, 'msg' => 'Noch kein Scan durchgefuehrt');
        }
        return json_decode(file_get_contents($this->audit_file), true);
    }

    /**
     * Audit-Ergebnisse speichern
     */
    private function saveAudit($audit) {
        file_put_contents($this->audit_file, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * History abrufen (fuer Trend-Charts)
     */
    public function getHistory() {
        if (!is_file($this->history_file)) return array();
        $data = json_decode(file_get_contents($this->history_file), true);
        return is_array($data) ? $data : array();
    }

    /**
     * Audit zur History hinzufuegen
     */
    private function addToHistory($audit) {
        $history = $this->getHistory();
        $history[] = array(
            'timestamp' => $audit['timestamp'],
            'total_scanned' => $audit['stats']['total_scanned'],
            'with_jsonld' => $audit['stats']['with_jsonld'],
            'with_microdata' => $audit['stats']['with_microdata'],
            'without_schema' => $audit['stats']['without_schema'],
            'overall_coverage' => $audit['overall_coverage'],
            'avg_score' => $audit['stats']['avg_score'],
            'schema_type_counts' => $audit['stats']['schema_type_counts'],
            'page_type_counts' => $audit['stats']['page_type_counts'],
        );
        // Max 52 Eintraege (1 Jahr bei 14-Tage-Intervall)
        if (count($history) > 52) {
            $history = array_slice($history, -52);
        }
        file_put_contents($this->history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Statistiken fuer Dashboard-Anzeige
     */
    public function getStats() {
        $audit = $this->getAuditResults();
        if (!$audit || !isset($audit['stats'])) {
            return array(
                'has_data' => false,
                'msg' => 'Noch kein Schema.org Scan durchgefuehrt',
            );
        }

        return array(
            'has_data' => true,
            'last_scan' => $audit['timestamp'],
            'total_scanned' => $audit['stats']['total_scanned'],
            'overall_coverage' => $audit['overall_coverage'],
            'avg_score' => $audit['stats']['avg_score'],
            'with_jsonld' => $audit['stats']['with_jsonld'],
            'with_microdata' => $audit['stats']['with_microdata'],
            'without_schema' => $audit['stats']['without_schema'],
            'errors' => $audit['stats']['errors'],
            'schema_types' => $audit['stats']['schema_type_counts'],
            'page_types' => $audit['stats']['page_type_counts'],
        );
    }

    // ================================================================
    // HELPER: JSON-LD Extraktion
    // ================================================================

    /**
     * Alle JSON-LD Bloecke aus HTML extrahieren
     */
    private function extractJsonLd($html) {
        $blocks = array();
        // Pattern: <script type="application/ld+json">...</script>
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches)) {
            foreach ($matches[1] as $block) {
                $block = trim($block);
                if (!empty($block)) {
                    $blocks[] = $block;
                }
            }
        }
        return $blocks;
    }

    /**
     * Schema-Typen aus geparsetem JSON-LD extrahieren (rekursiv)
     */
    private function extractTypes($data) {
        $types = array();

        if (isset($data['@type'])) {
            if (is_array($data['@type'])) {
                $types = array_merge($types, $data['@type']);
            } else {
                $types[] = $data['@type'];
            }
        }

        // @graph Array (mehrere Schemas in einem Block)
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $item) {
                $types = array_merge($types, $this->extractTypes($item));
            }
        }

        // Verschachtelte Objekte durchsuchen
        foreach ($data as $key => $value) {
            if (is_array($value) && !in_array($key, array('@context', '@type', '@id', '@graph'))) {
                if (isset($value['@type'])) {
                    $types = array_merge($types, $this->extractTypes($value));
                }
            }
        }

        return array_unique($types);
    }

    // ================================================================
    // HELPER: Seitentyp-Erkennung
    // ================================================================

    /**
     * Seitentyp anhand der URL und HTML erkennen
     */
    private function detectPageType($url, $html) {
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path || $path === '/') return 'homepage';

        // Sprachprefix entfernen
        $path = preg_replace('#^/(en|fr|es)/(.*)#', '/$2', $path);

        // Produkt-Seiten (tiefer Pfad unter Kategorie)
        if (preg_match('#/samen-shop/.+/.+#', $path)) return 'product';
        if (preg_match('#/growshop/.+/.+#', $path)) return 'product';
        if (preg_match('#/seedbanks/.+/.+#', $path)) return 'product';

        // Kategorie-Seiten
        if (preg_match('#/samen-shop/[^/]+/?$#', $path)) return 'category';
        if (preg_match('#/growshop/[^/]+/?$#', $path)) return 'category';
        if (preg_match('#/seedbanks/[^/]+/?$#', $path)) return 'category';
        if (preg_match('#/samen-shop/?$#', $path)) return 'category';

        // Content/Info-Seiten
        if (preg_match('#/(info|blog|ratgeber|guide|faq|hilfe|ueber-uns|impressum|agb|datenschutz|kontakt)#i', $path)) {
            if (preg_match('#/faq#i', $path)) return 'faq';
            return 'content';
        }

        // Grow-Guides
        if (preg_match('#/(grow|anbau|anleitung|howto|how-to)#i', $path)) return 'howto';

        // Fallback: Wenn HTML Produkt-Merkmale hat
        if (strpos($html, 'add_to_cart') !== false || strpos($html, 'product_info') !== false) {
            return 'product';
        }

        return 'content';
    }

    // ================================================================
    // HELPER: Schema Validierung
    // ================================================================

    /**
     * Schema-Daten validieren
     */
    private function validateSchema($parsed, $page_type) {
        $issues = array();

        $type = isset($parsed['@type']) ? $parsed['@type'] : '';

        // Product Schema Validierung
        if ($type === 'Product') {
            if (empty($parsed['name'])) $issues[] = 'Product: "name" fehlt';
            if (empty($parsed['description'])) $issues[] = 'Product: "description" fehlt';
            if (empty($parsed['image'])) $issues[] = 'Product: "image" fehlt';
            if (empty($parsed['offers']) && empty($parsed['offer'])) {
                $issues[] = 'Product: "offers" fehlt (Preis/Verfuegbarkeit)';
            } else {
                $offers = isset($parsed['offers']) ? $parsed['offers'] : $parsed['offer'];
                if (is_array($offers)) {
                    // Einzelnes Offer oder Array von Offers
                    $offer = isset($offers['@type']) ? $offers : (isset($offers[0]) ? $offers[0] : array());
                    if (empty($offer['price']) && empty($offer['lowPrice'])) {
                        $issues[] = 'Product > Offer: "price" fehlt';
                    }
                    if (empty($offer['priceCurrency'])) {
                        $issues[] = 'Product > Offer: "priceCurrency" fehlt';
                    }
                    if (empty($offer['availability'])) {
                        $issues[] = 'Product > Offer: "availability" fehlt';
                    }
                }
            }
            if (empty($parsed['brand'])) $issues[] = 'Product: "brand" fehlt (empfohlen)';
            if (empty($parsed['sku'])) $issues[] = 'Product: "sku" fehlt (empfohlen)';
        }

        // Organization Schema Validierung
        if ($type === 'Organization') {
            if (empty($parsed['name'])) $issues[] = 'Organization: "name" fehlt';
            if (empty($parsed['url'])) $issues[] = 'Organization: "url" fehlt';
            if (empty($parsed['logo'])) $issues[] = 'Organization: "logo" fehlt';
        }

        // WebSite Schema Validierung
        if ($type === 'WebSite') {
            if (empty($parsed['name'])) $issues[] = 'WebSite: "name" fehlt';
            if (empty($parsed['url'])) $issues[] = 'WebSite: "url" fehlt';
            if (empty($parsed['potentialAction'])) {
                $issues[] = 'WebSite: "potentialAction" (SearchAction) fehlt — keine Sitelinks-Suchbox';
            }
        }

        // BreadcrumbList Validierung
        if ($type === 'BreadcrumbList') {
            if (empty($parsed['itemListElement'])) {
                $issues[] = 'BreadcrumbList: "itemListElement" fehlt';
            }
        }

        // FAQPage Validierung
        if ($type === 'FAQPage') {
            if (empty($parsed['mainEntity'])) {
                $issues[] = 'FAQPage: "mainEntity" fehlt (keine Fragen definiert)';
            }
        }

        return array('issues' => $issues);
    }

    // ================================================================
    // HELPER: Empfehlungen
    // ================================================================

    /**
     * Empfehlungen basierend auf Scan-Ergebnis generieren
     */
    private function getRecommendations($result) {
        $recs = array();
        $page_type = $result['page_type'];
        $found_types = $result['schema_types'];

        if (!isset($this->expected_schemas[$page_type])) {
            return array('Seitentyp konnte nicht eindeutig erkannt werden');
        }

        $expected = $this->expected_schemas[$page_type];

        foreach ($expected as $expected_type) {
            $found = false;
            foreach ($found_types as $ft) {
                if (stripos($ft, $expected_type) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $recs[] = $expected_type . ' Schema fehlt auf dieser ' . ucfirst($page_type) . '-Seite';
            }
        }

        if (!$result['has_jsonld'] && !$result['has_microdata']) {
            $recs[] = 'KEIN Structured Data gefunden — JSON-LD Schema.org implementieren';
        }

        if ($result['has_microdata'] && !$result['has_jsonld']) {
            $recs[] = 'Nur Microdata vorhanden — Migration zu JSON-LD empfohlen (Google bevorzugt JSON-LD)';
        }

        return $recs;
    }

    // ================================================================
    // HELPER: Score Berechnung
    // ================================================================

    /**
     * Schema.org Score fuer eine URL berechnen (0-100)
     */
    private function calculateScore($result) {
        $score = 0;
        $page_type = $result['page_type'];

        // Basis: Hat ueberhaupt Schema.org?
        if ($result['has_jsonld']) $score += 30;
        elseif ($result['has_microdata']) $score += 15;

        // Erwartete Schemas vorhanden?
        if (isset($this->expected_schemas[$page_type])) {
            $expected = $this->expected_schemas[$page_type];
            $found_count = 0;
            foreach ($expected as $exp) {
                foreach ($result['schema_types'] as $ft) {
                    if (stripos($ft, $exp) !== false) {
                        $found_count++;
                        break;
                    }
                }
            }
            if (count($expected) > 0) {
                $score += round(($found_count / count($expected)) * 50);
            }
        }

        // Keine Validierungs-Fehler?
        if (empty($result['issues'])) {
            $score += 20;
        } else {
            $score += max(0, 20 - count($result['issues']) * 3);
        }

        return min(100, max(0, $score));
    }

    // ================================================================
    // HELPER: Sitemap URLs laden
    // ================================================================

    /**
     * URLs aus der Sitemap laden
     */
    private function getSitemapUrls() {
        $sitemap_url = 'https://mr-hanf.de/sitemap.xml';
        $urls = array();

        $ch = curl_init($sitemap_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'MrHanf-SEO-Scanner/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $xml_content = curl_exec($ch);
        curl_close($ch);

        if (!$xml_content) return $urls;

        // Sitemap Index oder direkte Sitemap?
        if (strpos($xml_content, '<sitemapindex') !== false) {
            // Sitemap Index: Sub-Sitemaps laden
            if (preg_match_all('/<loc>(.*?)<\/loc>/i', $xml_content, $matches)) {
                foreach ($matches[1] as $sub_sitemap) {
                    $sub_urls = $this->parseSitemapFile($sub_sitemap);
                    $urls = array_merge($urls, $sub_urls);
                }
            }
        } else {
            // Direkte Sitemap
            if (preg_match_all('/<loc>(.*?)<\/loc>/i', $xml_content, $matches)) {
                $urls = $matches[1];
            }
        }

        return $urls;
    }

    /**
     * Einzelne Sitemap-Datei parsen
     */
    private function parseSitemapFile($url) {
        $ch = curl
_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'MrHanf-SEO-Scanner/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $content = curl_exec($ch);
        curl_close($ch);

        $urls = array();
        if ($content && preg_match_all('/<loc>(.*?)<\/loc>/i', $content, $matches)) {
            $urls = $matches[1];
        }
        return $urls;
    }

    // ================================================================
    // JSON-LD VALIDATOR
    // ================================================================

    /**
     * JSON-LD String validieren
     * @param string $json_str JSON-LD String
     * @return array Validierungsergebnis
     */
    public function validateJsonLd($json_str) {
        $result = array(
            'valid_json' => false,
            'has_context' => false,
            'has_type' => false,
            'types' => array(),
            'issues' => array(),
            'warnings' => array(),
        );

        $parsed = @json_decode($json_str, true);
        if (!$parsed) {
            $result['issues'][] = 'Ungültiges JSON: ' . json_last_error_msg();
            return $result;
        }
        $result['valid_json'] = true;

        // @context prüfen
        if (isset($parsed['@context'])) {
            $result['has_context'] = true;
            $ctx = is_array($parsed['@context']) ? implode(', ', $parsed['@context']) : $parsed['@context'];
            if (strpos($ctx, 'schema.org') === false) {
                $result['warnings'][] = '@context verweist nicht auf schema.org: ' . $ctx;
            }
        } else {
            $result['issues'][] = '@context fehlt (sollte "https://schema.org" sein)';
        }

        // @type prüfen
        if (isset($parsed['@type'])) {
            $result['has_type'] = true;
            $result['types'] = $this->extractTypes($parsed);
        } elseif (isset($parsed['@graph'])) {
            $result['has_type'] = true;
            $result['types'] = $this->extractTypes($parsed);
        } else {
            $result['issues'][] = '@type fehlt';
        }

        // Detaillierte Validierung pro Typ
        foreach ($result['types'] as $type) {
            $validation = $this->validateSchema($parsed, 'unknown');
            $result['issues'] = array_merge($result['issues'], $validation['issues']);
        }

        return $result;
    }

    // ================================================================
    // CRONJOB ENTRY POINT
    // ================================================================

    /**
     * Cronjob-Einstiegspunkt (wird von fpc_dashboard.php aufgerufen)
     */
    public function runCronjob() {
        $config = $this->getConfig();
        $last_run = isset($config['last_cronjob']) ? strtotime($config['last_cronjob']) : 0;
        $interval = isset($config['scan_interval_days']) ? $config['scan_interval_days'] * 86400 : 14 * 86400;

        if ((time() - $last_run) < $interval) {
            return array('ok' => true, 'msg' => 'Naechster Scan am ' . date('Y-m-d', $last_run + $interval), 'skipped' => true);
        }

        $result = $this->runFullScan();

        // Config aktualisieren
        $config['last_cronjob'] = date('Y-m-d H:i:s');
        $this->saveConfig($config);

        return $result;
    }

    private function getConfig() {
        if (!is_file($this->config_file)) return array('scan_interval_days' => 14);
        $data = json_decode(file_get_contents($this->config_file), true);
        return is_array($data) ? $data : array('scan_interval_days' => 14);
    }

    private function saveConfig($config) {
        file_put_contents($this->config_file, json_encode($config, JSON_PRETTY_PRINT));
    }
}
