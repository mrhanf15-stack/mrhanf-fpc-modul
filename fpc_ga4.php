<?php
/**
 * Mr. Hanf FPC - Google Analytics 4 Integration v2.0 (MAXIMUM API)
 *
 * Fetches ALL available data from GA4 Data API v1:
 *   - Daily Traffic (sessions, users, pageviews, bounce, engagement, duration)
 *   - Top Pages with full metrics
 *   - Landing Pages analysis
 *   - Traffic Sources (source/medium)
 *   - Channel Groups (Organic, Direct, Referral, etc.)
 *   - Device Breakdown (desktop, mobile, tablet)
 *   - Browser Statistics
 *   - Operating System Statistics
 *   - Screen Resolution
 *   - Country & City breakdown
 *   - Language distribution
 *   - New vs Returning Users
 *   - Hourly Traffic Pattern
 *   - Day of Week Pattern
 *   - E-Commerce: Revenue, Transactions, AOV
 *   - E-Commerce: Top Products
 *   - E-Commerce: Shopping Funnel (view > cart > checkout > purchase)
 *   - Events Overview
 *   - Key Events (Conversions)
 *   - Realtime Active Users
 *   - Period Comparison (current vs previous)
 *   - Configurable time range (7d, 28d, 30d, 90d, 180d, 365d)
 *
 * @version   2.0.0
 * @date      2026-03-28
 */

class FPC_GoogleAnalytics4 {

    private $service_account_file;
    private $property_id;
    private $cache_dir;
    private $cache_ttl;
    private $access_token;

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

    private function realtimeRequest($body) {
        $token = $this->getAccessToken();
        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runRealtimeReport";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            return ['error' => true, 'http_code' => $code, 'response' => $response];
        }

        return json_decode($response, true);
    }

    private function getCached($key, $fetcher, $ttl = null) {
        $file = $this->cache_dir . $key . '.json';
        $use_ttl = $ttl ?: $this->cache_ttl;
        if (is_file($file) && (time() - filemtime($file)) < $use_ttl) {
            return json_decode(file_get_contents($file), true);
        }
        $data = $fetcher();
        if ($data && !isset($data['error'])) {
            file_put_contents($file, json_encode($data));
        }
        return $data;
    }

    // ================================================================
    // TRAFFIC & ENGAGEMENT REPORTS
    // ================================================================

    public function getDailyTraffic($days = 30) {
        return $this->getCached("daily_traffic_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'date']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'engagementRate'],
                    ['name' => 'bounceRate'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'sessionsPerUser'],
                    ['name' => 'screenPageViewsPerSession'],
                ],
                'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
                'limit' => 500,
            ]);
        });
    }

    public function getPeriodComparison($days = 30) {
        return $this->getCached("comparison_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [
                    ['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday', 'name' => 'current'],
                    ['startDate' => ($days * 2) . "daysAgo", 'endDate' => ($days + 1) . "daysAgo", 'name' => 'previous'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'engagementRate'],
                    ['name' => 'bounceRate'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'sessionsPerUser'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                ],
                'metricAggregations' => ['TOTAL'],
            ]);
        });
    }

    public function getTopPages($days = 30, $limit = 100) {
        return $this->getCached("top_pages_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'pagePath']],
                'metrics' => [
                    ['name' => 'screenPageViews'],
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'bounceRate'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'engagementRate'],
                    ['name' => 'keyEvents'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    public function getLandingPages($days = 30, $limit = 50) {
        return $this->getCached("landing_pages_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'landingPage']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'bounceRate'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'engagementRate'],
                    ['name' => 'keyEvents'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    // ================================================================
    // ACQUISITION REPORTS
    // ================================================================

    public function getTrafficSources($days = 30, $limit = 30) {
        return $this->getCached("sources_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'sessionSourceMedium']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'bounceRate'],
                    ['name' => 'engagementRate'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'keyEvents'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    public function getChannelGroups($days = 30) {
        return $this->getCached("channels_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'engagementRate'],
                    ['name' => 'bounceRate'],
                    ['name' => 'keyEvents'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            ]);
        });
    }

    public function getNewVsReturning($days = 30) {
        return $this->getCached("new_returning_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'newVsReturning']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'engagementRate'],
                    ['name' => 'bounceRate'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'screenPageViewsPerSession'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                ],
            ]);
        });
    }

    // ================================================================
    // DEMOGRAPHICS & TECHNOLOGY
    // ================================================================

    public function getDeviceBreakdown($days = 30) {
        return $this->getCached("devices_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'deviceCategory']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'bounceRate'],
                    ['name' => 'engagementRate'],
                    ['name' => 'averageSessionDuration'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                ],
            ]);
        });
    }

    public function getBrowsers($days = 30, $limit = 15) {
        return $this->getCached("browsers_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'browser']],
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

    public function getOperatingSystems($days = 30, $limit = 10) {
        return $this->getCached("os_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'operatingSystem']],
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

    public function getScreenResolutions($days = 30, $limit = 15) {
        return $this->getCached("screens_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'screenResolution']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    public function getCountries($days = 30, $limit = 30) {
        return $this->getCached("countries_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'country']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'engagementRate'],
                    ['name' => 'bounceRate'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    public function getCities($days = 30, $limit = 30) {
        return $this->getCached("cities_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'city']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    public function getLanguages($days = 30, $limit = 15) {
        return $this->getCached("languages_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'language']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    // ================================================================
    // TIME PATTERNS
    // ================================================================

    public function getHourlyTraffic($days = 7) {
        return $this->getCached("hourly_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'hour']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'totalUsers'],
                ],
                'orderBys' => [['dimension' => ['dimensionName' => 'hour']]],
            ]);
        });
    }

    public function getDayOfWeekTraffic($days = 30) {
        return $this->getCached("dow_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'dayOfWeek']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'totalUsers'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                ],
                'orderBys' => [['dimension' => ['dimensionName' => 'dayOfWeek']]],
            ]);
        });
    }

    // ================================================================
    // E-COMMERCE REPORTS
    // ================================================================

    public function getEcommerceOverview($days = 30) {
        return $this->getCached("ecom_overview_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'date']],
                'metrics' => [
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                    ['name' => 'averagePurchaseRevenue'],
                    ['name' => 'transactions'],
                    ['name' => 'addToCarts'],
                    ['name' => 'checkouts'],
                    ['name' => 'itemViews'],
                    ['name' => 'cartToViewRate'],
                    ['name' => 'purchaseToViewRate'],
                ],
                'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
                'limit' => 500,
            ]);
        });
    }

    public function getTopProducts($days = 30, $limit = 50) {
        return $this->getCached("top_products_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'itemName']],
                'metrics' => [
                    ['name' => 'itemViews'],
                    ['name' => 'addToCarts'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'itemRevenue'],
                    ['name' => 'itemsPurchased'],
                    ['name' => 'cartToViewRate'],
                    ['name' => 'purchaseToViewRate'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'itemRevenue'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    public function getProductCategories($days = 30, $limit = 30) {
        return $this->getCached("product_cats_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'itemCategory']],
                'metrics' => [
                    ['name' => 'itemViews'],
                    ['name' => 'addToCarts'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'itemRevenue'],
                    ['name' => 'itemsPurchased'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'itemRevenue'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    public function getShoppingFunnel($days = 30) {
        return $this->getCached("funnel_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'metrics' => [
                    ['name' => 'itemViews'],
                    ['name' => 'addToCarts'],
                    ['name' => 'checkouts'],
                    ['name' => 'ecommercePurchases'],
                    ['name' => 'purchaseRevenue'],
                    ['name' => 'cartToViewRate'],
                    ['name' => 'purchaseToViewRate'],
                ],
                'metricAggregations' => ['TOTAL'],
            ]);
        });
    }

    // ================================================================
    // EVENTS & CONVERSIONS
    // ================================================================

    public function getEventsOverview($days = 30, $limit = 30) {
        return $this->getCached("events_{$days}d", function() use ($days, $limit) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'eventName']],
                'metrics' => [
                    ['name' => 'eventCount'],
                    ['name' => 'totalUsers'],
                    ['name' => 'eventCountPerUser'],
                ],
                'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]],
                'limit' => $limit,
            ]);
        });
    }

    public function getKeyEvents($days = 30) {
        return $this->getCached("key_events_{$days}d", function() use ($days) {
            return $this->apiRequest([
                'dateRanges' => [['startDate' => "{$days}daysAgo", 'endDate' => 'yesterday']],
                'dimensions' => [['name' => 'eventName'], ['name' => 'isKeyEvent']],
                'metrics' => [
                    ['name' => 'eventCount'],
                    ['name' => 'totalUsers'],
                ],
                'dimensionFilter' => [
                    'filter' => [
                        'fieldName' => 'isKeyEvent',
                        'stringFilter' => ['value' => 'true', 'matchType' => 'EXACT'],
                    ],
                ],
                'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]],
            ]);
        });
    }

    // ================================================================
    // REALTIME
    // ================================================================

    public function getRealtimeData() {
        // Realtime has short cache (60s)
        return $this->getCached('realtime', function() {
            return $this->realtimeRequest([
                'dimensions' => [
                    ['name' => 'unifiedScreenName'],
                ],
                'metrics' => [
                    ['name' => 'activeUsers'],
                ],
                'limit' => 20,
            ]);
        }, 60);
    }

    public function getRealtimeByCountry() {
        return $this->getCached('realtime_country', function() {
            return $this->realtimeRequest([
                'dimensions' => [
                    ['name' => 'country'],
                ],
                'metrics' => [
                    ['name' => 'activeUsers'],
                ],
                'limit' => 15,
            ]);
        }, 60);
    }

    public function getRealtimeByDevice() {
        return $this->getCached('realtime_device', function() {
            return $this->realtimeRequest([
                'dimensions' => [
                    ['name' => 'deviceCategory'],
                ],
                'metrics' => [
                    ['name' => 'activeUsers'],
                ],
            ]);
        }, 60);
    }

    public function getRealtimeBySource() {
        return $this->getCached('realtime_source', function() {
            return $this->realtimeRequest([
                'dimensions' => [
                    ['name' => 'unifiedScreenName'],
                    ['name' => 'audienceName'],
                ],
                'metrics' => [
                    ['name' => 'activeUsers'],
                ],
                'limit' => 20,
            ]);
        }, 60);
    }

    // ================================================================
    // DASHBOARD AGGREGATION
    // ================================================================

    public function getDashboardData($days = 30) {
        return [
            'daily_traffic'      => $this->getDailyTraffic($days),
            'comparison'         => $this->getPeriodComparison($days),
            'top_pages'          => $this->getTopPages($days),
            'landing_pages'      => $this->getLandingPages($days),
            'devices'            => $this->getDeviceBreakdown($days),
            'browsers'           => $this->getBrowsers($days),
            'operating_systems'  => $this->getOperatingSystems($days),
            'screen_resolutions' => $this->getScreenResolutions($days),
            'traffic_sources'    => $this->getTrafficSources($days),
            'channel_groups'     => $this->getChannelGroups($days),
            'new_vs_returning'   => $this->getNewVsReturning($days),
            'countries'          => $this->getCountries($days),
            'cities'             => $this->getCities($days),
            'languages'          => $this->getLanguages($days),
            'hourly'             => $this->getHourlyTraffic(min($days, 7)),
            'day_of_week'        => $this->getDayOfWeekTraffic($days),
            'ecommerce'          => $this->getEcommerceOverview($days),
            'top_products'       => $this->getTopProducts($days),
            'product_categories' => $this->getProductCategories($days),
            'shopping_funnel'    => $this->getShoppingFunnel($days),
            'events'             => $this->getEventsOverview($days),
            'key_events'         => $this->getKeyEvents($days),
            'realtime'           => $this->getRealtimeData(),
            'realtime_country'   => $this->getRealtimeByCountry(),
            'realtime_device'    => $this->getRealtimeByDevice(),
            'timestamp'          => date('Y-m-d H:i:s'),
            'days'               => $days,
            'date_range'         => [
                'start' => date('Y-m-d', strtotime("-{$days} days")),
                'end'   => date('Y-m-d', strtotime('-1 day')),
            ],
        ];
    }
}
