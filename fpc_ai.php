<?php
/**
 * Mr. Hanf FPC AI Analyzer v1.0.0
 *
 * KI-gestuetzte SEO-Analyse Engine fuer das FPC Dashboard.
 * Nutzt OpenAI API (GPT-4.1-mini) fuer automatische Analysen und Chat.
 * Sammelt Daten aus allen APIs (GSC, GA4, Sistrix, FPC, SEO) und
 * erstellt priorisierte Empfehlungen.
 *
 * Features:
 *   - Automatische SEO-Analyse mit Cross-API Korrelation
 *   - Chat-Funktion fuer gezielte Fragen
 *   - Priorisierte Empfehlungen mit Aktions-Buttons
 *   - Kontext-bewusste Analyse (kennt alle Dashboard-Daten)
 *   - Antwort-Caching fuer wiederkehrende Analysen
 *
 * @version   1.0.0
 * @date      2026-03-28
 */

class FpcAi {

    private $api_key;
    private $model;
    private $base_dir;
    private $cache_dir;
    private $chat_history_file;
    private $analysis_cache_file;

    // System-Prompt fuer den SEO-Analysten
    private $system_prompt = <<<'PROMPT'
Du bist ein spezialisierter SEO-Experte fuer mr-hanf.de, einen der groessten europaeischen Online-Shops fuer Cannabis-Samen.
Du hast tiefes Wissen ueber E-Commerce SEO, Cannabis-Branche, internationale Maerkte (DE, AT, CH, NL, ES, IT, FR, UK) und die besonderen Herausforderungen dieser Nische.

Der Shop laeuft auf modified eCommerce (PHP) mit einem eigenen Full Page Cache (FPC) System.
Du analysierst Daten aus: Google Search Console, Google Analytics 4, Sistrix, dem FPC System und dem integrierten SEO-Scanner.

Dein Spezialwissen:
- Cannabis-Samen Keywords und Suchintentionen (autoflowering, feminisiert, regular, CBD, THC-arm)
- Saisonale Trends (Outdoor-Saison Fruehling, Indoor ganzjaehrig)
- Mehrsprachige SEO-Strategie (hreflang, Sprachversionen)
- E-Commerce Conversion-Optimierung (Produktseiten, Kategorien, Checkout)
- Content-Strategie fuer Cannabis-Nische (Grow-Guides, Strain-Reviews, Anbau-Tipps)
- Technisches SEO (Core Web Vitals, Crawl-Budget, Indexierung, Cache-Optimierung)
- Wettbewerber-Analyse (Sensi Seeds, Royal Queen Seeds, Zamnesia, Linda Seeds)
- Rechtliche Aspekte (Werbeeinschraenkungen, Laender-Regulierung)

Deine Aufgaben:
1. Probleme identifizieren und nach SEO-Impact priorisieren
2. Konkrete, umsetzbare Empfehlungen mit geschaetztem Traffic-Impact geben
3. Cross-API Korrelationen erkennen (z.B. GSC-Traffic-Verlust + 404-Fehler + Sistrix-Drop)
4. Redirect-Vorschlaege mit konkreten Quell- und Ziel-URLs machen
5. Canonical-Probleme erkennen und Fixes vorschlagen
6. Content-Luecken identifizieren (fehlende Kategorie-Texte, duenne Produktbeschreibungen)
7. Keyword-Kannibalisierung erkennen (mehrere Seiten ranken fuer gleiche Keywords)
8. E-Commerce spezifische Probleme finden (Produkt-URLs, Filter-URLs, Paginierung)
9. Cache-Performance mit SEO korrelieren (langsame Seiten = schlechteres Ranking)
10. Saisonale Empfehlungen geben (z.B. Outdoor-Saison Content vorbereiten)

Antworte IMMER auf Deutsch.
Sei direkt, praxisorientiert und gib konkrete Handlungsanweisungen.
Antworte im JSON-Format wenn eine Analyse angefordert wird.
Bei Chat-Fragen antworte in normalem Text, aber strukturiert und mit konkreten Beispielen.

Fuer Analyse-Antworten nutze dieses JSON-Format:
{
  "summary": "Kurze Zusammenfassung der Analyse",
  "score_assessment": "Bewertung des Health Scores",
  "critical_issues": [
    {
      "title": "Problem-Titel",
      "description": "Detaillierte Beschreibung",
      "affected_url": "/pfad/zur/seite/",
      "impact": "high|medium|low",
      "action_type": "redirect|canonical|content|technical",
      "action_details": {
        "source": "/alte-url/",
        "target": "/neue-url/",
        "type": "301"
      },
      "data_sources": ["gsc", "ga4", "scan"]
    }
  ],
  "recommendations": [
    {
      "title": "Empfehlung",
      "description": "Was zu tun ist",
      "priority": "high|medium|low",
      "effort": "low|medium|high",
      "expected_impact": "Beschreibung des erwarteten Effekts"
    }
  ],
  "positive_findings": ["Positive Beobachtung 1", "Positive Beobachtung 2"]
}
PROMPT;

    public function __construct($base_dir) {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->cache_dir = $this->base_dir . 'cache/fpc/seo/';

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755, true);
        }

        $this->chat_history_file = $this->cache_dir . 'ai_chat_history.json';
        $this->analysis_cache_file = $this->cache_dir . 'ai_analysis_cache.json';

        // API-Credentials laden
        $creds_file = $this->base_dir . 'cache/fpc/api_credentials.json';
        $creds = array();
        if (is_file($creds_file)) {
            $creds = @json_decode(file_get_contents($creds_file), true);
        }
        $this->api_key = isset($creds['openai_api_key']) ? $creds['openai_api_key'] : '';
        $this->model = isset($creds['openai_model']) && !empty($creds['openai_model'])
            ? $creds['openai_model'] : 'gpt-4.1-mini';
    }

    /**
     * Pruefen ob API konfiguriert ist
     */
    public function isConfigured() {
        return !empty($this->api_key);
    }

    // ================================================================
    // DATEN-SAMMLER: Alle APIs aggregieren
    // ================================================================

    /**
     * Alle verfuegbaren Daten sammeln fuer KI-Kontext
     */    private function collectAllData() {
        $data = array();

        // 1. SEO Engine Daten
        try {
            require_once $this->base_dir . 'fpc_seo.php';
            $seo = new FpcSeo($this->base_dir);
            $data['seo'] = $seo->getAiSummary();
            $data['seo_problems'] = $seo->getCrossApiProblems();
        } catch (Exception $e) {
            $data['seo'] = array('error' => $e->getMessage());
        }

        // 2. GSC Daten (wenn verfuegbar)
        try {
            require_once $this->base_dir . 'fpc_gsc.php';
            $creds = @json_decode(file_get_contents($this->base_dir . 'cache/fpc/api_credentials.json'), true);
            if (!empty($creds['gsc_service_account']) && is_file($this->base_dir . $creds['gsc_service_account'])) {
                $gsc = new FPC_GoogleSearchConsole($this->base_dir . $creds['gsc_service_account'], isset($creds['gsc_site_url']) ? $creds['gsc_site_url'] : 'https://mr-hanf.de/');
                $comparison = $gsc->getComparison(28);
                $top_queries = $gsc->getTopQueries(28, 10);
                $top_pages = $gsc->getTopPages(28, 10);
                $data['gsc'] = array(
                    'total_clicks' => isset($comparison['current']['clicks']) ? $comparison['current']['clicks'] : 0,
                    'total_impressions' => isset($comparison['current']['impressions']) ? $comparison['current']['impressions'] : 0,
                    'avg_ctr' => round((isset($comparison['current']['ctr']) ? $comparison['current']['ctr'] : 0) * 100, 2) . '%',
                    'avg_position' => round(isset($comparison['current']['position']) ? $comparison['current']['position'] : 0, 1),
                    'changes' => isset($comparison['changes']) ? $comparison['changes'] : array(),
                    'top_5_keywords' => array_slice(array_map(function($k) {
                        return (isset($k['keys'][0]) ? $k['keys'][0] : '?') . ' (Pos ' . round(isset($k['position']) ? $k['position'] : 0, 1) . ', ' . (isset($k['clicks']) ? $k['clicks'] : 0) . ' Clicks)';
                    }, is_array($top_queries) ? $top_queries : array()), 0, 5),
                    'top_5_pages' => array_slice(array_map(function($p) {
                        return (isset($p['keys'][0]) ? $p['keys'][0] : '?') . ' (' . (isset($p['clicks']) ? $p['clicks'] : 0) . ' Clicks)';
                    }, is_array($top_pages) ? $top_pages : array()), 0, 5),
                );
            }
        } catch (Exception $e) {
            $data['gsc'] = array('error' => $e->getMessage());
        }

        // 3. GA4 Daten (wenn verfuegbar)
        try {
            require_once $this->base_dir . 'fpc_ga4.php';
            $creds = @json_decode(file_get_contents($this->base_dir . 'cache/fpc/api_credentials.json'), true);
            if (!empty($creds['ga4_service_account']) && !empty($creds['ga4_property_id']) && is_file($this->base_dir . $creds['ga4_service_account'])) {
                $ga4 = new FPC_GoogleAnalytics4($this->base_dir . $creds['ga4_service_account'], $creds['ga4_property_id']);
                $ga4_comp = $ga4->getPeriodComparison(30);
                $data['ga4'] = array(
                    'sessions' => isset($ga4_comp['current']['sessions']) ? $ga4_comp['current']['sessions'] : 0,
                    'users' => isset($ga4_comp['current']['totalUsers']) ? $ga4_comp['current']['totalUsers'] : 0,
                    'pageviews' => isset($ga4_comp['current']['screenPageViews']) ? $ga4_comp['current']['screenPageViews'] : 0,
                    'bounce_rate' => round((isset($ga4_comp['current']['bounceRate']) ? $ga4_comp['current']['bounceRate'] : 0) * 100, 1) . '%',
                    'avg_duration' => round(isset($ga4_comp['current']['averageSessionDuration']) ? $ga4_comp['current']['averageSessionDuration'] : 0) . 's',
                );
                // E-Commerce Daten separat laden
                try {
                    $ecom = $ga4->getEcommerceOverview(30);
                    if (is_array($ecom)) {
                        $data['ga4']['revenue'] = isset($ecom['totalRevenue']) ? $ecom['totalRevenue'] : 0;
                        $data['ga4']['transactions'] = isset($ecom['transactions']) ? $ecom['transactions'] : 0;
                    }
                } catch (Exception $e2) { /* E-Commerce optional */ }
            }
        } catch (Exception $e) {
            $data['ga4'] = array('error' => $e->getMessage());
        }

        // 4. Sistrix Daten (wenn verfuegbar)
        try {
            require_once $this->base_dir . 'fpc_sistrix.php';
            $creds = @json_decode(file_get_contents($this->base_dir . 'cache/fpc/api_credentials.json'), true);
            if (!empty($creds['sistrix_api_key'])) {
                $domain = isset($creds['sistrix_domain']) ? $creds['sistrix_domain'] : 'mr-hanf.de';
                $sx = new FPC_Sistrix($creds['sistrix_api_key'], $domain);
                $si = $sx->getCurrentVisibility();
                if (is_array($si) && !isset($si['error'])) {
                    $data['sistrix'] = array(
                        'visibility_data' => $si,
                    );
                    // History separat laden
                    try {
                        $hist = $sx->getVisibilityHistory();
                        if (is_array($hist) && !isset($hist['error'])) {
                            $data['sistrix']['history'] = 'verfuegbar';
                        }
                    } catch (Exception $e3) { /* History optional */ }
                }
            }
        } catch (Exception $e) {
            $data['sistrix'] = array('error' => $e->getMessage());
        }

        // 5. FPC Cache Statistik
        $fpc_stats_file = $this->base_dir . 'cache/fpc/monitor.json';
        if (is_file($fpc_stats_file)) {
            $fpc_stats = @json_decode(file_get_contents($fpc_stats_file), true);
            if ($fpc_stats) {
                $data['fpc'] = array(
                    'cached_pages' => isset($fpc_stats['total_files']) ? $fpc_stats['total_files'] : 'unbekannt',
                    'hit_rate' => isset($fpc_stats['hit_rate']) ? $fpc_stats['hit_rate'] : 'unbekannt',
                );
            }
        }

        return $data;
    }

    // ================================================================
    // KI-ANALYSE
    // ================================================================

    /**
     * Vollstaendige SEO-Analyse durchfuehren
     */
    public function runAnalysis($force_refresh = false) {
        if (!$this->isConfigured()) {
            return array('error' => true, 'msg' => 'OpenAI API Key nicht konfiguriert. Gehe zu Settings > API Credentials.');
        }

        // Cache pruefen (1 Stunde)
        if (!$force_refresh && is_file($this->analysis_cache_file)) {
            $cache = @json_decode(file_get_contents($this->analysis_cache_file), true);
            if ($cache && isset($cache['timestamp']) && (time() - $cache['timestamp']) < 3600) {
                return $cache['result'];
            }
        }

        // Daten sammeln
        try {
            $all_data = $this->collectAllData();
        } catch (Exception $e) {
            $all_data = array('error' => 'Datensammlung fehlgeschlagen: ' . $e->getMessage());
        }

        // Prompt bauen
        $user_prompt = "Fuehre eine vollstaendige SEO-Analyse fuer mr-hanf.de durch.\n\n";
        $user_prompt .= "Hier sind die aktuellen Daten aus allen Quellen:\n\n";
        $user_prompt .= json_encode($all_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user_prompt .= "\n\nAnalysiere die Daten, finde Probleme, erkenne Cross-API Korrelationen ";
        $user_prompt .= "und gib priorisierte Empfehlungen im JSON-Format.";

        // OpenAI API aufrufen
        $response = $this->callOpenAI($this->system_prompt, $user_prompt);

        if (isset($response['error'])) {
            return $response;
        }

        // Versuche JSON aus der Antwort zu extrahieren
        $result = $this->parseAiResponse($response['content']);

        // Cache speichern
        file_put_contents($this->analysis_cache_file, json_encode(array(
            'timestamp' => time(),
            'result' => $result,
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $result;
    }

    // ================================================================
    // KI-CHAT
    // ================================================================

    /**
     * Chat-Nachricht senden und Antwort erhalten
     */
    public function chat($message) {
        if (!$this->isConfigured()) {
            return array('error' => true, 'msg' => 'OpenAI API Key nicht konfiguriert.');
        }

        // Kontext-Daten sammeln (kompakt) - Fehler abfangen
        try {
            $context_data = $this->collectAllData();
        } catch (Exception $e) {
            $context_data = array('error' => 'Datensammlung fehlgeschlagen: ' . $e->getMessage());
        }

        // Chat-History laden
        $history = $this->getChatHistory();

        // System-Prompt mit Kontext erweitern
        $system = $this->system_prompt . "\n\n";
        $system .= "AKTUELLE DATEN (Stand: " . date('Y-m-d H:i') . "):\n";
        $system .= json_encode($context_data, JSON_UNESCAPED_UNICODE);
        $system .= "\n\nDer User stellt dir eine Frage. Antworte praezise und hilfreich auf Deutsch.";
        $system .= " Wenn du Aktionen vorschlaegst (Redirect, Canonical), formatiere sie klar.";

        // Messages-Array bauen mit History
        $messages = array(
            array('role' => 'system', 'content' => $system),
        );

        // Letzte 10 Chat-Nachrichten als Kontext
        $recent = array_slice($history, -10);
        foreach ($recent as $h) {
            $messages[] = array('role' => 'user', 'content' => $h['question']);
            $messages[] = array('role' => 'assistant', 'content' => $h['answer']);
        }

        $messages[] = array('role' => 'user', 'content' => $message);

        // API aufrufen
        $response = $this->callOpenAIMessages($messages);

        if (isset($response['error'])) {
            return $response;
        }

        $answer = $response['content'];

        // Chat-History speichern
        $this->addChatHistory($message, $answer);

        return array(
            'ok' => true,
            'answer' => $answer,
            'timestamp' => date('Y-m-d H:i:s'),
        );
    }

    /**
     * Chat-History laden
     */
    public function getChatHistory() {
        if (!is_file($this->chat_history_file)) return array();
        $data = @json_decode(file_get_contents($this->chat_history_file), true);
        return is_array($data) ? $data : array();
    }

    /**
     * Chat-Nachricht zur History hinzufuegen
     */
    private function addChatHistory($question, $answer) {
        $history = $this->getChatHistory();
        $history[] = array(
            'question' => $question,
            'answer' => $answer,
            'timestamp' => date('Y-m-d H:i:s'),
        );
        // Max 50 Eintraege
        if (count($history) > 50) $history = array_slice($history, -50);
        file_put_contents($this->chat_history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Chat-History loeschen
     */
    public function clearChatHistory() {
        if (is_file($this->chat_history_file)) @unlink($this->chat_history_file);
        return array('ok' => true, 'msg' => 'Chat-History geloescht');
    }

    // ================================================================
    // OPENAI API
    // ================================================================

    /**
     * OpenAI API aufrufen (einfach: system + user)
     */
    private function callOpenAI($system_prompt, $user_prompt) {
        return $this->callOpenAIMessages(array(
            array('role' => 'system', 'content' => $system_prompt),
            array('role' => 'user', 'content' => $user_prompt),
        ));
    }

    /**
     * OpenAI API aufrufen (mit Messages-Array)
     */
    private function callOpenAIMessages($messages) {
        $url = 'https://api.openai.com/v1/chat/completions';

        $payload = json_encode(array(
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 8000,
        ));

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key,
            ),
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return array('error' => true, 'msg' => 'cURL Fehler: ' . $error);
        }
        curl_close($ch);

        $data = json_decode($response, true);

        if ($http_code !== 200) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'HTTP ' . $http_code;
            return array('error' => true, 'msg' => 'OpenAI API Fehler: ' . $error_msg);
        }

        if (!isset($data['choices'][0]['message']['content'])) {
            return array('error' => true, 'msg' => 'Unerwartete API-Antwort');
        }

        return array(
            'ok' => true,
            'content' => $data['choices'][0]['message']['content'],
            'usage' => isset($data['usage']) ? $data['usage'] : null,
        );
    }

    // ================================================================
    // HELPER
    // ================================================================

    /**
     * KI-Antwort parsen (JSON extrahieren wenn moeglich)
     */
    private function parseAiResponse($content) {
        // Versuche JSON zu extrahieren
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if ($json_start !== false && $json_end !== false) {
            $json_str = substr($content, $json_start, $json_end - $json_start + 1);
            $parsed = @json_decode($json_str, true);
            if ($parsed) {
                return array(
                    'ok' => true,
                    'type' => 'analysis',
                    'data' => $parsed,
                    'raw' => $content,
                    'timestamp' => date('Y-m-d H:i:s'),
                );
            }
        }

        // Fallback: Text-Antwort
        return array(
            'ok' => true,
            'type' => 'text',
            'data' => null,
            'raw' => $content,
            'timestamp' => date('Y-m-d H:i:s'),
        );
    }

    /**
     * Schnelle Problem-Zusammenfassung (ohne API-Call, nur lokale Daten)
     */
    public function getQuickSummary() {
        require_once $this->base_dir . 'fpc_seo.php';
        $seo = new FpcSeo($this->base_dir);
        $ist = $seo->getIstZustand();

        $alerts = array();

        // Kritische 404s
        if ($ist['log_404']['unresolved'] > 20) {
            $alerts[] = array('level' => 'critical', 'msg' => $ist['log_404']['unresolved'] . ' ungeloeste 404-Fehler');
        } elseif ($ist['log_404']['unresolved'] > 5) {
            $alerts[] = array('level' => 'warning', 'msg' => $ist['log_404']['unresolved'] . ' ungeloeste 404-Fehler');
        }

        // Health Score
        if ($ist['health']['score'] < 50) {
            $alerts[] = array('level' => 'critical', 'msg' => 'Health Score nur ' . $ist['health']['score'] . '%');
        } elseif ($ist['health']['score'] < 75) {
            $alerts[] = array('level' => 'warning', 'msg' => 'Health Score bei ' . $ist['health']['score'] . '%');
        }

        // Canonical Mismatches
        if ($ist['health']['canonical_mismatches'] > 5) {
            $alerts[] = array('level' => 'warning', 'msg' => $ist['health']['canonical_mismatches'] . ' Canonical Mismatches');
        }

        // Scan-Fehler
        if ($ist['health']['errors'] > 0) {
            $alerts[] = array('level' => 'critical', 'msg' => $ist['health']['errors'] . ' URLs mit HTTP-Fehlern');
        }

        return array(
            'health_score' => $ist['health']['score'],
            'alerts' => $alerts,
            'stats' => array(
                'redirects' => $ist['redirects']['active'],
                'canonicals' => $ist['canonicals']['active'],
                'unresolved_404' => $ist['log_404']['unresolved'],
                'scan_errors' => $ist['health']['errors'],
                'scan_warnings' => $ist['health']['warnings'],
            ),
            'ai_configured' => $this->isConfigured(),
        );
    }
}
