<?php
/**
 * Mr. Hanf FPC - SISTRIX Integration v1.1
 *
 * Fetches visibility index and available data from SISTRIX API.
 * Handles unavailable endpoints gracefully (package limitations).
 *
 * Requirements:
 *   - SISTRIX API Key (from sistrix.de > Account > API)
 *
 * @version   1.1.0
 * @date      2026-03-28
 */

class FPC_Sistrix {

    private $api_key;
    private $domain;
    private $cache_dir;
    private $cache_ttl;
    private $api_base = 'https://api.sistrix.com/';

    /**
     * @param string $api_key   SISTRIX API Key
     * @param string $domain    Domain to analyze (e.g. "mr-hanf.de")
     * @param string $cache_dir Directory for caching API responses
     * @param int    $cache_ttl Cache TTL in seconds (default 3600 = 1h)
     */
    public function __construct($api_key, $domain = 'mr-hanf.de', $cache_dir = null, $cache_ttl = 3600) {
        $this->api_key = $api_key;
        $this->domain = $domain;
        $this->cache_dir = $cache_dir ?: dirname(__FILE__) . '/cache/fpc/sistrix/';
        $this->cache_ttl = $cache_ttl;

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0777, true);
        }
    }

    /**
     * Make SISTRIX API request
     * Returns parsed JSON or error array
     */
    private function apiRequest($endpoint, $params = []) {
        $params['api_key'] = $this->api_key;
        $params['format'] = 'json';

        $url = $this->api_base . $endpoint . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code >= 400) {
            return ['error' => true, 'http_code' => $code, 'msg' => 'HTTP ' . $code];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['error' => true, 'msg' => 'Invalid JSON response'];
        }

        // Check for SISTRIX error responses
        if (isset($data['status']) && $data['status'] === 'fail') {
            $err_msg = isset($data['error'][0]['error_message']) ? $data['error'][0]['error_message'] : 'Unknown error';
            $err_code = isset($data['error'][0]['error_code']) ? $data['error'][0]['error_code'] : '';
            return ['error' => true, 'error_code' => $err_code, 'msg' => $err_msg, 'unavailable' => ($err_code == '5001')];
        }
        if (isset($data['status']) && $data['status'] === 'error') {
            $err_msg = isset($data['error'][0]['error_message']) ? $data['error'][0]['error_message'] : 'Unknown error';
            return ['error' => true, 'msg' => $err_msg, 'unavailable' => true];
        }

        return $data;
    }

    private function getCached($key, $fetcher) {
        $file = $this->cache_dir . $key . '.json';
        if (is_file($file) && (time() - filemtime($file)) < $this->cache_ttl) {
            return json_decode(file_get_contents($file), true);
        }
        $data = $fetcher();
        if ($data && !isset($data['error'])) {
            file_put_contents($file, json_encode($data));
        }
        return $data;
    }

    /**
     * Current Visibility Index value
     * Returns: answer[0].sichtbarkeitsindex[0] = {domain, date, value}
     */
    public function getCurrentVisibility() {
        return $this->getCached("visibility_current", function() {
            return $this->apiRequest('domain.sichtbarkeitsindex', [
                'domain' => $this->domain,
            ]);
        });
    }

    /**
     * Visibility Index history
     * Returns: answer[0].sichtbarkeitsindex[] = [{domain, date, value}, ...]
     */
    public function getVisibilityHistory() {
        return $this->getCached("visibility_history", function() {
            return $this->apiRequest('domain.sichtbarkeitsindex', [
                'domain' => $this->domain,
                'history' => 'true',
            ]);
        });
    }

    /**
     * Ranking distribution (Top 10, 11-20, 21-100)
     * Note: May not be available in all SISTRIX packages
     */
    public function getRankingDistribution() {
        return $this->getCached("ranking_dist", function() {
            return $this->apiRequest('domain.ranking.distribution', [
                'domain' => $this->domain,
            ]);
        });
    }

    /**
     * Keyword count
     * Note: May not be available in all SISTRIX packages
     */
    public function getKeywordCount() {
        return $this->getCached("kwcount", function() {
            return $this->apiRequest('domain.kwcount.seo', [
                'domain' => $this->domain,
                'history' => 'true',
            ]);
        });
    }

    /**
     * Competitors
     * Note: May not be available in all SISTRIX packages
     */
    public function getCompetitors($limit = 20) {
        return $this->getCached("competitors_{$limit}", function() use ($limit) {
            return $this->apiRequest('domain.competitors.seo', [
                'domain' => $this->domain,
                'num' => $limit,
            ]);
        });
    }

    /**
     * Top pages
     * Note: May not be available in all SISTRIX packages
     */
    public function getTopPages($limit = 50) {
        return $this->getCached("top_pages_{$limit}", function() use ($limit) {
            return $this->apiRequest('domain.pages', [
                'domain' => $this->domain,
                'num' => $limit,
            ]);
        });
    }

    /**
     * Get API credits remaining
     */
    public function getCredits() {
        return $this->apiRequest('credits');
    }

    /**
     * Get all available data for dashboard display
     * Gracefully handles unavailable endpoints
     */
    public function getDashboardData() {
        $data = [
            'domain'        => $this->domain,
            'timestamp'     => date('Y-m-d H:i:s'),
        ];

        // These always work with any SISTRIX plan
        $data['visibility'] = $this->getCurrentVisibility();
        $data['vi_history'] = $this->getVisibilityHistory();
        $data['credits']    = $this->getCredits();

        // These may not be available - try each and mark as unavailable
        $optional = [
            'ranking_dist' => function() { return $this->getRankingDistribution(); },
            'kwcount'      => function() { return $this->getKeywordCount(); },
            'competitors'  => function() { return $this->getCompetitors(20); },
            'top_pages'    => function() { return $this->getTopPages(50); },
        ];

        foreach ($optional as $key => $fetcher) {
            $result = $fetcher();
            $data[$key] = $result;
        }

        return $data;
    }
}
