<?php
/**
 * Mr. Hanf FPC - SISTRIX Integration v1.0
 *
 * Fetches visibility index, rankings, keywords, competitors
 * from SISTRIX API and caches results locally.
 *
 * Requirements:
 *   - SISTRIX API Key (from sistrix.de > Account > API)
 *
 * @version   1.0.0
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
     */
    private function apiRequest($endpoint, $params = []) {
        $params['api_key'] = $this->api_key;
        $params['format'] = 'json';

        $url = $this->api_base . $endpoint . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'FPC-Dashboard/1.0',
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            return ['error' => true, 'http_code' => $code, 'response' => $response];
        }

        return json_decode($response, true);
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
     * Visibility Index (current + history)
     */
    public function getVisibilityIndex() {
        return $this->getCached("visibility", function() {
            return $this->apiRequest('domain.sichtbarkeitsindex', [
                'domain' => $this->domain,
                'history' => 'true',
            ]);
        });
    }

    /**
     * Current Visibility Index value only
     */
    public function getCurrentVisibility() {
        return $this->getCached("visibility_current", function() {
            return $this->apiRequest('domain.sichtbarkeitsindex', [
                'domain' => $this->domain,
            ]);
        });
    }

    /**
     * SEO Keywords ranking in Top 100
     */
    public function getKeywords($limit = 100) {
        return $this->getCached("keywords_{$limit}", function() use ($limit) {
            return $this->apiRequest('domain.seo.top10', [
                'domain' => $this->domain,
                'num' => $limit,
            ]);
        });
    }

    /**
     * Ranking distribution (Top 10, 11-20, 21-100)
     */
    public function getRankingDistribution() {
        return $this->getCached("ranking_dist", function() {
            return $this->apiRequest('domain.ranking.distribution', [
                'domain' => $this->domain,
            ]);
        });
    }

    /**
     * Top ranking URLs
     */
    public function getTopUrls($limit = 50) {
        return $this->getCached("top_urls_{$limit}", function() use ($limit) {
            return $this->apiRequest('domain.pages', [
                'domain' => $this->domain,
                'num' => $limit,
            ]);
        });
    }

    /**
     * Competitors (similar domains)
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
     * Keyword changes (winners/losers)
     */
    public function getKeywordChanges() {
        return $this->getCached("keyword_changes", function() {
            return $this->apiRequest('domain.kwchange.seo', [
                'domain' => $this->domain,
                'num' => 50,
            ]);
        });
    }

    /**
     * Page speed / Core Web Vitals
     */
    public function getCoreWebVitals() {
        return $this->getCached("cwv", function() {
            return $this->apiRequest('domain.cwv', [
                'domain' => $this->domain,
            ]);
        });
    }

    /**
     * Get all data for dashboard display
     */
    public function getDashboardData() {
        return [
            'visibility'    => $this->getCurrentVisibility(),
            'vi_history'    => $this->getVisibilityIndex(),
            'keywords'      => $this->getKeywords(100),
            'ranking_dist'  => $this->getRankingDistribution(),
            'top_urls'      => $this->getTopUrls(50),
            'competitors'   => $this->getCompetitors(20),
            'keyword_changes' => $this->getKeywordChanges(),
            'cwv'           => $this->getCoreWebVitals(),
            'timestamp'     => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Get API credits remaining
     */
    public function getCredits() {
        return $this->apiRequest('credits');
    }
}
