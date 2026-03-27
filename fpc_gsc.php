<?php
/**
 * Mr. Hanf FPC - Google Search Console Integration v1.0
 *
 * Fetches indexing status, crawl stats, and search performance
 * from Google Search Console API and caches results locally.
 *
 * Requirements:
 *   - Google Service Account JSON key file
 *   - Service Account added as user in Search Console property
 *
 * @version   1.0.0
 * @date      2026-03-28
 */

class FPC_GoogleSearchConsole {

    private $service_account_file;
    private $site_url;
    private $cache_dir;
    private $cache_ttl;
    private $access_token;

    /**
     * @param string $service_account_file Path to Google Service Account JSON
     * @param string $site_url             Property URL (e.g. https://mr-hanf.de/)
     * @param string $cache_dir            Directory for caching API responses
     * @param int    $cache_ttl            Cache TTL in seconds (default 3600 = 1h)
     */
    public function __construct($service_account_file, $site_url = 'https://mr-hanf.de/', $cache_dir = null, $cache_ttl = 3600) {
        $this->service_account_file = $service_account_file;
        $this->site_url = rtrim($site_url, '/') . '/';
        $this->cache_dir = $cache_dir ?: dirname(__FILE__) . '/cache/fpc/gsc/';
        $this->cache_ttl = $cache_ttl;
        $this->access_token = null;

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0777, true);
        }
    }

    /**
     * Get OAuth2 access token using Service Account JWT
     */
    private function getAccessToken() {
        if ($this->access_token) return $this->access_token;

        // Check token cache
        $token_cache = $this->cache_dir . 'token.json';
        if (is_file($token_cache)) {
            $cached = json_decode(file_get_contents($token_cache), true);
            if ($cached && isset($cached['expires_at']) && $cached['expires_at'] > time()) {
                $this->access_token = $cached['access_token'];
                return $this->access_token;
            }
        }

        if (!is_file($this->service_account_file)) {
            throw new Exception('Service Account JSON file not found: ' . $this->service_account_file);
        }

        $sa = json_decode(file_get_contents($this->service_account_file), true);
        if (!$sa || !isset($sa['private_key'])) {
            throw new Exception('Invalid Service Account JSON file');
        }

        // Build JWT
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claims = base64_encode(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ]));

        $signing_input = str_replace(['+', '/', '='], ['-', '_', ''], $header) . '.' .
                         str_replace(['+', '/', '='], ['-', '_', ''], $claims);

        $key = openssl_pkey_get_private($sa['private_key']);
        openssl_sign($signing_input, $signature, $key, 'SHA256');
        $jwt = $signing_input . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        // Exchange JWT for access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $token_data = json_decode($response, true);
        if (!$token_data || !isset($token_data['access_token'])) {
            throw new Exception('Failed to get access token: ' . $response);
        }

        $this->access_token = $token_data['access_token'];

        // Cache token
        file_put_contents($token_cache, json_encode([
            'access_token' => $this->access_token,
            'expires_at' => $now + ($token_data['expires_in'] ?? 3500),
        ]));

        return $this->access_token;
    }

    /**
     * Make authenticated API request
     */
    private function apiRequest($url, $method = 'GET', $body = null) {
        $token = $this->getAccessToken();

        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($method === 'POST' && $body) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            return ['error' => true, 'http_code' => $code, 'response' => $response];
        }

        return json_decode($response, true);
    }

    /**
     * Get cached data or fetch fresh
     */
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
     * Search Performance (clicks, impressions, CTR, position)
     * @param int $days Number of days to look back
     */
    public function getSearchPerformance($days = 28) {
        return $this->getCached("performance_{$days}d", function() use ($days) {
            $end = date('Y-m-d');
            $start = date('Y-m-d', strtotime("-{$days} days"));
            $site = urlencode($this->site_url);

            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query",
                'POST',
                [
                    'startDate' => $start,
                    'endDate' => $end,
                    'dimensions' => ['date'],
                    'rowLimit' => 5000,
                ]
            );
        });
    }

    /**
     * Top queries (keywords)
     */
    public function getTopQueries($days = 28, $limit = 50) {
        return $this->getCached("queries_{$days}d", function() use ($days, $limit) {
            $end = date('Y-m-d');
            $start = date('Y-m-d', strtotime("-{$days} days"));
            $site = urlencode($this->site_url);

            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query",
                'POST',
                [
                    'startDate' => $start,
                    'endDate' => $end,
                    'dimensions' => ['query'],
                    'rowLimit' => $limit,
                    'orderBy' => 'clicks',
                ]
            );
        });
    }

    /**
     * Top pages by clicks
     */
    public function getTopPages($days = 28, $limit = 50) {
        return $this->getCached("pages_{$days}d", function() use ($days, $limit) {
            $end = date('Y-m-d');
            $start = date('Y-m-d', strtotime("-{$days} days"));
            $site = urlencode($this->site_url);

            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query",
                'POST',
                [
                    'startDate' => $start,
                    'endDate' => $end,
                    'dimensions' => ['page'],
                    'rowLimit' => $limit,
                    'orderBy' => 'clicks',
                ]
            );
        });
    }

    /**
     * Indexing status - URL Inspection (batch)
     */
    public function getIndexingStatus($urls = []) {
        $results = [];
        foreach (array_slice($urls, 0, 20) as $url) {
            $site = urlencode($this->site_url);
            $data = $this->apiRequest(
                "https://searchconsole.googleapis.com/v1/urlInspection/index:inspect",
                'POST',
                [
                    'inspectionUrl' => $url,
                    'siteUrl' => $this->site_url,
                ]
            );
            $results[] = [
                'url' => $url,
                'result' => $data,
            ];
            usleep(200000); // 200ms delay between requests
        }
        return $results;
    }

    /**
     * Sitemaps status
     */
    public function getSitemaps() {
        return $this->getCached("sitemaps", function() {
            $site = urlencode($this->site_url);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$site}/sitemaps"
            );
        });
    }

    /**
     * Get all data for dashboard display
     */
    public function getDashboardData($days = 28) {
        return [
            'performance' => $this->getSearchPerformance($days),
            'top_queries' => $this->getTopQueries($days),
            'top_pages'   => $this->getTopPages($days),
            'sitemaps'    => $this->getSitemaps(),
            'timestamp'   => date('Y-m-d H:i:s'),
            'days'        => $days,
        ];
    }
}
