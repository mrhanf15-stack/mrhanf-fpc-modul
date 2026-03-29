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
    private $config_dir;
    private $chat_history_file;
    private $analysis_cache_file;

    // System-Prompt fuer den SEO-Analysten
    // v1.1.0: Prompt kann ueber fpc_settings.json -> ai_prompt ueberschrieben werden
    private $system_prompt_file; // Pfad zur externen Prompt-Datei
    private $system_prompt_default = <<<'PROMPT'
=== ROLLE UND IDENTITAET ===
Du bist der SEO-Analyst und technische Berater fuer mr-hanf.de, einen der groessten europaeischen Online-Shops fuer Cannabis-Samen mit ueber 28.000 Produktseiten in mehreren Sprachen (DE, EN, NL, ES, IT, FR).

Du arbeitest als integrierter KI-Assistent im FPC Control Center Dashboard. Der Shop-Betreiber nutzt dich fuer:
- Automatische SEO-Analysen (Button "Analyse starten")
- Direkte Chat-Fragen zu SEO, Performance und Shop-Optimierung
- Priorisierte Handlungsempfehlungen mit konkreten Aktionen

=== SHOP-KONTEXT ===
Plattform: modified eCommerce (PHP 8.x) mit eigenem Full Page Cache (FPC) System
Domain: mr-hanf.de (Hauptdomain) + Sprachversionen
Branche: Cannabis-Samen E-Commerce (legal in DE seit 2024)
Zielgruppe: Anfaenger bis erfahrene Grower, primaer DACH-Raum, sekundaer EU
Produkte: ~3.000+ Cannabis-Samen Sorten (Autoflowering, Feminisiert, Regular, CBD)
Kategorien: Samen-Shop, Seedbanks, Grow-Equipment, Zubehoer
Wettbewerber: Sensi Seeds, Royal Queen Seeds, Zamnesia, Linda Seeds, Herbies Seeds

=== TECHNISCHE ARCHITEKTUR (WICHTIG - NICHT ALS FEHLER MELDEN) ===
Das FPC-System besteht aus diesen Dateien im Shop-Root:
- fpc_serve.php: Hauptdatei die gecachte Seiten ausliefert (via .htaccess eingebunden)
- fpc_preloader.php: Cron-Job der Seiten vorlaedt und cached
- fpc_seo.php: SEO-Scanner, Redirect-Manager, 404-Log, Canonical-Manager
- fpc_dashboard.php: Admin-Dashboard (im geschuetzten admin-Ordner)

WICHTIG: /fpc_serve.php ist KEIN Fehler! Diese Datei wird bei JEDEM Seitenaufruf
vom Webserver aufgerufen. 404-Hits auf /fpc_serve.php bedeuten, dass der Cache
fuer bestimmte Seiten fehlt - das ist normales Verhalten, KEIN Redirect noetig!

=== URL-STRUKTUR DES SHOPS ===
Produkte koennen unter mehreren Kategorie-Pfaden erreichbar sein:
- /samen-shop/autoflowering-samen/PRODUKT (Eltern-Kategorie)
- /samen-shop/autoflowering-samen/feminisierte-auto-sorten/PRODUKT (Sub-Kategorie)
Der Canonical zeigt immer auf die Sub-Kategorie-URL - das ist KORREKT!
Dies ist KEIN Canonical-Mismatch, sondern gewolltes Verhalten des Shops.
Ein Canonical-Mismatch liegt nur vor wenn der Canonical auf eine voellig andere
Seite zeigt (z.B. Kategorie statt Produkt, oder anderes Produkt).

=== SYSTEM-URLS DIE IGNORIERT WERDEN MUESSEN ===
Diese URLs sind KEINE SEO-Probleme und brauchen KEINEN Redirect:
- /fpc_serve.php → FPC Cache-Auslieferung (System-Datei)
- /.well-known/assetlinks.json → Android App-Verknuepfung (System)
- /.well-known/passkey-endpoints → WebAuthn/Passkey (System)
- /.well-known/* → Alle .well-known URLs sind System-URLs
- /favicon.ico → Browser-Standard-Anfrage
- /robots.txt → Crawler-Standard-Anfrage
- /sitemap*.xml → Sitemap-Dateien
- /admin_* → Admin-Bereich
- /cache/* → Cache-Verzeichnis
Wenn diese URLs 404-Fehler zeigen, erwaehne sie NICHT als kritische Probleme.
Konzentriere dich auf echte Shop-URLs (Produkte, Kategorien, Content-Seiten).

=== DATENQUELLEN DIE DIR ZUR VERFUEGUNG STEHEN ===
Dir werden bei jeder Anfrage aktuelle Daten aus diesen Quellen uebergeben:

1. SEO-SCANNER (fpc_seo):
   - Health Score (0-100%): Gesamtbewertung der technischen SEO-Gesundheit
   - Redirect-Manager: Aktive 301/302 Redirects, Redirect-Hits, Redirect-Ketten
   - 404-Log: Ungeloeste 404-Fehler mit Hit-Zaehler und Top-URLs
   - Canonical-Overrides: Manuell gesetzte Canonicals und Mismatches
   - URL-Scanner: HTTP-Status aller gescannten URLs, Fehler, Warnungen
   - Cross-API Probleme: Automatisch erkannte Korrelationen zwischen APIs

2. GOOGLE SEARCH CONSOLE (GSC):
   - Klicks, Impressions, CTR, durchschnittliche Position (28 Tage)
   - Veraenderungen zum Vormonat (Trends)
   - Top-5 Keywords mit Position und Klicks
   - Top-5 Seiten mit Klicks

3. GOOGLE ANALYTICS 4 (GA4):
   - Sessions, Users, Pageviews, Bounce Rate, Avg. Session Duration
   - E-Commerce: Revenue, Transactions (wenn verfuegbar)

4. SISTRIX:
   - Sichtbarkeitsindex (aktuell + Verlauf)
   - Sichtbarkeits-Trend

5. FPC CACHE:
   - Anzahl gecachter Seiten, Hit-Rate
   - Cache-Abdeckung (gecacht vs. gesamt)

=== ANALYSE-AUFGABEN (bei "Analyse starten") ===
Wenn eine Analyse angefordert wird, fuehre diese Schritte systematisch durch:

1. GESUNDHEITS-CHECK:
   - Health Score bewerten (>80% = gut, 60-80% = Handlungsbedarf, <60% = kritisch)
   - Anzahl und Schwere der Fehler einordnen
   - Vergleich mit letzter Analyse wenn moeglich

2. TRAFFIC-ANALYSE:
   - GSC Klick- und Impression-Trends bewerten
   - CTR analysieren (Branchendurchschnitt Cannabis: ~2-4%)
   - Position-Veraenderungen identifizieren
   - GA4 Session/User Trends korrelieren

3. TECHNISCHE PROBLEME:
   - 404-Fehler mit hohen Hits priorisieren (besonders wenn GSC-Impressions vorhanden)
   - Redirect-Ketten und -Schleifen erkennen
   - Canonical-Mismatches identifizieren
   - HTTP-Fehler im Scan (5xx, 4xx) bewerten
   - Cache-Abdeckung pruefen (nicht gecachte wichtige Seiten)

4. CROSS-API KORRELATIONEN (WICHTIGSTE AUFGABE):
   - URLs mit GSC-Impressions die 404 sind -> KRITISCH, sofort Redirect erstellen
   - URLs mit hohen Impressions aber niedriger CTR -> Meta-Title/Description optimieren
   - Seiten mit hoher Bounce Rate (GA4) + niedrigem Ranking (GSC) -> Content verbessern
   - Sistrix-Sichtbarkeits-Drop + GSC-Klick-Verlust -> Ursache identifizieren
   - Nicht gecachte Seiten mit viel Traffic -> Cache-Prioritaet erhoehen

5. CONTENT-BEWERTUNG:
   - Fehlende Kategorie-Texte identifizieren
   - Duenne Produktbeschreibungen erkennen
   - Keyword-Kannibalisierung (mehrere Seiten fuer gleiche Keywords)
   - Content-Luecken fuer saisonale Themen

6. E-COMMERCE SPEZIFISCH:
   - Produkt-URL Struktur bewerten
   - Filter-URLs und Paginierung pruefen (Duplicate Content Risiko)
   - Conversion-Pfad analysieren (wenn GA4 E-Commerce Daten vorhanden)
   - Checkout-Seiten nicht im Cache (korrekt) bestaetigen

7. SAISONALE EMPFEHLUNGEN:
   - Fruehling (Maerz-Mai): Outdoor-Saison Content, Autoflowering-Guides
   - Sommer (Juni-Aug): Grow-Tipps, Ernte-Guides
   - Herbst (Sep-Nov): Indoor-Saison, Equipment-Content
   - Winter (Dez-Feb): Planung, Seed-Vergleiche, Neuheiten

=== CHAT-VERHALTEN ===
Bei Chat-Fragen:
- Antworte praezise und direkt auf Deutsch
- Gib konkrete Beispiele mit echten URLs wenn moeglich
- Wenn der User nach einem bestimmten Problem fragt, nutze die uebergebenen Daten
- Schlage konkrete Aktionen vor die im Dashboard ausgefuehrt werden koennen
- Bei Redirect-Vorschlaegen: Nenne immer Quell-URL und Ziel-URL
- Bei Content-Vorschlaegen: Nenne Keywords, Suchvolumen-Schaetzung und Seitentyp
- Vermeide allgemeine SEO-Tipps, sei spezifisch fuer mr-hanf.de
- Beruecksichtige die Cannabis-Branche (Werbeeinschraenkungen, Regulierung)

=== ANTWORT-FORMATE ===

Fuer ANALYSE-Antworten ("Analyse starten") IMMER dieses JSON-Format:
{
  "summary": "2-3 Saetze Zusammenfassung der wichtigsten Erkenntnisse",
  "score_assessment": "Bewertung des Health Scores mit Einordnung und Trend",
  "critical_issues": [
    {
      "title": "Kurzer Problem-Titel",
      "description": "Was ist das Problem und warum ist es wichtig?",
      "affected_url": "/konkrete/url/pfad/",
      "impact": "high|medium|low",
      "action_type": "redirect|canonical|content|technical|cache",
      "action_details": {
        "source": "/alte-url/",
        "target": "/neue-url/",
        "type": "301"
      },
      "data_sources": ["gsc", "ga4", "sistrix", "scan", "404log"]
    }
  ],
  "recommendations": [
    {
      "title": "Konkrete Empfehlung",
      "description": "Schritt-fuer-Schritt was zu tun ist",
      "priority": "high|medium|low",
      "effort": "low|medium|high",
      "expected_impact": "Geschaetzter Traffic/Ranking Effekt mit Zeitrahmen"
    }
  ],
  "positive_findings": ["Was laeuft gut - konkret mit Zahlen"]
}

Fuer CHAT-Antworten: Normaler Text, strukturiert mit Absaetzen. Keine JSON-Bloecke.

=== WICHTIGE REGELN ===
- IMMER auf Deutsch antworten
- KEINE allgemeinen SEO-Tipps - NUR spezifische, datenbasierte Empfehlungen
- IMMER konkrete URLs nennen wenn moeglich
- Prioritaet: Erst kritische Fehler (404 mit Traffic, Redirects), dann Optimierungen
- Bei Unsicherheit: Sage was du nicht weisst statt zu raten
- Beruecksichtige dass der User ein technisch versierter Shop-Betreiber ist
- Vermeide medizinische Heilversprechen in Content-Vorschlaegen
- Keine Slang-Begriffe - professionelle Cannabis-Fachsprache verwenden
- Meta-Titles max 80 Zeichen, informativ mit Key-Feature
- Autoflowering-Content: Zielgruppe Anfaenger, Saison Fruehling bis Ende August
PROMPT;

    private $system_prompt; // Aktiver Prompt (aus Datei oder Default)

    public function __construct($base_dir) {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->cache_dir = $this->base_dir . 'cache/fpc/seo/';

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755, true);
        }

        // v10.3.0: Config-Dateien in geschuetztem config-Ordner (nicht vom Flush betroffen)
        $this->config_dir = $this->base_dir . 'api/fpc/';
        if (!is_dir($this->config_dir)) @mkdir($this->config_dir, 0755, true);

        $this->chat_history_file = $this->config_dir . 'ai_chat_history.json';
        $this->analysis_cache_file = $this->cache_dir . 'ai_analysis_cache.json';
        $this->system_prompt_file = $this->config_dir . 'ai_system_prompt.txt';

        // v1.1.0: System-Prompt aus Datei laden (wenn vorhanden), sonst Default
        $this->system_prompt = $this->loadSystemPrompt();

        // API-Credentials laden
        $creds_file = $this->config_dir . 'api_credentials.json';
        // Migration: Alte Datei aus cache/fpc_config/ oder cache/fpc/ uebernehmen
        if (!is_file($creds_file)) {
            if (is_file($this->base_dir . 'cache/fpc_config/api_credentials.json')) {
                @copy($this->base_dir . 'cache/fpc_config/api_credentials.json', $creds_file);
            } elseif (is_file($this->base_dir . 'cache/fpc/api_credentials.json')) {
                @copy($this->base_dir . 'cache/fpc/api_credentials.json', $creds_file);
            }
        }
        $creds = array();
        if (is_file($creds_file)) {
            $creds = @json_decode(file_get_contents($creds_file), true);
        }
        $this->api_key = isset($creds['openai_api_key']) ? $creds['openai_api_key'] : '';
        $this->model = isset($creds['openai_model']) && !empty($creds['openai_model'])
            ? $creds['openai_model'] : 'gpt-4.1-mini';
    }

    /**
     * v1.1.0: System-Prompt laden
     * Prioritaet: 1. Externe Datei (ai_system_prompt.txt) 2. Default-Prompt
     */
    private function loadSystemPrompt() {
        if (is_file($this->system_prompt_file)) {
            $custom = @file_get_contents($this->system_prompt_file);
            if (!empty(trim($custom))) {
                return trim($custom);
            }
        }
        return $this->system_prompt_default;
    }

    /**
     * v1.1.0: System-Prompt speichern (aus Dashboard Settings)
     */
    public function saveSystemPrompt($prompt_text) {
        $prompt_text = trim($prompt_text);
        if (empty($prompt_text)) {
            // Leerer Prompt = zurueck zum Default
            if (is_file($this->system_prompt_file)) {
                @unlink($this->system_prompt_file);
            }
            $this->system_prompt = $this->system_prompt_default;
            return array('ok' => true, 'msg' => 'KI-Prompt auf Standard zurueckgesetzt', 'length' => strlen($this->system_prompt));
        }
        $dir = dirname($this->system_prompt_file);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ok = @file_put_contents($this->system_prompt_file, $prompt_text);
        if ($ok === false) {
            return array('ok' => false, 'msg' => 'Fehler beim Speichern des Prompts');
        }
        $this->system_prompt = $prompt_text;
        return array('ok' => true, 'msg' => 'KI-Prompt gespeichert (' . strlen($prompt_text) . ' Zeichen)', 'length' => strlen($prompt_text));
    }

    /**
     * v1.1.0: Aktuellen System-Prompt zurueckgeben (fuer Settings-Anzeige)
     */
    public function getSystemPrompt() {
        return $this->system_prompt;
    }

    /**
     * v1.1.0: Default System-Prompt zurueckgeben (fuer Reset-Button)
     */
    public function getDefaultSystemPrompt() {
        return $this->system_prompt_default;
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
            $creds = @json_decode(file_get_contents($this->config_dir . 'api_credentials.json'), true);
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
        // v10.2.4: Cache-Dir Parameter + bessere Fehlerbehandlung
        try {
            require_once $this->base_dir . 'fpc_ga4.php';
            $creds = @json_decode(file_get_contents($this->config_dir . 'api_credentials.json'), true);
            $ga4_sa = isset($creds['ga4_service_account']) ? $creds['ga4_service_account'] : '';
            $ga4_prop = isset($creds['ga4_property_id']) ? $creds['ga4_property_id'] : '';
            $ga4_sa_path = $this->base_dir . $ga4_sa;
            if (!empty($ga4_sa) && !empty($ga4_prop) && is_file($ga4_sa_path)) {
                $ga4_cache = $this->base_dir . 'cache/fpc/ga4/';
                $ga4 = new FPC_GoogleAnalytics4($ga4_sa_path, $ga4_prop, $ga4_cache);
                $ga4_comp = $ga4->getPeriodComparison(30);
                if (is_array($ga4_comp) && isset($ga4_comp['current'])) {
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
                } else {
                    $data['ga4'] = array('error' => 'getPeriodComparison lieferte keine Daten', 'raw' => $ga4_comp);
                }
            } else {
                $data['ga4'] = array('not_configured' => true, 'reason' => empty($ga4_sa) ? 'Service Account fehlt' : (empty($ga4_prop) ? 'Property ID fehlt' : 'Service Account Datei nicht gefunden: ' . $ga4_sa_path));
            }
        } catch (Exception $e) {
            $data['ga4'] = array('error' => $e->getMessage());
        }

        // 4. Sistrix Daten (wenn verfuegbar)
        try {
            require_once $this->base_dir . 'fpc_sistrix.php';
            $creds = @json_decode(file_get_contents($this->config_dir . 'api_credentials.json'), true);
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

    // ================================================================
    // v10.4.0: KI-REDIRECT-VORSCHLAEGE MIT SPRACH-GRUPPIERUNG
    // ================================================================

    /**
     * KI-gestuetzte Redirect-Vorschlaege fuer problematische URLs
     * Analysiert 301/302/404 URLs und schlaegt passende Ziele vor.
     * Erkennt Sprach-Varianten und gruppiert sie automatisch.
     *
     * @param array $urls Array von URLs mit HTTP-Status und Issues
     * @return array KI-Vorschlaege mit Sprach-Gruppierung
     */
    public function suggestRedirects($urls) {
        if (!$this->isConfigured()) {
            return array('error' => true, 'msg' => 'OpenAI API Key nicht konfiguriert.');
        }

        if (empty($urls)) {
            return array('error' => true, 'msg' => 'Keine URLs zum Analysieren.');
        }

        // Sprach-Prefixe erkennen und gruppieren
        $lang_prefixes = array('/en/', '/fr/', '/es/', '/nl/', '/it/');
        $groups = array();
        $ungrouped = array();

        foreach ($urls as $u) {
            $url = is_array($u) ? $u['url'] : $u;
            $found_lang = false;
            foreach ($lang_prefixes as $prefix) {
                if (strpos($url, $prefix) === 0) {
                    $base_path = substr($url, strlen($prefix) - 1); // z.B. /bushplanet/...
                    $lang = trim($prefix, '/');
                    if (!isset($groups[$base_path])) {
                        $groups[$base_path] = array('base' => $base_path, 'languages' => array(), 'urls' => array());
                    }
                    $groups[$base_path]['languages'][] = $lang;
                    $groups[$base_path]['urls'][] = $u;
                    $found_lang = true;
                    break;
                }
            }
            // Deutsche URLs (kein Prefix) oder unbekannte
            if (!$found_lang) {
                // Pruefen ob es eine Basis-URL ist die auch in anderen Sprachen existiert
                $base_path = $url;
                if (!isset($groups[$base_path])) {
                    $groups[$base_path] = array('base' => $base_path, 'languages' => array(), 'urls' => array());
                }
                $groups[$base_path]['languages'][] = 'de';
                $groups[$base_path]['urls'][] = $u;
            }
        }

        // Nur Gruppen mit > 1 Sprache sind echte Sprach-Gruppen
        $language_groups = array();
        $single_urls = array();
        foreach ($groups as $base => $group) {
            if (count($group['languages']) > 1) {
                $language_groups[] = $group;
            } else {
                foreach ($group['urls'] as $u) {
                    $single_urls[] = $u;
                }
            }
        }

        // Prompt fuer KI bauen
        $prompt = "Du bist der SEO-Analyst fuer mr-hanf.de (Cannabis-Samen Shop).\n";
        $prompt .= "Analysiere diese problematischen URLs und schlage passende 301-Redirect-Ziele vor.\n\n";
        $prompt .= "SHOP-STRUKTUR:\n";
        $prompt .= "- Hauptkategorien: /samen-shop/, /growshop/, /seedbanks/\n";
        $prompt .= "- Sprachen: DE (kein Prefix), EN (/en/), FR (/fr/), ES (/es/), NL (/nl/), IT (/it/)\n";
        $prompt .= "- Produkte: /samen-shop/KATEGORIE/PRODUKT/\n";
        $prompt .= "- Bushplanet: /bushplanet/ (Grow-Equipment)\n\n";

        if (!empty($language_groups)) {
            $prompt .= "SPRACH-GRUPPEN (gleiche Seite in verschiedenen Sprachen):\n";
            foreach ($language_groups as $i => $g) {
                $prompt .= ($i + 1) . ". Basis: " . $g['base'] . " (Sprachen: " . implode(', ', $g['languages']) . ")\n";
                foreach ($g['urls'] as $u) {
                    $url_str = is_array($u) ? $u['url'] : $u;
                    $status = is_array($u) && isset($u['http_status']) ? $u['http_status'] : '?';
                    $target = is_array($u) && isset($u['redirect_target']) ? $u['redirect_target'] : '';
                    $prompt .= "   - " . $url_str . " (HTTP " . $status;
                    if ($target) $prompt .= " → " . $target;
                    $prompt .= ")\n";
                }
            }
            $prompt .= "\n";
        }

        if (!empty($single_urls)) {
            $prompt .= "EINZELNE URLs:\n";
            foreach (array_slice($single_urls, 0, 30) as $i => $u) {
                $url_str = is_array($u) ? $u['url'] : $u;
                $status = is_array($u) && isset($u['http_status']) ? $u['http_status'] : '?';
                $target = is_array($u) && isset($u['redirect_target']) ? $u['redirect_target'] : '';
                $issues = is_array($u) && isset($u['issues']) ? implode(', ', $u['issues']) : '';
                $prompt .= ($i + 1) . ". " . $url_str . " (HTTP " . $status;
                if ($target) $prompt .= " → " . $target;
                if ($issues) $prompt .= " | " . $issues;
                $prompt .= ")\n";
            }
        }

        $prompt .= "\nAntworte im JSON-Format:\n";
        $prompt .= '{"suggestions": [{"source": "/alte-url/", "target": "/neue-url/", "type": "301", "reason": "Kurze Begruendung", "confidence": "high|medium|low", "language_group": [{"lang": "de", "source": "/alte-url/", "target": "/neue-url/"}, {"lang": "en", "source": "/en/alte-url/", "target": "/en/neue-url/"}]}]}';
        $prompt .= "\n\nWICHTIG:\n";
        $prompt .= "- Wenn eine URL bereits auf ein Ziel redirected (→), pruefe ob das Ziel korrekt ist\n";
        $prompt .= "- Bei Sprach-Gruppen: Schlage fuer JEDE Sprache den passenden Redirect vor\n";
        $prompt .= "- Wenn das Redirect-Ziel bereits korrekt aussieht, setze confidence=high\n";
        $prompt .= "- Bei 404-URLs: Versuche die naechstliegende existierende Seite zu finden\n";
        $prompt .= "- Bushplanet-URLs: Oft umbenannt zu Growshop-Kategorien\n";
        $prompt .= "- Gib NUR das JSON zurueck, keinen weiteren Text\n";

        $response = $this->callOpenAI(
            "Du bist ein SEO-Redirect-Experte fuer mr-hanf.de. Antworte NUR mit validem JSON.",
            $prompt
        );

        if (isset($response['error'])) {
            return $response;
        }

        // JSON parsen
        $content = $response['content'];
        $json_start = strpos($content, '{');
        $json_end = strrpos($content, '}');

        if ($json_start !== false && $json_end !== false) {
            $json_str = substr($content, $json_start, $json_end - $json_start + 1);
            $parsed = @json_decode($json_str, true);
            if ($parsed && isset($parsed['suggestions'])) {
                return array(
                    'ok' => true,
                    'suggestions' => $parsed['suggestions'],
                    'language_groups' => $language_groups,
                    'total_urls' => count($urls),
                    'grouped_urls' => array_sum(array_map(function($g) { return count($g['urls']); }, $language_groups)),
                    'timestamp' => date('Y-m-d H:i:s'),
                );
            }
        }

        return array(
            'ok' => true,
            'suggestions' => array(),
            'raw' => $content,
            'msg' => 'KI-Antwort konnte nicht als JSON geparst werden',
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

    // ================================================================
    // v10.4.1: UNIVERSELLER KI-ASSISTENT PRO TAB
    // ================================================================

    /**
     * Kontextbezogene KI-Analyse fuer einen bestimmten Dashboard-Tab.
     * Sammelt Tab-spezifische Daten und erstellt passende Vorschlaege.
     *
     * @param string $tab  Tab-Name (dashboard, performance, coverage, cache_tools, fehler, seo, health, gsc, ga4, sistrix, stats)
     * @param array  $context  Zusaetzliche Daten vom Frontend (z.B. aktuelle Filter, sichtbare Daten)
     * @return array KI-Antwort mit Vorschlaegen
     */
    public function analyzeTab($tab, $context = array()) {
        if (!$this->isConfigured()) {
            return array('error' => true, 'msg' => 'OpenAI API Key nicht konfiguriert. Gehe zu Settings > API Credentials.');
        }

        // Tab-spezifische Daten sammeln
        $tab_data = $this->collectTabData($tab, $context);

        // Tab-spezifischen Prompt bauen
        $tab_prompts = array(
            'dashboard' => "Analysiere die Dashboard-Uebersicht von mr-hanf.de. Bewerte die KPIs, erkenne Trends und gib 3-5 priorisierte Empfehlungen was als naechstes getan werden sollte. Fokus: Was ist am dringendsten?",
            'performance' => "Analysiere die Cache-Performance von mr-hanf.de. Bewerte Hit-Rate, TTFB, Miss-Gruende und gib konkrete Empfehlungen zur Verbesserung. Welche Seiten sollten priorisiert gecacht werden? Gibt es Muster bei Cache-Misses?",
            'coverage' => "Analysiere die Cache-Abdeckung von mr-hanf.de. Welche Kategorien haben schlechte Abdeckung? Welche wichtigen Seiten fehlen im Cache? Gib Empfehlungen zur Verbesserung der Coverage.",
            'cache_tools' => "Gib Empfehlungen fuer die Cache-Verwaltung. Welche URLs sollten priorisiert gecacht werden? Gibt es Custom-URLs die fehlen? Wie sollte der Preloader konfiguriert werden?",
            'fehler' => "Analysiere die Fehler und Probleme. Priorisiere die Fehler nach Schwere und Traffic-Impact. Welche 404-Fehler sollten zuerst behoben werden? Welche langsamen Seiten brauchen Optimierung? Gib fuer jeden Fehler eine konkrete Loesung.",
            'seo' => "Analysiere den SEO-Zustand von mr-hanf.de. Bewerte Redirects, 404-Fehler, Canonical-Probleme und Scan-Ergebnisse. Gib priorisierte Empfehlungen. Bei Redirects: Schlage konkrete Ziel-URLs vor. Bei 404s: Welche sind am dringendsten? Bei Scan-Ergebnissen: Welche Muster erkennst du?",
            'seo_404' => "Analysiere die 404-Fehler im Detail. Gruppiere sie nach Muster (z.B. gleiche Kategorie, gleiche Sprache). Schlage fuer jede 404-URL ein konkretes Redirect-Ziel vor. Priorisiere nach Anzahl der Aufrufe. Erkenne ob es Sprach-Varianten gibt die zusammen behoben werden koennen.",
            'seo_scan' => "Analysiere die Scan-Ergebnisse. Gruppiere problematische URLs nach Muster. Schlage fuer Redirects konkrete Ziel-URLs vor. Erkenne Sprach-Gruppen und empfehle Bulk-Aktionen. Welche URLs haben den groessten SEO-Impact?",
            'seo_redirects' => "Analysiere die bestehenden Redirects. Gibt es Redirect-Ketten? Fehlen wichtige Redirects? Gibt es Redirects die nicht mehr noetig sind? Gib Empfehlungen zur Optimierung.",
            'health' => "Analysiere den technischen Gesundheitszustand. Bewerte .htaccess, Layer-Status und Health-Check Details. Welche technischen Probleme sind am kritischsten? Gib konkrete Loesungsvorschlaege.",
            'gsc' => "Analysiere die Google Search Console Daten. Welche Keywords haben Potenzial (hohe Impressions, niedrige CTR)? Welche Seiten verlieren Rankings? Wo gibt es Quick-Wins? Gib konkrete SEO-Empfehlungen basierend auf den GSC-Daten.",
            'ga4' => "Analysiere die Google Analytics 4 Daten. Bewerte Traffic-Trends, Bounce-Rate, E-Commerce Performance. Welche Seiten performen schlecht? Wo gibt es Conversion-Potenzial? Gib datenbasierte Empfehlungen.",
            'sistrix' => "Analysiere die SISTRIX Sichtbarkeitsdaten. Wie entwickelt sich die Sichtbarkeit? Gibt es Einbrueche oder Anstiege? Was koennte die Ursache sein? Gib strategische SEO-Empfehlungen.",
            'stats' => "Analysiere die Besucherstatistiken. Welche Seiten sind am beliebtesten? Welche Referrer bringen den meisten Traffic? Gibt es auffaellige Muster bei Geraeten oder Tageszeiten?",
        );

        $tab_prompt = isset($tab_prompts[$tab]) ? $tab_prompts[$tab] : $tab_prompts['dashboard'];

        $user_prompt = $tab_prompt . "\n\n";
        $user_prompt .= "Hier sind die aktuellen Daten:\n\n";
        $user_prompt .= json_encode($tab_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Zusaetzlicher Frontend-Kontext (z.B. aktive Filter)
        if (!empty($context)) {
            $user_prompt .= "\n\nZusaetzlicher Kontext vom Benutzer:\n";
            $user_prompt .= json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $user_prompt .= "\n\nAntworte im folgenden JSON-Format:\n";
        $user_prompt .= '{"summary": "Kurze Zusammenfassung in 1-2 Saetzen", "score": 0-100, "findings": [{"type": "critical|warning|info|success", "title": "Kurztitel", "detail": "Erklaerung", "action": "Konkrete Handlungsempfehlung", "action_type": "redirect|cache|fix|optimize|monitor", "urls": ["betroffene URLs"]}], "quick_wins": ["Sofort umsetzbare Verbesserungen"]}';

        $response = $this->callOpenAI($this->system_prompt, $user_prompt);

        if (isset($response['error'])) {
            return $response;
        }

        $result = $this->parseAiResponse($response['content']);
        $result['tab'] = $tab;

        return $result;
    }

    /**
     * Tab-spezifische Daten sammeln
     */
    private function collectTabData($tab, $context = array()) {
        $data = array();

        switch ($tab) {
            case 'dashboard':
                // Alles sammeln (wie runAnalysis)
                $data = $this->collectAllData();
                break;

            case 'performance':
                // FPC Stats + Monitor
                $monitor_file = $this->base_dir . 'cache/fpc/monitor.json';
                if (is_file($monitor_file)) {
                    $data['fpc_monitor'] = @json_decode(file_get_contents($monitor_file), true);
                }
                // Preloader Log (letzte 50 Zeilen)
                $log_file = $this->base_dir . 'cache/fpc/preloader.log';
                if (is_file($log_file)) {
                    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $data['preloader_log_tail'] = array_slice($lines, -50);
                }
                break;

            case 'coverage':
                // SEO Daten fuer Coverage
                try {
                    require_once $this->base_dir . 'fpc_seo.php';
                    $seo = new FpcSeo($this->base_dir);
                    $data['seo_summary'] = $seo->getAiSummary();
                } catch (Exception $e) {
                    $data['error'] = $e->getMessage();
                }
                // Cache-Dateien zaehlen nach Kategorie
                $cache_dir = $this->base_dir . 'cache/fpc/';
                if (is_dir($cache_dir)) {
                    $data['cache_file_count'] = count(glob($cache_dir . '*.html'));
                }
                break;

            case 'fehler':
                // SEO Probleme + 404 Log
                try {
                    require_once $this->base_dir . 'fpc_seo.php';
                    $seo = new FpcSeo($this->base_dir);
                    $data['problems'] = $seo->getCrossApiProblems();
                    $data['seo_ist'] = $seo->getIstZustand();
                    // 404 Log (Top 50)
                    $log404 = $seo->get404Log('open', 50);
                    $data['top_404s'] = $log404;
                    // Scan-Ergebnisse mit Fehlern
                    $scan = $seo->getScanResults();
                    $errors = array();
                    if (is_array($scan)) {
                        foreach ($scan as $r) {
                            if (isset($r['http_status']) && $r['http_status'] >= 400) {
                                $errors[] = $r;
                            }
                        }
                    }
                    $data['scan_errors'] = array_slice($errors, 0, 50);
                } catch (Exception $e) {
                    $data['error'] = $e->getMessage();
                }
                break;

            case 'seo':
            case 'seo_404':
            case 'seo_scan':
            case 'seo_redirects':
                try {
                    require_once $this->base_dir . 'fpc_seo.php';
                    $seo = new FpcSeo($this->base_dir);
                    $data['seo_summary'] = $seo->getAiSummary();
                    $data['problems'] = $seo->getCrossApiProblems();

                    if ($tab === 'seo_404' || $tab === 'seo') {
                        $data['open_404s'] = $seo->get404Log('open', 100);
                    }
                    if ($tab === 'seo_scan' || $tab === 'seo') {
                        $scan = $seo->getScanResults();
                        $problematic = array();
                        if (is_array($scan)) {
                            foreach ($scan as $r) {
                                if (isset($r['http_status']) && $r['http_status'] >= 300) {
                                    $problematic[] = array(
                                        'url' => $r['url'],
                                        'status' => $r['http_status'],
                                        'issues' => isset($r['issues']) ? $r['issues'] : array(),
                                    );
                                }
                            }
                        }
                        $data['problematic_urls'] = array_slice($problematic, 0, 100);
                    }
                    if ($tab === 'seo_redirects' || $tab === 'seo') {
                        $data['redirects'] = $seo->getRedirects();
                    }
                } catch (Exception $e) {
                    $data['error'] = $e->getMessage();
                }
                break;

            case 'health':
                try {
                    require_once $this->base_dir . 'fpc_seo.php';
                    $seo = new FpcSeo($this->base_dir);
                    $data['health'] = $seo->getIstZustand();
                } catch (Exception $e) {
                    $data['error'] = $e->getMessage();
                }
                // .htaccess pruefen
                $htaccess = $this->base_dir . '.htaccess';
                if (is_file($htaccess)) {
                    $data['htaccess_size'] = filesize($htaccess);
                    $data['htaccess_lines'] = count(file($htaccess));
                }
                break;

            case 'gsc':
                try {
                    require_once $this->base_dir . 'fpc_gsc.php';
                    $creds = @json_decode(file_get_contents($this->config_dir . 'api_credentials.json'), true);
                    if (!empty($creds['gsc_service_account']) && is_file($this->base_dir . $creds['gsc_service_account'])) {
                        $gsc = new FPC_GoogleSearchConsole($this->base_dir . $creds['gsc_service_account'], isset($creds['gsc_site_url']) ? $creds['gsc_site_url'] : 'https://mr-hanf.de/');
                        $data['comparison'] = $gsc->getComparison(28);
                        $data['top_queries'] = $gsc->getTopQueries(28, 30);
                        $data['top_pages'] = $gsc->getTopPages(28, 30);
                    } else {
                        $data['not_configured'] = true;
                    }
                } catch (Exception $e) {
                    $data['error'] = $e->getMessage();
                }
                break;

            case 'ga4':
                try {
                    require_once $this->base_dir . 'fpc_ga4.php';
                    $creds = @json_decode(file_get_contents($this->config_dir . 'api_credentials.json'), true);
                    $ga4_sa = isset($creds['ga4_service_account']) ? $creds['ga4_service_account'] : '';
                    $ga4_prop = isset($creds['ga4_property_id']) ? $creds['ga4_property_id'] : '';
                    if (!empty($ga4_sa) && !empty($ga4_prop) && is_file($this->base_dir . $ga4_sa)) {
                        $ga4 = new FPC_GoogleAnalytics4($this->base_dir . $ga4_sa, $ga4_prop, $this->base_dir . 'cache/fpc/ga4/');
                        $data['comparison'] = $ga4->getPeriodComparison(30);
                        try { $data['ecommerce'] = $ga4->getEcommerceOverview(30); } catch (Exception $e2) {}
                        try { $data['top_pages'] = $ga4->getTopPages(30, 20); } catch (Exception $e2) {}
                        try { $data['sources'] = $ga4->getTrafficSources(30, 20); } catch (Exception $e2) {}
                    } else {
                        $data['not_configured'] = true;
                    }
                } catch (Exception $e) {
                    $data['error'] = $e->getMessage();
                }
                break;

            case 'sistrix':
                try {
                    require_once $this->base_dir . 'fpc_sistrix.php';
                    $creds = @json_decode(file_get_contents($this->config_dir . 'api_credentials.json'), true);
                    if (!empty($creds['sistrix_api_key'])) {
                        $domain = isset($creds['sistrix_domain']) ? $creds['sistrix_domain'] : 'mr-hanf.de';
                        $sx = new FPC_Sistrix($creds['sistrix_api_key'], $domain);
                        $data['visibility'] = $sx->getCurrentVisibility();
                        try { $data['history'] = $sx->getVisibilityHistory(); } catch (Exception $e2) {}
                    } else {
                        $data['not_configured'] = true;
                    }
                } catch (Exception $e) {
                    $data['error'] = $e->getMessage();
                }
                break;

            case 'stats':
                // FPC Monitor Daten
                $monitor_file = $this->base_dir . 'cache/fpc/monitor.json';
                if (is_file($monitor_file)) {
                    $data['fpc_monitor'] = @json_decode(file_get_contents($monitor_file), true);
                }
                break;

            default:
                $data = $this->collectAllData();
                break;
        }

        return $data;
    }
}
