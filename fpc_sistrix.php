<?php
/**
 * Mr. Hanf FPC - SISTRIX Integration v1.2
 *
 * Fetches visibility index, keyword rankings, competitor data
 * and URL-level analysis from SISTRIX API.
 * Erweitert fuer SEO Tab v11.0 mit Keywords, Competitor und URL-Analyse.
 *
 * @version   2.0.0
 * @date      2026-03-30
 */

class FPC_Sistrix {

    private $api_key;
    private $domain;
    private $cache_dir;
    private $cache_ttl;
    private $api_base = 'https://api.sistrix.com/';

    public function __construct($api_key, $domain = 'mr-hanf.de', $cache_dir = null, $cache_ttl = 3600) {
        $this->api_key = $api_key;
        $this->domain = $domain;
        $this->cache_dir = $cache_dir ?: dirname(__FILE__) . '/cache/fpc/sistrix/';
        $this->cache_ttl = $cache_ttl;

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0777, true);
        }
    }

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

        if (isset($data['status']) && in_array($data['status'], ['fail', 'error'])) {
            $err_msg = isset($data['error'][0]['error_message']) ? $data['error'][0]['error_message'] : 'Unknown error';
            return ['error' => true, 'msg' => $err_msg];
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

    public function getCurrentVisibility() {
        return $this->getCached("visibility_current", function() {
            return $this->apiRequest('domain.sichtbarkeitsindex', [
                'domain' => $this->domain,
            ]);
        });
    }

    public function getVisibilityHistory() {
        return $this->getCached("visibility_history", function() {
            return $this->apiRequest('domain.sichtbarkeitsindex', [
                'domain' => $this->domain,
                'history' => 'true',
            ]);
        });
    }

    public function getCredits() {
        return $this->apiRequest('credits');
    }

    // ================================================================
    // KEYWORD RANKINGS
    // ================================================================

    /**
     * Top Keyword Rankings fuer die Domain
     * @param int $limit Max Ergebnisse (default 100)
     * @param string $country Laendercode (default 'de')
     */
    public function getKeywordRankings($limit = 100, $country = 'de') {
        return $this->getCached("keywords_{$country}_{$limit}", function() use ($limit, $country) {
            return $this->apiRequest('domain.keywords', [
                'domain' => $this->domain,
                'country' => $country,
                'limit' => $limit,
                'sort' => 'traffic',
            ]);
        });
    }

    /**
     * Keyword Ranking-Veraenderungen (Gewinner/Verlierer)
     */
    public function getKeywordChanges($country = 'de') {
        return $this->getCached("keyword_changes_{$country}", function() use ($country) {
            $winners = $this->apiRequest('domain.ranking-distribution', [
                'domain' => $this->domain,
                'country' => $country,
            ]);
            return $winners;
        });
    }

    /**
     * Keyword-Ideen / verwandte Keywords
     */
    public function getKeywordIdeas($keyword, $country = 'de') {
        return $this->getCached("keyword_ideas_" . md5($keyword), function() use ($keyword, $country) {
            return $this->apiRequest('keyword.related', [
                'keyword' => $keyword,
                'country' => $country,
                'limit' => 50,
            ]);
        });
    }

    // ================================================================
    // COMPETITOR ANALYSE
    // ================================================================

    /**
     * Wettbewerber-Sichtbarkeit vergleichen
     * @param array $competitors Array von Domains
     */
    public function getCompetitorVisibility($competitors = []) {
        if (empty($competitors)) {
            $competitors = ['linda-seeds.com', 'sensiseeds.com', 'royalqueenseeds.de', 'zamnesia.com'];
        }

        $result = [
            'own' => $this->getCurrentVisibility(),
            'competitors' => [],
        ];

        foreach ($competitors as $comp) {
            $result['competitors'][$comp] = $this->getCached("comp_vis_" . md5($comp), function() use ($comp) {
                return $this->apiRequest('domain.sichtbarkeitsindex', [
                    'domain' => $comp,
                ]);
            });
        }

        return $result;
    }

    /**
     * Gemeinsame Keywords mit Wettbewerbern
     */
    public function getCompetitorKeywords($competitor_domain, $country = 'de') {
        return $this->getCached("comp_kw_" . md5($competitor_domain), function() use ($competitor_domain, $country) {
            return $this->apiRequest('domain.competitors.seo', [
                'domain' => $this->domain,
                'country' => $country,
                'limit' => 20,
            ]);
        });
    }

    // ================================================================
    // URL-LEVEL ANALYSE
    // ================================================================

    /**
     * URL-Level Sichtbarkeits-Analyse
     * @param string $url Vollstaendige URL oder Pfad
     */
    public function getUrlAnalysis($url) {
        if (strpos($url, 'http') !== 0) {
            $url = 'https://' . $this->domain . $url;
        }

        return $this->getCached("url_" . md5($url), function() use ($url) {
            $visibility = $this->apiRequest('url.sichtbarkeitsindex', [
                'url' => $url,
            ]);

            $keywords = $this->apiRequest('url.keywords', [
                'url' => $url,
                'limit' => 20,
            ]);

            return [
                'url' => $url,
                'visibility' => $visibility,
                'keywords' => $keywords,
            ];
        });
    }

    /**
     * Top-URLs der Domain nach Sichtbarkeit
     */
    public function getTopUrls($limit = 50) {
        return $this->getCached("top_urls_{$limit}", function() use ($limit) {
            return $this->apiRequest('domain.urls', [
                'domain' => $this->domain,
                'limit' => $limit,
                'sort' => 'visibility',
            ]);
        });
    }

    // ================================================================
    // SERP FEATURES
    // ================================================================

    /**
     * SERP Features fuer die Domain
     */
    public function getSerpFeatures() {
        return $this->getCached("serp_features", function() {
            return $this->apiRequest('domain.serp-features', [
                'domain' => $this->domain,
            ]);
        });
    }

    // ================================================================
    // DASHBOARD DATA (erweitert)
    // ================================================================

    /**
     * Get dashboard data - SI + History + Credits
     * Basis-Daten die immer geladen werden
     */
    public function getDashboardData() {
        return [
            'domain'     => $this->domain,
            'timestamp'  => date('Y-m-d H:i:s'),
            'visibility' => $this->getCurrentVisibility(),
            'vi_history' => $this->getVisibilityHistory(),
            'credits'    => $this->getCredits(),
        ];
    }

    /**
     * Erweiterte Dashboard-Daten fuer SEO Tab
     * Laedt zusaetzlich Keywords, Competitors und Top-URLs
     */
    public function getExtendedDashboardData($competitors = []) {
        $base = $this->getDashboardData();
        $base['keywords'] = $this->getKeywordRankings(50);
        $base['top_urls'] = $this->getTopUrls(20);
        $base['competitors'] = $this->getCompetitorVisibility($competitors);
        return $base;
    }

    /**
     * SEO Tab spezifische Daten
     */
    public function getSeoTabData() {
        return [
            'domain' => $this->domain,
            'timestamp' => date('Y-m-d H:i:s'),
            'visibility' => $this->getCurrentVisibility(),
            'keywords' => $this->getKeywordRankings(100),
            'top_urls' => $this->getTopUrls(50),
            'competitors' => $this->getCompetitorVisibility(),
            'credits' => $this->getCredits(),
        ];
    }
}
