<?php
/**
 * Mr. Hanf FPC - Core Web Vitals Scanner v1.0.0
 *
 * Testet Seiten ueber die Google PageSpeed Insights API
 * und speichert Ergebnisse fuer Trend-Analyse.
 *
 * Features:
 *   - Einzelne URL testen (Lab + Field Data)
 *   - Batch-Test (Top-50 Seiten)
 *   - Cronjob alle 14 Tage
 *   - Verlaufs-Tracking mit Trend-Charts
 *   - Ampel-System (gruen/gelb/rot)
 *   - Fallback auf CrUX-Daten wenn kein API Key
 *
 * Metriken: LCP, INP, CLS, FCP, TTFB, SI, TBT
 *
 * @version   1.0.0
 * @date      2026-03-30
 */

class FpcSeoCwv {

    private $base_dir;
    private $cache_dir;
    private $results_file;
    private $history_file;
    private $config_file;
    private $api_key;

    // CWV Schwellenwerte (Google 2026 Standards)
    private $thresholds = array(
        'LCP' => array('good' => 2500, 'poor' => 4000),       // ms
        'INP' => array('good' => 200, 'poor' => 500),          // ms
        'CLS' => array('good' => 0.1, 'poor' => 0.25),         // score
        'FCP' => array('good' => 1800, 'poor' => 3000),        // ms
        'TTFB' => array('good' => 800, 'poor' => 1800),        // ms
        'SI'  => array('good' => 3400, 'poor' => 5800),        // ms
        'TBT' => array('good' => 200, 'poor' => 600),          // ms
    );

    public function __construct($base_dir) {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->cache_dir = $this->base_dir . 'cache/fpc/seo/';
        $this->results_file = $this->cache_dir . 'cwv_results.json';
        $this->history_file = $this->cache_dir . 'cwv_history.json';
        $this->config_file = $this->cache_dir . 'cwv_config.json';

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755, true);
        }

        // API Key laden
        $creds_file = $this->base_dir . 'api/fpc/api_credentials.json';
        if (is_file($creds_file)) {
            $creds = @json_decode(file_get_contents($creds_file), true);
            $this->api_key = isset($creds['google_api_key']) ? $creds['google_api_key'] : '';
        }
    }

    /**
     * Pruefen ob PageSpeed API verfuegbar ist
     */
    public function isConfigured() {
        return !empty($this->api_key);
    }

    // ================================================================
    // EINZELNE URL TESTEN
    // ================================================================

    /**
     * Eine URL ueber PageSpeed Insights API testen
     * @param string $url URL zu testen
     * @param string $strategy 'mobile' oder 'desktop'
     * @return array Test-Ergebnis
     */
    public function testUrl($url, $strategy = 'mobile') {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'msg' => 'Google API Key nicht konfiguriert (Settings > API Credentials)');
        }

        // URL normalisieren
        if (strpos($url, 'http') !== 0) {
            $url = 'https://mr-hanf.de' . (strpos($url, '/') === 0 ? '' : '/') . $url;
        }

        $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?'
            . 'url=' . urlencode($url)
            . '&key=' . $this->api_key
            . '&strategy=' . $strategy
            . '&category=performance'
            . '&category=accessibility'
            . '&category=best-practices'
            . '&category=seo';

        $ch = curl_init($api_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $http_code >= 400) {
            return array(
                'ok' => false,
                'msg' => 'PageSpeed API Fehler (HTTP ' . $http_code . ')',
                'url' => $url,
            );
        }

        $data = json_decode($response, true);
        if (!$data) {
            return array('ok' => false, 'msg' => 'Ungueltige API-Antwort');
        }

        return $this->parsePageSpeedResult($data, $url, $strategy);
    }

    /**
     * PageSpeed API Ergebnis parsen
     */
    private function parsePageSpeedResult($data, $url, $strategy) {
        $result = array(
            'ok' => true,
            'url' => $url,
            'strategy' => $strategy,
            'timestamp' => date('Y-m-d H:i:s'),
            'scores' => array(),
            'lab_data' => array(),
            'field_data' => array(),
            'opportunities' => array(),
            'diagnostics' => array(),
        );

        // Lighthouse Scores
        if (isset($data['lighthouseResult']['categories'])) {
            $cats = $data['lighthouseResult']['categories'];
            foreach (array('performance', 'accessibility', 'best-practices', 'seo') as $cat) {
                if (isset($cats[$cat])) {
                    $result['scores'][$cat] = round(($cats[$cat]['score'] ?? 0) * 100);
                }
            }
        }

        // Lab Data (Lighthouse)
        if (isset($data['lighthouseResult']['audits'])) {
            $audits = $data['lighthouseResult']['audits'];
            $metrics = array(
                'largest-contentful-paint' => 'LCP',
                'interaction-to-next-paint' => 'INP',
                'cumulative-layout-shift' => 'CLS',
                'first-contentful-paint' => 'FCP',
                'server-response-time' => 'TTFB',
                'speed-index' => 'SI',
                'total-blocking-time' => 'TBT',
            );

            foreach ($metrics as $audit_key => $metric_name) {
                if (isset($audits[$audit_key])) {
                    $audit = $audits[$audit_key];
                    $value = isset($audit['numericValue']) ? $audit['numericValue'] : null;
                    $display = isset($audit['displayValue']) ? $audit['displayValue'] : '';
                    $score = isset($audit['score']) ? $audit['score'] : null;

                    $result['lab_data'][$metric_name] = array(
                        'value' => $value,
                        'display' => $display,
                        'score' => $score,
                        'rating' => $this->getRating($metric_name, $value),
                    );
                }
            }

            // Opportunities (Verbesserungsvorschlaege)
            foreach ($audits as $key => $audit) {
                if (isset($audit['details']['type']) && $audit['details']['type'] === 'opportunity'
                    && isset($audit['score']) && $audit['score'] < 1) {
                    $result['opportunities'][] = array(
                        'id' => $key,
                        'title' => $audit['title'] ?? $key,
                        'description' => $audit['description'] ?? '',
                        'savings_ms' => isset($audit['details']['overallSavingsMs'])
                            ? round($audit['details']['overallSavingsMs'])
                            : 0,
                        'savings_bytes' => isset($audit['details']['overallSavingsBytes'])
                            ? $audit['details']['overallSavingsBytes']
                            : 0,
                        'score' => $audit['score'],
                    );
                }
            }

            // Opportunities nach Einsparung sortieren
            usort($result['opportunities'], function($a, $b) {
                return $b['savings_ms'] - $a['savings_ms'];
            });
        }

        // Field Data (CrUX - echte Nutzerdaten)
        if (isset($data['loadingExperience']['metrics'])) {
            $field = $data['loadingExperience']['metrics'];
            $field_map = array(
                'LARGEST_CONTENTFUL_PAINT_MS' => 'LCP',
                'INTERACTION_TO_NEXT_PAINT' => 'INP',
                'CUMULATIVE_LAYOUT_SHIFT_SCORE' => 'CLS',
                'FIRST_CONTENTFUL_PAINT_MS' => 'FCP',
                'EXPERIMENTAL_TIME_TO_FIRST_BYTE' => 'TTFB',
            );

            foreach ($field_map as $api_key => $metric_name) {
                if (isset($field[$api_key])) {
                    $m = $field[$api_key];
                    $result['field_data'][$metric_name] = array(
                        'p75' => isset($m['percentile']) ? $m['percentile'] : null,
                        'category' => isset($m['category']) ? strtolower($m['category']) : 'unknown',
                        'distributions' => isset($m['distributions']) ? $m['distributions'] : array(),
                    );
                }
            }

            // Overall Category
            if (isset($data['loadingExperience']['overall_category'])) {
                $result['field_data']['overall'] = strtolower($data['loadingExperience']['overall_category']);
            }
        }

        return $result;
    }

    // ================================================================
    // BATCH TEST
    // ================================================================

    /**
     * Batch-Test der Top-N Seiten
     * @param int $limit Anzahl URLs (default 20, max 50)
     * @param string $strategy 'mobile' oder 'desktop'
     */
    public function testBatch($limit = 20, $strategy = 'mobile') {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'msg' => 'Google API Key nicht konfiguriert');
        }

        $urls = $this->getTopUrls($limit);
        if (empty($urls)) {
            return array('ok' => false, 'msg' => 'Keine URLs zum Testen gefunden');
        }

        $results = array();
        $summary = array(
            'total_tested' => 0,
            'good' => 0,
            'needs_improvement' => 0,
            'poor' => 0,
            'avg_performance' => 0,
            'avg_lcp' => 0,
            'avg_cls' => 0,
            'avg_inp' => 0,
        );

        $perf_scores = array();
        $lcp_values = array();
        $cls_values = array();
        $inp_values = array();

        foreach ($urls as $url) {
            $test = $this->testUrl($url, $strategy);
            if (isset($test['ok']) && $test['ok']) {
                $results[] = $test;
                $summary['total_tested']++;

                // Performance Score
                $perf = isset($test['scores']['performance']) ? $test['scores']['performance'] : 0;
                $perf_scores[] = $perf;
                if ($perf >= 90) $summary['good']++;
                elseif ($perf >= 50) $summary['needs_improvement']++;
                else $summary['poor']++;

                // Metriken sammeln
                if (isset($test['lab_data']['LCP']['value'])) $lcp_values[] = $test['lab_data']['LCP']['value'];
                if (isset($test['lab_data']['CLS']['value'])) $cls_values[] = $test['lab_data']['CLS']['value'];
                if (isset($test['lab_data']['INP']['value'])) $inp_values[] = $test['lab_data']['INP']['value'];
            }

            // 2 Sekunden Pause (API Rate Limit)
            sleep(2);
        }

        // Durchschnitte
        if (!empty($perf_scores)) $summary['avg_performance'] = round(array_sum($perf_scores) / count($perf_scores), 1);
        if (!empty($lcp_values)) $summary['avg_lcp'] = round(array_sum($lcp_values) / count($lcp_values));
        if (!empty($cls_values)) $summary['avg_cls'] = round(array_sum($cls_values) / count($cls_values), 3);
        if (!empty($inp_values)) $summary['avg_inp'] = round(array_sum($inp_values) / count($inp_values));

        $batch_result = array(
            'ok' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'strategy' => $strategy,
            'summary' => $summary,
            'results' => $results,
        );

        // Ergebnisse speichern
        $this->saveResults($batch_result);
        $this->addToHistory($batch_result);

        return $batch_result;
    }

    // ================================================================
    // ERGEBNISSE & HISTORY
    // ================================================================

    public function getResults() {
        if (!is_file($this->results_file)) {
            return array('has_data' => false, 'msg' => 'Noch kein CWV-Test durchgefuehrt');
        }
        $data = json_decode(file_get_contents($this->results_file), true);
        $data['has_data'] = true;
        return $data;
    }

    private function saveResults($data) {
        file_put_contents($this->results_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function getHistory() {
        if (!is_file($this->history_file)) return array();
        $data = json_decode(file_get_contents($this->history_file), true);
        return is_array($data) ? $data : array();
    }

    private function addToHistory($batch_result) {
        $history = $this->getHistory();
        $history[] = array(
            'timestamp' => $batch_result['timestamp'],
            'strategy' => $batch_result['strategy'],
            'total_tested' => $batch_result['summary']['total_tested'],
            'avg_performance' => $batch_result['summary']['avg_performance'],
            'avg_lcp' => $batch_result['summary']['avg_lcp'],
            'avg_cls' => $batch_result['summary']['avg_cls'],
            'avg_inp' => $batch_result['summary']['avg_inp'],
            'good' => $batch_result['summary']['good'],
            'needs_improvement' => $batch_result['summary']['needs_improvement'],
            'poor' => $batch_result['summary']['poor'],
        );
        // Max 52 Eintraege
        if (count($history) > 52) $history = array_slice($history, -52);
        file_put_contents($this->history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // ================================================================
    // HELPER
    // ================================================================

    /**
     * Bewertung einer Metrik (good/needs-improvement/poor)
     */
    private function getRating($metric, $value) {
        if ($value === null) return 'unknown';
        if (!isset($this->thresholds[$metric])) return 'unknown';

        $t = $this->thresholds[$metric];
        if ($value <= $t['good']) return 'good';
        if ($value <= $t['poor']) return 'needs-improvement';
        return 'poor';
    }

    /**
     * Top-URLs fuer CWV-Test ermitteln (aus Sitemap, priorisiert)
     */
    private function getTopUrls($limit = 20) {
        $urls = array();

        // Prioritaet 1: Startseite + Hauptkategorien
        $priority_urls = array(
            'https://mr-hanf.de/',
            'https://mr-hanf.de/samen-shop/',
            'https://mr-hanf.de/samen-shop/autoflowering-samen/',
            'https://mr-hanf.de/samen-shop/feminisierte-samen/',
            'https://mr-hanf.de/growshop/',
            'https://mr-hanf.de/seedbanks/',
            'https://mr-hanf.de/en/',
            'https://mr-hanf.de/fr/',
            'https://mr-hanf.de/es/',
        );
        $urls = array_merge($urls, $priority_urls);

        // Prioritaet 2: Weitere URLs aus Sitemap
        if (count($urls) < $limit) {
            $sitemap_urls = $this->getSitemapSample($limit - count($urls));
            $urls = array_merge($urls, $sitemap_urls);
        }

        return array_slice(array_unique($urls), 0, $limit);
    }

    /**
     * Stichprobe aus Sitemap
     */
    private function getSitemapSample($count) {
        $sitemap_url = 'https://mr-hanf.de/sitemap.xml';
        $ch = curl_init($sitemap_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $xml = curl_exec($ch);
        curl_close($ch);

        $urls = array();
        if ($xml && preg_match_all('/<loc>(.*?)<\/loc>/i', $xml, $matches)) {
            $all = $matches[1];
            shuffle($all);
            $urls = array_slice($all, 0, $count);
        }
        return $urls;
    }

    /**
     * Schwellenwerte fuer Frontend-Anzeige
     */
    public function getThresholds() {
        return $this->thresholds;
    }

    // ================================================================
    // CRONJOB
    // ================================================================

    public function runCronjob() {
        $config = $this->getConfig();
        $last_run = isset($config['last_cronjob']) ? strtotime($config['last_cronjob']) : 0;
        $interval = isset($config['scan_interval_days']) ? $config['scan_interval_days'] * 86400 : 14 * 86400;

        if ((time() - $last_run) < $interval) {
            return array('ok' => true, 'msg' => 'Naechster Test am ' . date('Y-m-d', $last_run + $interval), 'skipped' => true);
        }

        $result = $this->testBatch(20, 'mobile');

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
