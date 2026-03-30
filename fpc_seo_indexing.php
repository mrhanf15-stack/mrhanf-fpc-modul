<?php
/**
 * Mr. Hanf FPC - Google Indexing API Manager v1.0.0
 *
 * Verwaltet die Google Indexing API fuer sofortige URL-Indexierung.
 * Nutzt denselben Service Account wie GSC.
 *
 * Features:
 *   - Einzelne URL einreichen (URL_UPDATED / URL_DELETED)
 *   - Bulk-Einreichung (max 200/Tag)
 *   - Quota-Tracking (200 Calls/Tag)
 *   - Vollstaendiges Log aller Einreichungen
 *   - Status-Abfrage einzelner URLs
 *
 * @version   1.0.0
 * @date      2026-03-30
 */

class FpcSeoIndexing {

    private $base_dir;
    private $cache_dir;
    private $log_file;
    private $quota_file;
    private $api_endpoint = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    private $batch_endpoint = 'https://indexing.googleapis.com/batch';
    private $status_endpoint = 'https://indexing.googleapis.com/v3/urlNotifications/metadata';

    private $service_account;
    private $access_token;
    private $daily_limit = 200;

    public function __construct($base_dir) {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->cache_dir = $this->base_dir . 'cache/fpc/seo/';
        $this->log_file = $this->cache_dir . 'indexing_log.json';
        $this->quota_file = $this->cache_dir . 'indexing_quota.json';

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755, true);
        }

        // Service Account laden (gleicher wie GSC)
        $creds_file = $this->base_dir . 'api/fpc/api_credentials.json';
        if (is_file($creds_file)) {
            $creds = @json_decode(file_get_contents($creds_file), true);
            if (isset($creds['gsc_service_account_file'])) {
                $sa_file = $this->base_dir . $creds['gsc_service_account_file'];
                if (is_file($sa_file)) {
                    $this->service_account = json_decode(file_get_contents($sa_file), true);
                }
            }
        }
    }

    /**
     * Pruefen ob Indexing API konfiguriert ist
     */
    public function isConfigured() {
        return !empty($this->service_account) && isset($this->service_account['private_key']);
    }

    // ================================================================
    // AUTH: JWT / Access Token
    // ================================================================

    /**
     * Access Token via JWT generieren (Indexing API Scope)
     */
    private function getAccessToken() {
        if ($this->access_token) return $this->access_token;
        if (!$this->isConfigured()) return null;

        $sa = $this->service_account;
        $now = time();

        // JWT Header
        $header = base64_encode(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));

        // JWT Claim Set - mit Indexing API Scope
        $claim = base64_encode(json_encode(array(
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/indexing',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        )));

        // JWT Signatur
        $signature_input = $header . '.' . $claim;
        $signature = '';
        openssl_sign($signature_input, $signature, $sa['private_key'], 'SHA256');
        $signature = base64_encode($signature);

        // URL-safe Base64
        $jwt = str_replace(array('+', '/', '='), array('-', '_', ''), $header)
            . '.' . str_replace(array('+', '/', '='), array('-', '_', ''), $claim)
            . '.' . str_replace(array('+', '/', '='), array('-', '_', ''), $signature);

        // Token anfordern
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            )),
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['access_token'])) {
            $this->access_token = $response['access_token'];
            return $this->access_token;
        }

        return null;
    }

    // ================================================================
    // URL EINREICHEN
    // ================================================================

    /**
     * Einzelne URL zur Indexierung einreichen
     * @param string $url Vollstaendige URL
     * @param string $type 'URL_UPDATED' oder 'URL_DELETED'
     * @return array Ergebnis
     */
    public function submitUrl($url, $type = 'URL_UPDATED') {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'msg' => 'Indexing API nicht konfiguriert. Service Account benoetigt den Scope "indexing.googleapis.com".');
        }

        // Quota pruefen
        $quota = $this->getQuota();
        if ($quota['used_today'] >= $this->daily_limit) {
            return array('ok' => false, 'msg' => 'Tages-Limit erreicht (' . $this->daily_limit . ' URLs/Tag). Naechster Reset: morgen 00:00 UTC.');
        }

        // URL normalisieren
        if (strpos($url, 'http') !== 0) {
            $url = 'https://mr-hanf.de' . (strpos($url, '/') === 0 ? '' : '/') . $url;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return array('ok' => false, 'msg' => 'Auth fehlgeschlagen. Pruefen Sie ob der Service Account den Indexing API Scope hat.');
        }

        // API Request
        $payload = json_encode(array(
            'url' => $url,
            'type' => $type,
        ));

        $ch = curl_init($this->api_endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ),
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result_data = json_decode($response, true);

        $log_entry = array(
            'url' => $url,
            'type' => $type,
            'timestamp' => date('Y-m-d H:i:s'),
            'http_status' => $http_code,
            'success' => ($http_code >= 200 && $http_code < 300),
            'response' => $result_data,
        );

        // Log speichern
        $this->addLogEntry($log_entry);

        // Quota aktualisieren
        if ($log_entry['success']) {
            $this->incrementQuota();
        }

        if ($log_entry['success']) {
            return array(
                'ok' => true,
                'msg' => 'URL erfolgreich eingereicht: ' . $url,
                'type' => $type,
                'notify_time' => isset($result_data['urlNotificationMetadata']['latestUpdate']['notifyTime'])
                    ? $result_data['urlNotificationMetadata']['latestUpdate']['notifyTime']
                    : null,
            );
        } else {
            $error_msg = 'API Fehler (HTTP ' . $http_code . ')';
            if (isset($result_data['error']['message'])) {
                $error_msg = $result_data['error']['message'];
            }
            // Spezifische Fehlermeldungen
            if ($http_code === 403) {
                $error_msg .= ' — Der Service Account hat keinen Zugriff auf die Indexing API. Bitte aktivieren Sie die "Web Search Indexing API" in der Google Cloud Console und fuegen Sie den Service Account als Inhaber in der Search Console hinzu.';
            }
            return array('ok' => false, 'msg' => $error_msg, 'url' => $url);
        }
    }

    /**
     * Bulk-Einreichung mehrerer URLs
     * @param array $urls Array von URLs
     * @param string $type 'URL_UPDATED' oder 'URL_DELETED'
     */
    public function submitBatch($urls, $type = 'URL_UPDATED') {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'msg' => 'Indexing API nicht konfiguriert');
        }

        $quota = $this->getQuota();
        $remaining = $this->daily_limit - $quota['used_today'];

        if ($remaining <= 0) {
            return array('ok' => false, 'msg' => 'Tages-Limit erreicht');
        }

        // URLs begrenzen auf verfuegbare Quota
        $urls = array_slice($urls, 0, $remaining);

        $results = array(
            'total' => count($urls),
            'success' => 0,
            'failed' => 0,
            'details' => array(),
        );

        foreach ($urls as $url) {
            $result = $this->submitUrl($url, $type);
            if (isset($result['ok']) && $result['ok']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            $results['details'][] = $result;
            usleep(500000); // 500ms Pause zwischen Requests
        }

        return array(
            'ok' => true,
            'msg' => $results['success'] . ' von ' . $results['total'] . ' URLs erfolgreich eingereicht',
            'results' => $results,
        );
    }

    // ================================================================
    // URL STATUS
    // ================================================================

    /**
     * Status einer URL bei Google abfragen
     */
    public function getUrlStatus($url) {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'msg' => 'Nicht konfiguriert');
        }

        if (strpos($url, 'http') !== 0) {
            $url = 'https://mr-hanf.de' . (strpos($url, '/') === 0 ? '' : '/') . $url;
        }

        $token = $this->getAccessToken();
        if (!$token) return array('ok' => false, 'msg' => 'Auth fehlgeschlagen');

        $api_url = $this->status_endpoint . '?url=' . urlencode($url);

        $ch = curl_init($api_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $token,
            ),
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            $data = json_decode($response, true);
            return array(
                'ok' => true,
                'url' => $data['url'] ?? $url,
                'latest_update' => $data['latestUpdate'] ?? null,
                'latest_remove' => $data['latestRemove'] ?? null,
            );
        }

        return array('ok' => false, 'msg' => 'Status konnte nicht abgefragt werden (HTTP ' . $http_code . ')');
    }

    // ================================================================
    // QUOTA MANAGEMENT
    // ================================================================

    /**
     * Aktuelle Quota abrufen
     */
    public function getQuota() {
        $today = date('Y-m-d');
        $data = array('date' => $today, 'used_today' => 0, 'daily_limit' => $this->daily_limit);

        if (is_file($this->quota_file)) {
            $saved = json_decode(file_get_contents($this->quota_file), true);
            if (is_array($saved) && isset($saved['date'])) {
                if ($saved['date'] === $today) {
                    $data['used_today'] = $saved['used_today'];
                }
                // Sonst: Neuer Tag, Quota zuruecksetzen
            }
        }

        $data['remaining'] = max(0, $this->daily_limit - $data['used_today']);
        $data['percentage_used'] = round(($data['used_today'] / $this->daily_limit) * 100, 1);

        return $data;
    }

    private function incrementQuota() {
        $quota = $this->getQuota();
        $quota['used_today']++;
        file_put_contents($this->quota_file, json_encode($quota, JSON_PRETTY_PRINT));
    }

    // ================================================================
    // LOG MANAGEMENT
    // ================================================================

    /**
     * Indexing Log abrufen
     * @param int $limit Max Eintraege
     * @param string $filter 'all', 'success', 'failed'
     */
    public function getLog($limit = 100, $filter = 'all') {
        if (!is_file($this->log_file)) return array();

        $log = json_decode(file_get_contents($this->log_file), true);
        if (!is_array($log)) return array();

        // Filtern
        if ($filter === 'success') {
            $log = array_filter($log, function($e) { return $e['success']; });
        } elseif ($filter === 'failed') {
            $log = array_filter($log, function($e) { return !$e['success']; });
        }

        // Neueste zuerst, limitieren
        $log = array_reverse($log);
        return array_slice($log, 0, $limit);
    }

    /**
     * Log-Statistiken
     */
    public function getLogStats() {
        $log = $this->getLog(10000, 'all');
        $stats = array(
            'total' => count($log),
            'success' => 0,
            'failed' => 0,
            'today' => 0,
            'this_week' => 0,
            'this_month' => 0,
            'by_type' => array('URL_UPDATED' => 0, 'URL_DELETED' => 0),
        );

        $today = date('Y-m-d');
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $month_start = date('Y-m-01');

        foreach ($log as $entry) {
            if ($entry['success']) $stats['success']++;
            else $stats['failed']++;

            $date = substr($entry['timestamp'], 0, 10);
            if ($date === $today) $stats['today']++;
            if ($date >= $week_start) $stats['this_week']++;
            if ($date >= $month_start) $stats['this_month']++;

            if (isset($entry['type']) && isset($stats['by_type'][$entry['type']])) {
                $stats['by_type'][$entry['type']]++;
            }
        }

        return $stats;
    }

    private function addLogEntry($entry) {
        $log = array();
        if (is_file($this->log_file)) {
            $log = json_decode(file_get_contents($this->log_file), true);
            if (!is_array($log)) $log = array();
        }

        $log[] = $entry;

        // Max 5000 Eintraege
        if (count($log) > 5000) {
            $log = array_slice($log, -5000);
        }

        file_put_contents($this->log_file, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Setup-Anleitung fuer die Indexing API
     */
    public function getSetupGuide() {
        return array(
            'title' => 'Google Indexing API einrichten',
            'steps' => array(
                array(
                    'step' => 1,
                    'title' => 'Web Search Indexing API aktivieren',
                    'description' => 'Gehen Sie zur Google Cloud Console (console.cloud.google.com), waehlen Sie Ihr Projekt und aktivieren Sie die "Web Search Indexing API" unter APIs & Services > Library.',
                ),
                array(
                    'step' => 2,
                    'title' => 'Service Account berechtigen',
                    'description' => 'Der bestehende GSC Service Account kann wiederverwendet werden. Stellen Sie sicher, dass er den Scope "https://www.googleapis.com/auth/indexing" hat.',
                ),
                array(
                    'step' => 3,
                    'title' => 'Service Account als Inhaber hinzufuegen',
                    'description' => 'In der Google Search Console: Einstellungen > Nutzer und Berechtigungen > den Service Account (E-Mail) als "Inhaber" hinzufuegen.',
                ),
                array(
                    'step' => 4,
                    'title' => 'Testen',
                    'description' => 'Klicken Sie auf "URL testen" im Indexing API Tab, um die Konfiguration zu pruefen.',
                ),
            ),
            'note' => 'Das taegliche Limit betraegt 200 URL-Benachrichtigungen. Fuer die meisten Shops ist das ausreichend.',
        );
    }
}
