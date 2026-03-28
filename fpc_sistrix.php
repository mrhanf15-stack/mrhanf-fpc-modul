<?php
/**
 * Mr. Hanf FPC - SISTRIX Integration v1.2
 *
 * Fetches visibility index and history from SISTRIX API.
 * Optimized for SISTRIX Plus plan (only SI endpoints).
 *
 * @version   1.2.0
 * @date      2026-03-28
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

    /**
     * Get dashboard data - only SI + History + Credits
     * Saves API credits by not calling unavailable endpoints
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
}
