<?php
/**
 * Mr. Hanf FPC - Google Analytics 4 Integration v1.0
 *
 * Fetches traffic data, pageviews, sessions, bounce rate, devices
 * from Google Analytics 4 Data API and caches results locally.
 *
 * Requirements:
 *   - Google Service Account JSON key file (same as GSC)
 *   - Service Account added as Viewer in GA4 property
 *   - GA4 Property ID
 *
 * @version   1.0.0
 * @date      2026-03-28
 */

class FPC_GoogleAnalytics4 {

    private $service_account_file;
    private $property_id;
    private $cache_dir;
    private $cache_ttl;
    private $access_token;

    /**
     * @param string $service_account_file Path to Google Service Account JSON
     * @param string $property_id          GA4 Property ID (numeric, e.g. "123456789")
     * @param string $cache_dir            Directory for caching API responses
     * @param int    $cache_ttl            Cache TTL in seconds (default 3600 = 1h)
     */
    public function __construct($service_account_file, $property_id, $cache_dir = null, $cache_ttl = 3600) {
        $this->service_account_file = $service_account_file;
        $this->property_id = $property_id;
        $this->cache_dir = $cache_dir ?: dirname(__FILE__) . '/cache/fpc/ga4/';
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

        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claims = base64_encode(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ]));

        $signing_input = str_replace(['+', '/', '='], ['-', '_', ''], $header) . '.' .
                         str_replace(['+', '/', '='], ['-', '_', ''], $claims);

        $key = openssl_pkey_get_private($sa['private_key']);
        openssl_sign($signing_input, $signature, $key, 'SHA256');
        $jwt = $signing_input . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

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
        file_put_contents($token_cache, json_encode([
            'access_token' => $this->access_token,
            'expires_at' => $now + ($token_data['expires_in'] ?? 3500),
        ]));

        return $this->access_token;
    }

    /**
     * Make GA4 Data API request
     */
    private function apiRequest($body) {
        $token = $this->getAccessToken();
        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
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
     * Daily traffic overview (sessions, users, pageviews, bounce rate)
     */
    public function getDailyTraffic($days = 30) {
        return $this->getCached("daily_traffic_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [['name' => 'date']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'bounceRate'],
                    ['name' => 'averageSessionDuration'],
                ],
                'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
            ]);
        });
    }

    /**
     * Top pages by pageviews
     */
    public function getTopPages($days = 30, $limit = 50) {
        return $this->getCached("top_pages_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [['name' => 'pagePath']],
                'metrics' => [
                    ['name' => 'screenPageViews'],
                    ['name' => 'sessions'],
                    ['name' => 'bounceRate'],
                    ['name' => 'averageSessionDuration'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    /**
     * Traffic by device category
     */
    public function getDeviceBreakdown($days = 30) {
        return $this->getCached("devices_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [['name' => 'deviceCategory']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'bounceRate'],
                ],
            ]);
        });
    }

    /**
     * Traffic by source/medium
     */
    public function getTrafficSources($days = 30, $limit = 20) {
        return $this->getCached("sources_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [['name' => 'sessionSourceMedium']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'bounceRate'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    /**
     * Traffic by country
     */
    public function getCountries($days = 30, $limit = 20) {
        return $this->getCached("countries_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'today']],
                'dimensions' => [['name' => 'country']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    /**
     * Hourly traffic pattern (today)
     */
    public function getHourlyTraffic() {
        return $this->getCached("hourly_today", function() {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => 'today', 'endDate' => 'today']],
                'dimensions' => [['name' => 'hour']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                ],
                'orderBys' => [['dimension' => ['dimensionName' => 'hour']]],
            ]);
        });
    }

    /**
     * Get all data for dashboard display
     */
    public function getDashboardData($days = 30) {
        return [
            'daily_traffic'   => $this->getDailyTraffic($days),
            'top_pages'       => $this->getTopPages($days),
            'devices'         => $this->getDeviceBreakdown($days),
            'traffic_sources' => $this->getTrafficSources($days),
            'countries'       => $this->getCountries($days),
            'hourly'          => $this->getHourlyTraffic(),
            'timestamp'       => date('Y-m-d H:i:s'),
            'days'            => $days,
        ];
    }
}
