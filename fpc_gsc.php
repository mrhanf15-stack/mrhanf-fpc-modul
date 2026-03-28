<?php
/**
 * Mr. Hanf FPC - Google Search Console Integration v2.0
 *
 * MAXIMUM API usage: All available GSC endpoints and dimensions.
 *
 * Features:
 *   - Search Performance (clicks, impressions, CTR, position)
 *   - Dimensions: date, query, page, country, device, searchAppearance
 *   - Search Types: web, image, video, news, discover
 *   - Sitemaps: list, status, errors, indexed counts
 *   - URL Inspection: index status of sample URLs
 *   - Time range selection: 7d, 28d, 90d, 6m, 12m, 16m
 *   - Caching of all API responses
 *
 * @version   2.0.0
 * @date      2026-03-28
 */

class FPC_GoogleSearchConsole {

    private $service_account_file;
    private $site_url;
    private $cache_dir;
    private $cache_ttl;
    private $access_token;

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

    // ============================================================
    // AUTH
    // ============================================================

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
        if (!$sa || !isset($sa['private_key'])) {
            throw new Exception('Invalid Service Account JSON file');
        }

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
        if (!$key) {
            throw new Exception('Failed to parse private key from Service Account JSON');
        }
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
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new Exception('cURL error getting token: ' . $err);
        }

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

    // ============================================================
    // HTTP
    // ============================================================

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
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['error' => true, 'http_code' => 0, 'response' => $err];
        }
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

    private function siteEncoded() {
        return urlencode($this->site_url);
    }

    private function dateRange($days) {
        return [
            'start' => date('Y-m-d', strtotime("-{$days} days")),
            'end'   => date('Y-m-d', strtotime('-2 days')), // GSC data has 2-day delay
        ];
    }

    // ============================================================
    // SEARCH ANALYTICS - All dimension combinations
    // ============================================================

    /**
     * Daily performance (clicks, impressions, CTR, position by date)
     */
    public function getPerformanceByDate($days = 28, $type = 'web') {
        return $this->getCached("perf_date_{$days}d_{$type}", function() use ($days, $type) {
            $range = $this->dateRange($days);
            $body = [
                'startDate'  => $range['start'],
                'endDate'    => $range['end'],
                'dimensions' => ['date'],
                'rowLimit'   => 25000,
                'type'       => $type,
            ];
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', $body
            );
        });
    }

    /**
     * Top queries (keywords) with clicks, impressions, CTR, position
     */
    public function getTopQueries($days = 28, $limit = 100, $type = 'web') {
        return $this->getCached("queries_{$days}d_{$type}", function() use ($days, $limit, $type) {
            $range = $this->dateRange($days);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate'  => $range['start'],
                    'endDate'    => $range['end'],
                    'dimensions' => ['query'],
                    'rowLimit'   => $limit,
                    'type'       => $type,
                ]
            );
        });
    }

    /**
     * Top pages by clicks
     */
    public function getTopPages($days = 28, $limit = 100, $type = 'web') {
        return $this->getCached("pages_{$days}d_{$type}", function() use ($days, $limit, $type) {
            $range = $this->dateRange($days);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate'  => $range['start'],
                    'endDate'    => $range['end'],
                    'dimensions' => ['page'],
                    'rowLimit'   => $limit,
                    'type'       => $type,
                ]
            );
        });
    }

    /**
     * Performance by country
     */
    public function getByCountry($days = 28, $type = 'web') {
        return $this->getCached("country_{$days}d_{$type}", function() use ($days, $type) {
            $range = $this->dateRange($days);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate'  => $range['start'],
                    'endDate'    => $range['end'],
                    'dimensions' => ['country'],
                    'rowLimit'   => 250,
                    'type'       => $type,
                ]
            );
        });
    }

    /**
     * Performance by device (Desktop, Mobile, Tablet)
     */
    public function getByDevice($days = 28, $type = 'web') {
        return $this->getCached("device_{$days}d_{$type}", function() use ($days, $type) {
            $range = $this->dateRange($days);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate'  => $range['start'],
                    'endDate'    => $range['end'],
                    'dimensions' => ['device'],
                    'rowLimit'   => 10,
                    'type'       => $type,
                ]
            );
        });
    }

    /**
     * Performance by search appearance (Rich Results, AMP, etc.)
     */
    public function getBySearchAppearance($days = 28) {
        return $this->getCached("appearance_{$days}d", function() use ($days) {
            $range = $this->dateRange($days);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate'  => $range['start'],
                    'endDate'    => $range['end'],
                    'dimensions' => ['searchAppearance'],
                    'rowLimit'   => 50,
                ]
            );
        });
    }

    /**
     * Performance across all search types (web, image, video, news, discover)
     */
    public function getBySearchType($days = 28) {
        $types = ['web', 'image', 'video', 'news', 'discover'];
        $results = [];
        foreach ($types as $type) {
            $data = $this->getCached("type_{$type}_{$days}d", function() use ($days, $type) {
                $range = $this->dateRange($days);
                return $this->apiRequest(
                    "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                    'POST', [
                        'startDate'  => $range['start'],
                        'endDate'    => $range['end'],
                        'dimensions' => [],
                        'type'       => $type,
                    ]
                );
            });
            if ($data && !isset($data['error']) && isset($data['rows'][0])) {
                $row = $data['rows'][0];
                $results[] = [
                    'type'        => $type,
                    'clicks'      => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'ctr'         => $row['ctr'] ?? 0,
                    'position'    => $row['position'] ?? 0,
                ];
            } else {
                $results[] = [
                    'type'        => $type,
                    'clicks'      => 0,
                    'impressions' => 0,
                    'ctr'         => 0,
                    'position'    => 0,
                ];
            }
        }
        return $results;
    }

    /**
     * Query + Page combination (which keywords drive which pages)
     */
    public function getQueryPageCombinations($days = 28, $limit = 50) {
        return $this->getCached("query_page_{$days}d", function() use ($days, $limit) {
            $range = $this->dateRange($days);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate'  => $range['start'],
                    'endDate'    => $range['end'],
                    'dimensions' => ['query', 'page'],
                    'rowLimit'   => $limit,
                ]
            );
        });
    }

    /**
     * Country + Device combination
     */
    public function getCountryDeviceCombinations($days = 28) {
        return $this->getCached("country_device_{$days}d", function() use ($days) {
            $range = $this->dateRange($days);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate'  => $range['start'],
                    'endDate'    => $range['end'],
                    'dimensions' => ['country', 'device'],
                    'rowLimit'   => 250,
                ]
            );
        });
    }

    // ============================================================
    // SITEMAPS
    // ============================================================

    public function getSitemaps() {
        return $this->getCached("sitemaps", function() {
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/sitemaps"
            );
        });
    }

    // ============================================================
    // URL INSPECTION
    // ============================================================

    /**
     * Inspect a batch of URLs (max 20 per call, API limit ~2000/day)
     */
    public function inspectUrls($urls = []) {
        $cache_key = 'inspection_' . md5(implode(',', $urls));
        return $this->getCached($cache_key, function() use ($urls) {
            $results = [];
            foreach (array_slice($urls, 0, 20) as $url) {
                $data = $this->apiRequest(
                    "https://searchconsole.googleapis.com/v1/urlInspection/index:inspect",
                    'POST', [
                        'inspectionUrl' => $url,
                        'siteUrl'       => $this->site_url,
                        'languageCode'  => 'de-DE',
                    ]
                );
                $result = ['url' => $url];
                if ($data && !isset($data['error']) && isset($data['inspectionResult'])) {
                    $ir = $data['inspectionResult'];
                    $idx = $ir['indexStatusResult'] ?? [];
                    $result['verdict']       = $idx['verdict'] ?? 'UNKNOWN';
                    $result['coverageState'] = $idx['coverageState'] ?? 'Unknown';
                    $result['robotsTxt']     = $idx['robotsTxtState'] ?? 'Unknown';
                    $result['indexing']      = $idx['indexingState'] ?? 'Unknown';
                    $result['lastCrawl']     = $idx['lastCrawlTime'] ?? '';
                    $result['crawledAs']     = $idx['crawledAs'] ?? '';
                    $result['pageFetch']     = $idx['pageFetchState'] ?? '';
                    $result['canonical']     = $idx['userCanonical'] ?? '';
                    $result['googleCanonical'] = $idx['googleCanonical'] ?? '';
                    // Mobile usability
                    $mob = $ir['mobileUsabilityResult'] ?? [];
                    $result['mobileVerdict'] = $mob['verdict'] ?? 'UNKNOWN';
                    $result['mobileIssues']  = $mob['issues'] ?? [];
                    // Rich results
                    $rich = $ir['richResultsResult'] ?? [];
                    $result['richVerdict']   = $rich['verdict'] ?? 'UNKNOWN';
                    $result['richItems']     = $rich['detectedItems'] ?? [];
                } else {
                    $result['verdict'] = 'ERROR';
                    $result['coverageState'] = isset($data['error']) ? ($data['response'] ?? 'API Error') : 'No data';
                }
                $results[] = $result;
                usleep(300000); // 300ms delay
            }
            return ['urls' => $results, 'timestamp' => date('Y-m-d H:i:s')];
        });
    }

    // ============================================================
    // AGGREGATED TOTALS (for KPIs)
    // ============================================================

    /**
     * Get totals for a period (no dimensions = aggregated)
     */
    public function getTotals($days = 28, $type = 'web') {
        return $this->getCached("totals_{$days}d_{$type}", function() use ($days, $type) {
            $range = $this->dateRange($days);
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate' => $range['start'],
                    'endDate'   => $range['end'],
                    'type'      => $type,
                ]
            );
        });
    }

    /**
     * Compare two periods for trend calculation
     */
    public function getComparison($days = 28, $type = 'web') {
        $current = $this->getTotals($days, $type);
        $previous = $this->getCached("totals_prev_{$days}d_{$type}", function() use ($days, $type) {
            $end = date('Y-m-d', strtotime("-" . ($days + 2) . " days"));
            $start = date('Y-m-d', strtotime("-" . ($days * 2 + 2) . " days"));
            return $this->apiRequest(
                "https://www.googleapis.com/webmasters/v3/sites/{$this->siteEncoded()}/searchAnalytics/query",
                'POST', [
                    'startDate' => $start,
                    'endDate'   => $end,
                    'type'      => $type,
                ]
            );
        });

        $cur = (isset($current['rows'][0])) ? $current['rows'][0] : ['clicks'=>0,'impressions'=>0,'ctr'=>0,'position'=>0];
        $prev = (isset($previous['rows'][0])) ? $previous['rows'][0] : ['clicks'=>0,'impressions'=>0,'ctr'=>0,'position'=>0];

        $pctChange = function($c, $p) { return $p > 0 ? round(($c - $p) / $p * 100, 1) : 0; };

        return [
            'current' => $cur,
            'previous' => $prev,
            'changes' => [
                'clicks'      => $pctChange($cur['clicks'], $prev['clicks']),
                'impressions' => $pctChange($cur['impressions'], $prev['impressions']),
                'ctr'         => $pctChange($cur['ctr'], $prev['ctr']),
                'position'    => round(($cur['position'] ?? 0) - ($prev['position'] ?? 0), 1),
            ],
        ];
    }

    // ============================================================
    // FULL DASHBOARD DATA
    // ============================================================

    /**
     * Get ALL data for maximum dashboard display
     */
    public function getDashboardData($days = 28) {
        $comparison = $this->getComparison($days);

        return [
            'comparison'        => $comparison,
            'performance'       => $this->getPerformanceByDate($days),
            'top_queries'       => $this->getTopQueries($days, 100),
            'top_pages'         => $this->getTopPages($days, 100),
            'countries'         => $this->getByCountry($days),
            'devices'           => $this->getByDevice($days),
            'search_appearance' => $this->getBySearchAppearance($days),
            'search_types'      => $this->getBySearchType($days),
            'query_pages'       => $this->getQueryPageCombinations($days, 50),
            'sitemaps'          => $this->getSitemaps(),
            'timestamp'         => date('Y-m-d H:i:s'),
            'days'              => $days,
            'date_range'        => $this->dateRange($days),
        ];
    }

    /**
     * URL Inspection for specific URLs (separate call, expensive)
     */
    public function getInspectionData($urls) {
        return $this->inspectUrls($urls);
    }
}
