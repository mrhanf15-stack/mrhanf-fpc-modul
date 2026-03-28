<?php
/**
 * Mr. Hanf FPC SEO Engine v1.0.0
 *
 * Nahtlos integrierte SEO-Verwaltung fuer das FPC Dashboard.
 * Verwaltet Redirects, Canonicals, 404-Logging, URL-Scanning und Health Score.
 * Alle Daten werden als JSON in cache/fpc/seo/ gespeichert (konsistent mit FPC).
 *
 * Funktionen:
 *   - Redirect-Engine (exakt, Trailing-Slash, Regex, Bulk)
 *   - Canonical-Override-Verwaltung
 *   - 404-Fehler-Protokollierung mit Auto-Aggregation
 *   - URL-Scanner (Sitemap vs. Live, HTTP-Status, Canonical-Check)
 *   - Health Score Berechnung und Trend-Verlauf
 *   - Cross-API Daten-Aggregation (GSC, GA4, Sistrix, FPC)
 *   - CSV Import/Export
 *
 * @version   1.0.0
 * @date      2026-03-28
 */

class FpcSeo {

    private $seo_dir;
    private $base_dir;
    private $site_url;

    // JSON-Dateien
    private $file_redirects;
    private $file_canonicals;
    private $file_404_log;
    private $file_scan_results;
    private $file_scan_history;
    private $file_settings;

    public function __construct($base_dir, $site_url = 'https://mr-hanf.de') {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->site_url = rtrim($site_url, '/');
        $this->seo_dir = $this->base_dir . 'cache/fpc/seo/';

        // Verzeichnis erstellen
        if (!is_dir($this->seo_dir)) {
            @mkdir($this->seo_dir, 0755, true);
        }

        $this->file_redirects    = $this->seo_dir . 'redirects.json';
        $this->file_canonicals   = $this->seo_dir . 'canonicals.json';
        $this->file_404_log      = $this->seo_dir . '404_log.json';
        $this->file_scan_results = $this->seo_dir . 'scan_results.json';
        $this->file_scan_history = $this->seo_dir . 'scan_history.json';
        $this->file_settings     = $this->seo_dir . 'seo_settings.json';
    }

    // ================================================================
    // HELPER: JSON lesen/schreiben
    // ================================================================

    private function readJson($file, $default = array()) {
        if (!is_file($file)) return $default;
        $data = @json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : $default;
    }

    private function writeJson($file, $data) {
        return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ================================================================
    // REDIRECTS
    // ================================================================

    /**
     * Alle Redirects laden
     */
    public function getRedirects($filter = array()) {
        $redirects = $this->readJson($this->file_redirects);
        if (!empty($filter['active'])) {
            $redirects = array_filter($redirects, function($r) use ($filter) {
                return (bool)$r['active'] === ($filter['active'] === 'true' || $filter['active'] === true);
            });
        }
        if (!empty($filter['type'])) {
            $redirects = array_filter($redirects, function($r) use ($filter) {
                return $r['type'] === $filter['type'];
            });
        }
        if (!empty($filter['search'])) {
            $s = strtolower($filter['search']);
            $redirects = array_filter($redirects, function($r) use ($s) {
                return strpos(strtolower($r['source']), $s) !== false || strpos(strtolower($r['target']), $s) !== false;
            });
        }
        return array_values($redirects);
    }

    /**
     * Redirect hinzufuegen
     */
    public function addRedirect($source, $target, $type = '301', $is_regex = false, $note = '') {
        $redirects = $this->readJson($this->file_redirects);
        $source = trim($source);
        $target = trim($target);
        if (empty($source) || empty($target)) return array('ok' => false, 'msg' => 'Source und Target sind Pflichtfelder');

        // Duplikat-Check
        foreach ($redirects as $r) {
            if ($r['source'] === $source) {
                return array('ok' => false, 'msg' => 'Redirect fuer diese Source existiert bereits');
            }
        }

        // Redirect-Loop-Check
        if ($source === $target) {
            return array('ok' => false, 'msg' => 'Source und Target duerfen nicht identisch sein (Loop)');
        }

        $redirects[] = array(
            'id' => $this->generateId($redirects),
            'source' => $source,
            'target' => $target,
            'type' => in_array($type, array('301', '302', '307', '410')) ? $type : '301',
            'is_regex' => (bool)$is_regex,
            'active' => true,
            'hit_count' => 0,
            'last_hit' => null,
            'note' => $note,
            'created' => date('Y-m-d H:i:s'),
            'created_by' => 'dashboard',
        );

        $this->writeJson($this->file_redirects, $redirects);
        return array('ok' => true, 'msg' => 'Redirect erstellt', 'count' => count($redirects));
    }

    /**
     * Redirect aktualisieren
     */
    public function updateRedirect($id, $data) {
        $redirects = $this->readJson($this->file_redirects);
        $found = false;
        foreach ($redirects as &$r) {
            if ($r['id'] == $id) {
                if (isset($data['source'])) $r['source'] = trim($data['source']);
                if (isset($data['target'])) $r['target'] = trim($data['target']);
                if (isset($data['type'])) $r['type'] = $data['type'];
                if (isset($data['is_regex'])) $r['is_regex'] = (bool)$data['is_regex'];
                if (isset($data['active'])) $r['active'] = (bool)$data['active'];
                if (isset($data['note'])) $r['note'] = $data['note'];
                $r['updated'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        if (!$found) return array('ok' => false, 'msg' => 'Redirect nicht gefunden');
        $this->writeJson($this->file_redirects, $redirects);
        return array('ok' => true, 'msg' => 'Redirect aktualisiert');
    }

    /**
     * Redirect loeschen
     */
    public function deleteRedirect($id) {
        $redirects = $this->readJson($this->file_redirects);
        $redirects = array_values(array_filter($redirects, function($r) use ($id) {
            return $r['id'] != $id;
        }));
        $this->writeJson($this->file_redirects, $redirects);
        return array('ok' => true, 'msg' => 'Redirect geloescht');
    }

    /**
     * Redirect-Engine: URL pruefen und ggf. Redirect finden
     * Wird von fpc_serve.php aufgerufen
     */
    public function findRedirect($request_uri) {
        $redirects = $this->readJson($this->file_redirects);
        $path = parse_url($request_uri, PHP_URL_PATH);
        if (empty($path)) $path = '/';

        foreach ($redirects as &$r) {
            if (!$r['active']) continue;

            if ($r['is_regex']) {
                // Regex-Match
                if (@preg_match('#' . $r['source'] . '#i', $path, $matches)) {
                    $target = preg_replace('#' . $r['source'] . '#i', $r['target'], $path);
                    $r['hit_count']++;
                    $r['last_hit'] = date('Y-m-d H:i:s');
                    $this->writeJson($this->file_redirects, $redirects);
                    return array('target' => $target, 'type' => (int)$r['type'], 'id' => $r['id']);
                }
            } else {
                // Exakter Match
                if ($path === $r['source']) {
                    $r['hit_count']++;
                    $r['last_hit'] = date('Y-m-d H:i:s');
                    $this->writeJson($this->file_redirects, $redirects);
                    return array('target' => $r['target'], 'type' => (int)$r['type'], 'id' => $r['id']);
                }
                // Trailing-Slash Variante
                $alt = (substr($path, -1) === '/') ? rtrim($path, '/') : $path . '/';
                if ($alt === $r['source']) {
                    $r['hit_count']++;
                    $r['last_hit'] = date('Y-m-d H:i:s');
                    $this->writeJson($this->file_redirects, $redirects);
                    return array('target' => $r['target'], 'type' => (int)$r['type'], 'id' => $r['id']);
                }
            }
        }
        return null;
    }

    /**
     * Bulk-Redirects erstellen (aus Scan oder CSV)
     */
    public function bulkAddRedirects($items) {
        $added = 0;
        $errors = array();
        foreach ($items as $item) {
            $result = $this->addRedirect(
                $item['source'],
                $item['target'],
                isset($item['type']) ? $item['type'] : '301',
                isset($item['is_regex']) ? $item['is_regex'] : false,
                isset($item['note']) ? $item['note'] : 'Bulk Import'
            );
            if ($result['ok']) {
                $added++;
            } else {
                $errors[] = $item['source'] . ': ' . $result['msg'];
            }
        }
        return array('ok' => true, 'added' => $added, 'errors' => $errors);
    }

    // ================================================================
    // CANONICAL OVERRIDES
    // ================================================================

    public function getCanonicals() {
        return $this->readJson($this->file_canonicals);
    }

    public function addCanonical($page_url, $canonical_url, $note = '') {
        $canonicals = $this->readJson($this->file_canonicals);
        $page_url = trim($page_url);
        $canonical_url = trim($canonical_url);
        if (empty($page_url) || empty($canonical_url)) return array('ok' => false, 'msg' => 'Beide URLs sind Pflicht');

        // Duplikat-Check
        foreach ($canonicals as $c) {
            if ($c['page_url'] === $page_url) {
                return array('ok' => false, 'msg' => 'Canonical fuer diese URL existiert bereits');
            }
        }

        $canonicals[] = array(
            'id' => $this->generateId($canonicals),
            'page_url' => $page_url,
            'canonical_url' => $canonical_url,
            'active' => true,
            'note' => $note,
            'created' => date('Y-m-d H:i:s'),
        );
        $this->writeJson($this->file_canonicals, $canonicals);
        return array('ok' => true, 'msg' => 'Canonical Override erstellt');
    }

    public function updateCanonical($id, $data) {
        $canonicals = $this->readJson($this->file_canonicals);
        foreach ($canonicals as &$c) {
            if ($c['id'] == $id) {
                if (isset($data['page_url'])) $c['page_url'] = trim($data['page_url']);
                if (isset($data['canonical_url'])) $c['canonical_url'] = trim($data['canonical_url']);
                if (isset($data['active'])) $c['active'] = (bool)$data['active'];
                if (isset($data['note'])) $c['note'] = $data['note'];
                $this->writeJson($this->file_canonicals, $canonicals);
                return array('ok' => true, 'msg' => 'Canonical aktualisiert');
            }
        }
        return array('ok' => false, 'msg' => 'Canonical nicht gefunden');
    }

    public function deleteCanonical($id) {
        $canonicals = $this->readJson($this->file_canonicals);
        $canonicals = array_values(array_filter($canonicals, function($c) use ($id) {
            return $c['id'] != $id;
        }));
        $this->writeJson($this->file_canonicals, $canonicals);
        return array('ok' => true, 'msg' => 'Canonical geloescht');
    }

    /**
     * Canonical-Override fuer eine URL finden
     * Wird von fpc_serve.php aufgerufen
     */
    public function findCanonical($request_uri) {
        $canonicals = $this->readJson($this->file_canonicals);
        $path = parse_url($request_uri, PHP_URL_PATH);
        foreach ($canonicals as $c) {
            if (!$c['active']) continue;
            if ($c['page_url'] === $path) {
                return $c['canonical_url'];
            }
        }
        return null;
    }

    // ================================================================
    // 404 LOGGING
    // ================================================================

    /**
     * 404 loggen (wird von fpc_serve.php aufgerufen)
     */
    public function log404($request_uri, $referer = '', $user_agent = '') {
        $log = $this->readJson($this->file_404_log);
        $path = parse_url($request_uri, PHP_URL_PATH);
        $now = date('Y-m-d H:i:s');

        // Existierenden Eintrag suchen und Hit-Count erhoehen
        $found = false;
        foreach ($log as &$entry) {
            if ($entry['url'] === $path) {
                $entry['hit_count']++;
                $entry['last_hit'] = $now;
                if (!empty($referer) && !in_array($referer, $entry['referers'])) {
                    $entry['referers'][] = $referer;
                    if (count($entry['referers']) > 10) array_shift($entry['referers']);
                }
                $found = true;
                break;
            }
        }

        if (!$found) {
            $log[] = array(
                'id' => $this->generateId($log),
                'url' => $path,
                'hit_count' => 1,
                'first_seen' => $now,
                'last_hit' => $now,
                'referers' => !empty($referer) ? array($referer) : array(),
                'resolved' => false,
                'resolved_to' => '',
                'dismissed' => false,
            );
        }

        // Max 1000 Eintraege behalten, aelteste loeschen
        usort($log, function($a, $b) { return $b['hit_count'] - $a['hit_count']; });
        if (count($log) > 1000) $log = array_slice($log, 0, 1000);

        $this->writeJson($this->file_404_log, $log);
    }

    /**
     * 404-Log abrufen
     */
    public function get404Log($filter = array()) {
        $log = $this->readJson($this->file_404_log);

        if (isset($filter['resolved'])) {
            $resolved = $filter['resolved'] === 'true' || $filter['resolved'] === true;
            $log = array_filter($log, function($e) use ($resolved) {
                return (bool)$e['resolved'] === $resolved;
            });
        }
        if (isset($filter['dismissed'])) {
            $dismissed = $filter['dismissed'] === 'true' || $filter['dismissed'] === true;
            $log = array_filter($log, function($e) use ($dismissed) {
                return (bool)$e['dismissed'] === $dismissed;
            });
        }
        if (!empty($filter['search'])) {
            $s = strtolower($filter['search']);
            $log = array_filter($log, function($e) use ($s) {
                return strpos(strtolower($e['url']), $s) !== false;
            });
        }

        // Sortierung: hit_count absteigend
        usort($log, function($a, $b) { return $b['hit_count'] - $a['hit_count']; });
        return array_values($log);
    }

    /**
     * 404 als resolved markieren (Redirect erstellt)
     */
    public function resolve404($id, $redirect_target = '') {
        $log = $this->readJson($this->file_404_log);
        foreach ($log as &$entry) {
            if ($entry['id'] == $id) {
                $entry['resolved'] = true;
                $entry['resolved_to'] = $redirect_target;
                $this->writeJson($this->file_404_log, $log);

                // Redirect automatisch erstellen
                if (!empty($redirect_target)) {
                    $this->addRedirect($entry['url'], $redirect_target, '301', false, 'Auto: 404 resolved');
                }
                return array('ok' => true, 'msg' => '404 resolved und Redirect erstellt');
            }
        }
        return array('ok' => false, 'msg' => '404-Eintrag nicht gefunden');
    }

    /**
     * 404 als dismissed markieren (ignorieren)
     */
    public function dismiss404($id) {
        $log = $this->readJson($this->file_404_log);
        foreach ($log as &$entry) {
            if ($entry['id'] == $id) {
                $entry['dismissed'] = true;
                $this->writeJson($this->file_404_log, $log);
                return array('ok' => true, 'msg' => '404 dismissed');
            }
        }
        return array('ok' => false, 'msg' => '404-Eintrag nicht gefunden');
    }

    // ================================================================
    // URL SCANNER
    // ================================================================

    /**
     * Sitemap parsen und URLs extrahieren
     */
    public function fetchSitemapUrls($sitemap_url = '') {
        if (empty($sitemap_url)) $sitemap_url = $this->site_url . '/sitemap.xml';

        $urls = array();
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 15,
            'user_agent' => 'FPC-SEO-Scanner/1.0',
        )));

        $xml = @file_get_contents($sitemap_url, false, $ctx);
        if ($xml === false) return array('error' => 'Sitemap nicht erreichbar: ' . $sitemap_url);

        // Sitemap-Index pruefen
        if (strpos($xml, '<sitemapindex') !== false) {
            preg_match_all('#<loc>(.*?)</loc>#', $xml, $matches);
            foreach ($matches[1] as $sub_url) {
                $sub_xml = @file_get_contents($sub_url, false, $ctx);
                if ($sub_xml !== false) {
                    preg_match_all('#<loc>(.*?)</loc>#', $sub_xml, $sub_matches);
                    foreach ($sub_matches[1] as $url) {
                        $urls[] = str_replace($this->site_url, '', $url);
                    }
                }
            }
        } else {
            preg_match_all('#<loc>(.*?)</loc>#', $xml, $matches);
            foreach ($matches[1] as $url) {
                $urls[] = str_replace($this->site_url, '', $url);
            }
        }

        return array_unique($urls);
    }

    /**
     * Einzelne URL live pruefen (HTTP-Status + Canonical)
     */
    public function scanLiveUrl($url_path) {
        $full_url = $this->site_url . $url_path;
        $result = array(
            'url' => $url_path,
            'http_status' => 0,
            'canonical' => '',
            'canonical_match' => null,
            'redirect_target' => '',
            'response_time_ms' => 0,
            'has_fpc_cache' => false,
            'error' => '',
        );

        $start = microtime(true);
        $ch = curl_init($full_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADER => true,
            CURLOPT_USERAGENT => 'FPC-SEO-Scanner/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($ch);
        $result['response_time_ms'] = round((microtime(true) - $start) * 1000);

        if ($response === false) {
            $result['error'] = curl_error($ch);
            curl_close($ch);
            return $result;
        }

        $result['http_status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        curl_close($ch);

        // Redirect-Ziel
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
            $result['redirect_target'] = trim($m[1]);
        }

        // FPC Cache Header
        if (stripos($headers, 'X-FPC-Cache: HIT') !== false) {
            $result['has_fpc_cache'] = true;
        }

        // Canonical aus HTML extrahieren
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/', $body, $m)) {
            $canonical = $m[1];
            $result['canonical'] = $canonical;
            $expected = $this->site_url . $url_path;
            $result['canonical_match'] = ($canonical === $expected || $canonical === rtrim($expected, '/') || $canonical === $expected . '/');
        }

        return $result;
    }

    /**
     * Auto-Scan starten: Sitemap-URLs vs. Live pruefen
     */
    public function runAutoScan($mode = 'fast', $limit = 100) {
        $sitemap_urls = $this->fetchSitemapUrls();
        if (isset($sitemap_urls['error'])) return $sitemap_urls;

        $scan_results = array();
        $scanned = 0;
        $total = count($sitemap_urls);

        // Bei fast-Modus nur die ersten N URLs
        $urls_to_scan = ($mode === 'fast') ? array_slice($sitemap_urls, 0, $limit) : $sitemap_urls;

        foreach ($urls_to_scan as $url_path) {
            if (empty($url_path) || $url_path === '/') continue;

            $live = $this->scanLiveUrl($url_path);
            $status = 'ok';
            $issues = array();

            if ($live['http_status'] >= 400) {
                $status = 'error';
                $issues[] = 'HTTP ' . $live['http_status'];
            } elseif ($live['http_status'] >= 300) {
                $status = 'redirect';
                $issues[] = 'Redirect zu ' . $live['redirect_target'];
            }

            if ($live['canonical_match'] === false) {
                $status = ($status === 'ok') ? 'warning' : $status;
                $issues[] = 'Canonical Mismatch: ' . $live['canonical'];
            }

            if (!$live['has_fpc_cache']) {
                $issues[] = 'Kein FPC Cache';
            }

            $scan_results[] = array(
                'url' => $url_path,
                'in_sitemap' => true,
                'http_status' => $live['http_status'],
                'canonical' => $live['canonical'],
                'canonical_match' => $live['canonical_match'],
                'redirect_target' => $live['redirect_target'],
                'has_fpc_cache' => $live['has_fpc_cache'],
                'response_time_ms' => $live['response_time_ms'],
                'status' => $status,
                'issues' => $issues,
                'scanned_at' => date('Y-m-d H:i:s'),
            );

            $scanned++;

            // Fortschritt speichern alle 10 URLs
            if ($scanned % 10 === 0) {
                $this->writeJson($this->file_scan_results, $scan_results);
            }
        }

        // Finale Speicherung
        $this->writeJson($this->file_scan_results, $scan_results);

        // Health Score berechnen und History speichern
        $health = $this->calculateHealthScore($scan_results);
        $this->addScanHistory($health, $scanned, $total);

        return array(
            'ok' => true,
            'scanned' => $scanned,
            'total_sitemap' => $total,
            'health_score' => $health,
            'results' => $scan_results,
        );
    }

    /**
     * Scan-Ergebnisse abrufen
     */
    public function getScanResults($filter = array()) {
        $results = $this->readJson($this->file_scan_results);
        if (!empty($filter['status'])) {
            $results = array_filter($results, function($r) use ($filter) {
                return $r['status'] === $filter['status'];
            });
        }
        if (!empty($filter['search'])) {
            $s = strtolower($filter['search']);
            $results = array_filter($results, function($r) use ($s) {
                return strpos(strtolower($r['url']), $s) !== false;
            });
        }
        return array_values($results);
    }

    // ================================================================
    // HEALTH SCORE
    // ================================================================

    /**
     * Health Score berechnen
     */
    public function calculateHealthScore($scan_results = null) {
        if ($scan_results === null) {
            $scan_results = $this->readJson($this->file_scan_results);
        }
        if (empty($scan_results)) return array('score' => 0, 'breakdown' => array());

        $total = count($scan_results);
        $ok = 0;
        $warnings = 0;
        $errors = 0;
        $redirects_count = 0;
        $no_cache = 0;
        $canonical_mismatches = 0;

        foreach ($scan_results as $r) {
            switch ($r['status']) {
                case 'ok': $ok++; break;
                case 'warning': $warnings++; break;
                case 'error': $errors++; break;
                case 'redirect': $redirects_count++; break;
            }
            if (!$r['has_fpc_cache']) $no_cache++;
            if ($r['canonical_match'] === false) $canonical_mismatches++;
        }

        // Score-Berechnung: 100 - Abzuege
        $score = 100;
        if ($total > 0) {
            $score -= ($errors / $total) * 40;           // Errors: max -40
            $score -= ($warnings / $total) * 20;         // Warnings: max -20
            $score -= ($redirects_count / $total) * 10;  // Redirects: max -10
            $score -= ($canonical_mismatches / $total) * 15; // Canonical: max -15
            $score -= ($no_cache / $total) * 15;         // No Cache: max -15
        }
        $score = max(0, round($score, 1));

        // 404-Log Einfluss
        $log404 = $this->readJson($this->file_404_log);
        $unresolved_404 = count(array_filter($log404, function($e) {
            return !$e['resolved'] && !$e['dismissed'];
        }));
        if ($unresolved_404 > 50) $score = max(0, $score - 5);
        elseif ($unresolved_404 > 20) $score = max(0, $score - 3);
        elseif ($unresolved_404 > 5) $score = max(0, $score - 1);

        return array(
            'score' => $score,
            'total_urls' => $total,
            'ok' => $ok,
            'warnings' => $warnings,
            'errors' => $errors,
            'redirects' => $redirects_count,
            'no_cache' => $no_cache,
            'canonical_mismatches' => $canonical_mismatches,
            'unresolved_404' => $unresolved_404,
            'calculated_at' => date('Y-m-d H:i:s'),
        );
    }

    /**
     * Scan-History speichern
     */
    private function addScanHistory($health, $scanned, $total) {
        $history = $this->readJson($this->file_scan_history);
        $history[] = array(
            'date' => date('Y-m-d H:i:s'),
            'score' => $health['score'],
            'total_urls' => $total,
            'scanned' => $scanned,
            'ok' => $health['ok'],
            'warnings' => $health['warnings'],
            'errors' => $health['errors'],
        );
        // Max 100 Eintraege
        if (count($history) > 100) $history = array_slice($history, -100);
        $this->writeJson($this->file_scan_history, $history);
    }

    /**
     * Scan-History abrufen
     */
    public function getScanHistory() {
        return $this->readJson($this->file_scan_history);
    }

    // ================================================================
    // CROSS-API DATEN-AGGREGATION
    // ================================================================

    /**
     * Gesamt-Dashboard-Daten: Ist-Zustand
     */
    public function getIstZustand() {
        $redirects = $this->readJson($this->file_redirects);
        $canonicals = $this->readJson($this->file_canonicals);
        $log404 = $this->readJson($this->file_404_log);
        $scan_results = $this->readJson($this->file_scan_results);
        $scan_history = $this->readJson($this->file_scan_history);

        $active_redirects = count(array_filter($redirects, function($r) { return $r['active']; }));
        $total_hits = array_sum(array_column($redirects, 'hit_count'));
        $unresolved_404 = count(array_filter($log404, function($e) { return !$e['resolved'] && !$e['dismissed']; }));
        $total_404_hits = array_sum(array_column($log404, 'hit_count'));

        $health = $this->calculateHealthScore();
        $last_scan = !empty($scan_history) ? end($scan_history) : null;

        return array(
            'health' => $health,
            'redirects' => array(
                'total' => count($redirects),
                'active' => $active_redirects,
                'total_hits' => $total_hits,
                'top_redirects' => array_slice($redirects, 0, 10),
            ),
            'canonicals' => array(
                'total' => count($canonicals),
                'active' => count(array_filter($canonicals, function($c) { return $c['active']; })),
            ),
            'log_404' => array(
                'total' => count($log404),
                'unresolved' => $unresolved_404,
                'total_hits' => $total_404_hits,
                'top_404' => array_slice(array_filter($log404, function($e) { return !$e['resolved'] && !$e['dismissed']; }), 0, 20),
            ),
            'scan' => array(
                'total_results' => count($scan_results),
                'last_scan' => $last_scan,
                'history' => $scan_history,
            ),
        );
    }

    /**
     * Cross-API Problem-Erkennung
     * Sammelt Daten aus allen Quellen und findet Korrelationen
     */
    public function getCrossApiProblems($gsc_data = null, $ga4_data = null, $sistrix_data = null) {
        $problems = array();
        $log404 = $this->readJson($this->file_404_log);
        $scan_results = $this->readJson($this->file_scan_results);

        // 1. GSC: URLs mit Impressions die 404 sind
        if ($gsc_data && isset($gsc_data['pages'])) {
            $log404_urls = array_column($log404, 'url');
            foreach ($gsc_data['pages'] as $page) {
                $path = parse_url($page['keys'][0], PHP_URL_PATH);
                if (in_array($path, $log404_urls)) {
                    $problems[] = array(
                        'type' => 'gsc_404',
                        'severity' => 'critical',
                        'url' => $path,
                        'description' => 'URL hat ' . number_format($page['impressions']) . ' Impressions in GSC aber ist 404',
                        'impressions' => $page['impressions'],
                        'clicks' => $page['clicks'],
                        'suggestion' => 'Redirect erstellen zu einer relevanten Seite',
                    );
                }
            }
        }

        // 2. GSC: URLs mit vielen Impressions aber sehr niedriger CTR
        if ($gsc_data && isset($gsc_data['pages'])) {
            foreach ($gsc_data['pages'] as $page) {
                if ($page['impressions'] > 100 && $page['ctr'] < 0.01) {
                    $problems[] = array(
                        'type' => 'low_ctr',
                        'severity' => 'warning',
                        'url' => $page['keys'][0],
                        'description' => number_format($page['impressions']) . ' Impressions aber nur ' . round($page['ctr'] * 100, 2) . '% CTR',
                        'impressions' => $page['impressions'],
                        'ctr' => $page['ctr'],
                        'suggestion' => 'Meta Title und Description optimieren',
                    );
                }
            }
        }

        // 3. GA4: Seiten mit hoher Bounce Rate
        if ($ga4_data && isset($ga4_data['top_pages'])) {
            foreach ($ga4_data['top_pages'] as $page) {
                if (isset($page['bounceRate']) && $page['bounceRate'] > 0.8 && isset($page['sessions']) && $page['sessions'] > 50) {
                    $problems[] = array(
                        'type' => 'high_bounce',
                        'severity' => 'warning',
                        'url' => $page['pagePath'],
                        'description' => 'Bounce Rate ' . round($page['bounceRate'] * 100, 1) . '% bei ' . $page['sessions'] . ' Sessions',
                        'bounce_rate' => $page['bounceRate'],
                        'sessions' => $page['sessions'],
                        'suggestion' => 'Content und User Experience verbessern',
                    );
                }
            }
        }

        // 4. Scan: Canonical Mismatches
        foreach ($scan_results as $r) {
            if ($r['canonical_match'] === false) {
                $problems[] = array(
                    'type' => 'canonical_mismatch',
                    'severity' => 'warning',
                    'url' => $r['url'],
                    'description' => 'Canonical zeigt auf ' . $r['canonical'] . ' statt auf sich selbst',
                    'canonical' => $r['canonical'],
                    'suggestion' => 'Canonical Override setzen oder Seite pruefen',
                );
            }
        }

        // 5. Scan: URLs mit HTTP-Fehlern
        foreach ($scan_results as $r) {
            if ($r['http_status'] >= 400) {
                $problems[] = array(
                    'type' => 'http_error',
                    'severity' => 'critical',
                    'url' => $r['url'],
                    'description' => 'HTTP Status ' . $r['http_status'] . ' (in Sitemap!)',
                    'http_status' => $r['http_status'],
                    'suggestion' => 'Aus Sitemap entfernen oder Redirect erstellen',
                );
            }
        }

        // 6. 404s mit hohem Hit-Count
        foreach ($log404 as $entry) {
            if (!$entry['resolved'] && !$entry['dismissed'] && $entry['hit_count'] > 10) {
                $problems[] = array(
                    'type' => '404_high_hits',
                    'severity' => $entry['hit_count'] > 50 ? 'critical' : 'warning',
                    'url' => $entry['url'],
                    'description' => $entry['hit_count'] . ' Aufrufe auf 404-Seite',
                    'hit_count' => $entry['hit_count'],
                    'referers' => $entry['referers'],
                    'suggestion' => 'Redirect erstellen',
                );
            }
        }

        // Sortierung: critical zuerst, dann nach Impact
        usort($problems, function($a, $b) {
            $sev = array('critical' => 0, 'warning' => 1, 'info' => 2);
            $sa = isset($sev[$a['severity']]) ? $sev[$a['severity']] : 2;
            $sb = isset($sev[$b['severity']]) ? $sev[$b['severity']] : 2;
            if ($sa !== $sb) return $sa - $sb;
            $ia = isset($a['impressions']) ? $a['impressions'] : (isset($a['hit_count']) ? $a['hit_count'] : 0);
            $ib = isset($b['impressions']) ? $b['impressions'] : (isset($b['hit_count']) ? $b['hit_count'] : 0);
            return $ib - $ia;
        });

        return $problems;
    }

    // ================================================================
    // CSV IMPORT / EXPORT
    // ================================================================

    public function exportRedirectsCsv() {
        $redirects = $this->readJson($this->file_redirects);
        $csv = "source,target,type,is_regex,active,hit_count,note\n";
        foreach ($redirects as $r) {
            $csv .= '"' . str_replace('"', '""', $r['source']) . '","'
                  . str_replace('"', '""', $r['target']) . '","'
                  . $r['type'] . '","'
                  . ($r['is_regex'] ? '1' : '0') . '","'
                  . ($r['active'] ? '1' : '0') . '","'
                  . $r['hit_count'] . '","'
                  . str_replace('"', '""', $r['note']) . '"' . "\n";
        }
        return $csv;
    }

    public function importRedirectsCsv($csv_content) {
        $lines = explode("\n", trim($csv_content));
        if (count($lines) < 2) return array('ok' => false, 'msg' => 'CSV leer oder nur Header');

        $header = str_getcsv(array_shift($lines));
        $items = array();
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $row = str_getcsv($line);
            if (count($row) < 2) continue;
            $items[] = array(
                'source' => $row[0],
                'target' => $row[1],
                'type' => isset($row[2]) ? $row[2] : '301',
                'is_regex' => isset($row[3]) ? (bool)$row[3] : false,
                'note' => isset($row[6]) ? $row[6] : 'CSV Import',
            );
        }

        return $this->bulkAddRedirects($items);
    }

    // ================================================================
    // SETTINGS
    // ================================================================

    public function getSettings() {
        return $this->readJson($this->file_settings, array(
            'auto_404_logging' => true,
            'redirect_check_enabled' => true,
            'canonical_override_enabled' => true,
            'scan_interval_hours' => 24,
            'max_404_entries' => 1000,
            'auto_redirect_404_threshold' => 0,  // 0 = deaktiviert
        ));
    }

    public function saveSettings($settings) {
        $current = $this->getSettings();
        $merged = array_merge($current, $settings);
        $this->writeJson($this->file_settings, $merged);
        return array('ok' => true, 'msg' => 'SEO Settings gespeichert');
    }

    // ================================================================
    // HELPER
    // ================================================================

    private function generateId($items) {
        $max = 0;
        foreach ($items as $item) {
            if (isset($item['id']) && $item['id'] > $max) $max = $item['id'];
        }
        return $max + 1;
    }

    /**
     * Statistik-Zusammenfassung fuer KI-Analyse
     */
    public function getAiSummary() {
        $ist = $this->getIstZustand();
        $summary = array(
            'health_score' => $ist['health']['score'],
            'total_redirects' => $ist['redirects']['total'],
            'active_redirects' => $ist['redirects']['active'],
            'redirect_hits_total' => $ist['redirects']['total_hits'],
            'unresolved_404_count' => $ist['log_404']['unresolved'],
            'total_404_hits' => $ist['log_404']['total_hits'],
            'canonical_overrides' => $ist['canonicals']['total'],
            'scan_urls_total' => $ist['scan']['total_results'],
            'scan_errors' => $ist['health']['errors'],
            'scan_warnings' => $ist['health']['warnings'],
            'canonical_mismatches' => $ist['health']['canonical_mismatches'],
            'no_cache_urls' => $ist['health']['no_cache'],
            'top_404_urls' => array_slice(array_map(function($e) {
                return $e['url'] . ' (' . $e['hit_count'] . ' hits)';
            }, $ist['log_404']['top_404']), 0, 10),
        );
        return $summary;
    }
}
