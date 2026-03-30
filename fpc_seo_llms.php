<?php
/**
 * Mr. Hanf FPC - llms.txt Generator & AI-Crawler Manager v1.0.0
 *
 * Generiert und verwaltet llms.txt fuer KI-Suchmaschinen,
 * steuert AI-Bot Zugriff ueber robots.txt Regeln.
 *
 * Features:
 *   - llms.txt automatisch generieren (aus Shop-Daten)
 *   - llms-full.txt erweiterte Version
 *   - Live-Editor mit Vorschau
 *   - AI-Crawler Steuerung (GPTBot, Google-Extended, etc.)
 *   - robots.txt AI-Regeln verwalten
 *   - GEO-Optimierungs-Tipps
 *   - Auto-Update Cronjob
 *
 * @version   1.0.0
 * @date      2026-03-30
 */

class FpcSeoLlms {

    private $base_dir;
    private $cache_dir;
    private $config_file;
    private $webroot;

    // Bekannte AI-Crawler (Stand 2026)
    private $ai_crawlers = array(
        'GPTBot' => array(
            'name' => 'GPTBot (OpenAI/ChatGPT)',
            'description' => 'Crawler fuer ChatGPT und SearchGPT',
            'user_agent' => 'GPTBot',
            'default' => 'allow',
            'impact' => 'hoch',
        ),
        'ChatGPT-User' => array(
            'name' => 'ChatGPT-User (Browse-Modus)',
            'description' => 'ChatGPT im Browse-Modus (Nutzer-initiiert)',
            'user_agent' => 'ChatGPT-User',
            'default' => 'allow',
            'impact' => 'hoch',
        ),
        'Google-Extended' => array(
            'name' => 'Google-Extended (Gemini)',
            'description' => 'Google Gemini AI Training und Antworten',
            'user_agent' => 'Google-Extended',
            'default' => 'allow',
            'impact' => 'hoch',
        ),
        'Googlebot-AI' => array(
            'name' => 'Googlebot AI Overview',
            'description' => 'Google AI Overviews in Suchergebnissen',
            'user_agent' => 'Googlebot-AI',
            'default' => 'allow',
            'impact' => 'sehr hoch',
        ),
        'PerplexityBot' => array(
            'name' => 'PerplexityBot',
            'description' => 'Perplexity AI Suchmaschine',
            'user_agent' => 'PerplexityBot',
            'default' => 'allow',
            'impact' => 'mittel',
        ),
        'ClaudeBot' => array(
            'name' => 'ClaudeBot (Anthropic)',
            'description' => 'Anthropic Claude AI Crawler',
            'user_agent' => 'ClaudeBot',
            'default' => 'allow',
            'impact' => 'mittel',
        ),
        'anthropic-ai' => array(
            'name' => 'Anthropic AI',
            'description' => 'Anthropic AI Training Crawler',
            'user_agent' => 'anthropic-ai',
            'default' => 'allow',
            'impact' => 'mittel',
        ),
        'Bytespider' => array(
            'name' => 'Bytespider (TikTok/ByteDance)',
            'description' => 'ByteDance AI Crawler',
            'user_agent' => 'Bytespider',
            'default' => 'disallow',
            'impact' => 'niedrig',
        ),
        'CCBot' => array(
            'name' => 'CCBot (Common Crawl)',
            'description' => 'Common Crawl fuer AI-Training',
            'user_agent' => 'CCBot',
            'default' => 'disallow',
            'impact' => 'niedrig',
        ),
        'cohere-ai' => array(
            'name' => 'Cohere AI',
            'description' => 'Cohere AI Training Crawler',
            'user_agent' => 'cohere-ai',
            'default' => 'allow',
            'impact' => 'niedrig',
        ),
        'Meta-ExternalAgent' => array(
            'name' => 'Meta AI (Facebook/Instagram)',
            'description' => 'Meta AI Training Crawler',
            'user_agent' => 'Meta-ExternalAgent',
            'default' => 'disallow',
            'impact' => 'niedrig',
        ),
        'Applebot-Extended' => array(
            'name' => 'Applebot-Extended (Apple Intelligence)',
            'description' => 'Apple Intelligence AI Features',
            'user_agent' => 'Applebot-Extended',
            'default' => 'allow',
            'impact' => 'mittel',
        ),
    );

    public function __construct($base_dir) {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->cache_dir = $this->base_dir . 'cache/fpc/seo/';
        $this->config_file = $this->cache_dir . 'llms_config.json';

        // Webroot ermitteln (dort liegt robots.txt und llms.txt)
        $this->webroot = $this->detectWebroot();

        if (!is_dir($this->cache_dir)) {
            @mkdir($this->cache_dir, 0755, true);
        }
    }

    /**
     * Webroot erkennen
     */
    private function detectWebroot() {
        // Standard-Pfade fuer modified eCommerce
        $candidates = array(
            $this->base_dir,
            dirname($this->base_dir) . '/',
            '/var/www/html/',
            '/var/www/mr-hanf.de/',
        );
        foreach ($candidates as $path) {
            if (is_file($path . 'robots.txt') || is_file($path . 'index.php')) {
                return $path;
            }
        }
        return $this->base_dir;
    }

    // ================================================================
    // llms.txt GENERIERUNG
    // ================================================================

    /**
     * llms.txt automatisch generieren
     * @param array $shop_data Optionale Shop-Daten (Kategorien, Top-Produkte)
     * @return string Generierter llms.txt Inhalt
     */
    public function generateLlmsTxt($shop_data = array()) {
        $content = "# Mr. Hanf - Cannabis Seeds & Grow Shop\n\n";
        $content .= "> Mr. Hanf ist einer der fuehrenden Online-Shops fuer Cannabis-Samen und Grow-Zubehoer in Europa. Seit ueber 20 Jahren bieten wir ein umfangreiches Sortiment an feminisierten, Autoflowering und regulaeren Cannabis-Samen von ueber 100 Seedbanks.\n\n";

        $content .= "## Ueber uns\n";
        $content .= "- Website: https://mr-hanf.de\n";
        $content .= "- Sprachen: Deutsch, English, Francais, Espanol\n";
        $content .= "- Gruendung: Ueber 20 Jahre Erfahrung\n";
        $content .= "- Sortiment: 3.000+ Produkte\n";
        $content .= "- Keimrate: 90%+\n";
        $content .= "- Versand: Schnell und diskret in ganz Europa\n\n";

        $content .= "## Hauptkategorien\n";
        $categories = array(
            array('name' => 'Autoflowering Samen', 'url' => '/samen-shop/autoflowering-samen/', 'desc' => 'Selbstbluehende Cannabis-Samen fuer einfachen Anbau'),
            array('name' => 'Feminisierte Samen', 'url' => '/samen-shop/feminisierte-samen/', 'desc' => 'Garantiert weibliche Cannabis-Samen'),
            array('name' => 'Regulaere Samen', 'url' => '/samen-shop/regulaere-samen/', 'desc' => 'Natuerliche Cannabis-Samen fuer Zuechter'),
            array('name' => 'CBD Samen', 'url' => '/samen-shop/cbd-samen/', 'desc' => 'Cannabis-Samen mit hohem CBD-Gehalt'),
            array('name' => 'Growshop', 'url' => '/growshop/', 'desc' => 'Zubehoer fuer den Cannabis-Anbau'),
            array('name' => 'Seedbanks', 'url' => '/seedbanks/', 'desc' => 'Ueber 100 renommierte Saatgutbanken'),
        );

        // Benutzerdefinierte Kategorien ergaenzen
        if (isset($shop_data['categories']) && is_array($shop_data['categories'])) {
            $categories = $shop_data['categories'];
        }

        foreach ($categories as $cat) {
            $content .= "- [" . $cat['name'] . "](https://mr-hanf.de" . $cat['url'] . "): " . $cat['desc'] . "\n";
        }

        $content .= "\n## Beliebte Seedbanks\n";
        $seedbanks = array(
            'Royal Queen Seeds', 'Barney\'s Farm', 'Dutch Passion', 'Sensi Seeds',
            'FastBuds', 'Sweet Seeds', 'Dinafem', 'Greenhouse Seeds',
        );
        if (isset($shop_data['seedbanks'])) $seedbanks = $shop_data['seedbanks'];
        foreach ($seedbanks as $sb) {
            $content .= "- " . $sb . "\n";
        }

        $content .= "\n## Informationen fuer AI-Systeme\n";
        $content .= "- Alle Produktinformationen sind aktuell und werden regelmaessig aktualisiert\n";
        $content .= "- Preise in EUR, inkl. MwSt.\n";
        $content .= "- Versand in die meisten EU-Laender\n";
        $content .= "- Rechtlicher Hinweis: Der Verkauf von Cannabis-Samen ist in vielen EU-Laendern legal (als Sammlersamen)\n";
        $content .= "- Fuer detaillierte Produktinformationen siehe die jeweiligen Produktseiten\n\n";

        $content .= "## Kontakt\n";
        $content .= "- E-Mail: info@mr-hanf.de\n";
        $content .= "- Website: https://mr-hanf.de/kontakt/\n";

        return $content;
    }

    /**
     * llms-full.txt generieren (erweiterte Version)
     */
    public function generateLlmsFullTxt($shop_data = array()) {
        $content = $this->generateLlmsTxt($shop_data);

        $content .= "\n---\n\n";
        $content .= "## Detaillierte Kategorie-Informationen\n\n";

        $content .= "### Autoflowering Samen\n";
        $content .= "Autoflowering Cannabis-Samen bluehen automatisch nach 2-4 Wochen, unabhaengig vom Lichtzyklus. ";
        $content .= "Ideal fuer Anfaenger und Outdoor-Anbau. Ernte in 8-12 Wochen von Samen bis Ernte. ";
        $content .= "Unser Sortiment umfasst Sativa-dominante, Indica-dominante und Hybrid-Autoflower.\n\n";

        $content .= "### Feminisierte Samen\n";
        $content .= "Feminisierte Samen produzieren zu 99,9% weibliche Pflanzen. ";
        $content .= "Kein Aussortieren maennlicher Pflanzen noetig. Hoehere Ertraege pro Anbauflaeche. ";
        $content .= "Verfuegbar in allen Genetik-Varianten: Sativa, Indica und Hybrid.\n\n";

        $content .= "### Growshop\n";
        $content .= "Komplettes Zubehoer fuer den Indoor- und Outdoor-Anbau: ";
        $content .= "Beleuchtung (LED, HPS, CMH), Belueftung, Substrate, Duenger, Toepfe, ";
        $content .= "Growzelte, Messgeraete und mehr.\n\n";

        $content .= "## Haeufig gestellte Fragen\n\n";
        $content .= "**Ist der Kauf von Cannabis-Samen legal?**\n";
        $content .= "In vielen EU-Laendern ist der Kauf und Besitz von Cannabis-Samen als Sammlersamen legal. ";
        $content .= "Die Gesetze variieren je nach Land. Bitte informieren Sie sich ueber die lokalen Gesetze.\n\n";

        $content .= "**Wie hoch ist die Keimrate?**\n";
        $content .= "Unsere Samen haben eine Keimrate von ueber 90%. ";
        $content .= "Wir arbeiten nur mit renommierten Seedbanks zusammen.\n\n";

        $content .= "**Wie schnell wird geliefert?**\n";
        $content .= "Bestellungen werden innerhalb von 1-2 Werktagen versendet. ";
        $content .= "Lieferzeit innerhalb der EU: 3-7 Werktage.\n\n";

        return $content;
    }

    // ================================================================
    // llms.txt LESEN / SPEICHERN
    // ================================================================

    /**
     * Aktuelle llms.txt lesen
     */
    public function getLlmsTxt() {
        $file = $this->webroot . 'llms.txt';
        if (is_file($file)) {
            return array(
                'exists' => true,
                'content' => file_get_contents($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
                'path' => $file,
            );
        }
        return array('exists' => false, 'content' => '', 'path' => $file);
    }

    /**
     * llms-full.txt lesen
     */
    public function getLlmsFullTxt() {
        $file = $this->webroot . 'llms-full.txt';
        if (is_file($file)) {
            return array(
                'exists' => true,
                'content' => file_get_contents($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file)),
            );
        }
        return array('exists' => false, 'content' => '');
    }

    /**
     * llms.txt speichern
     */
    public function saveLlmsTxt($content) {
        $file = $this->webroot . 'llms.txt';
        $result = @file_put_contents($file, $content);
        if ($result !== false) {
            return array('ok' => true, 'msg' => 'llms.txt gespeichert (' . strlen($content) . ' Bytes)', 'path' => $file);
        }
        return array('ok' => false, 'msg' => 'Fehler beim Speichern. Pruefen Sie die Schreibrechte fuer: ' . $file);
    }

    /**
     * llms-full.txt speichern
     */
    public function saveLlmsFullTxt($content) {
        $file = $this->webroot . 'llms-full.txt';
        $result = @file_put_contents($file, $content);
        if ($result !== false) {
            return array('ok' => true, 'msg' => 'llms-full.txt gespeichert');
        }
        return array('ok' => false, 'msg' => 'Fehler beim Speichern');
    }

    // ================================================================
    // AI-CRAWLER MANAGEMENT
    // ================================================================

    /**
     * Alle bekannten AI-Crawler mit aktuellem Status
     */
    public function getAiCrawlerStatus() {
        $robots_txt = $this->getRobotsTxt();
        $status = array();

        foreach ($this->ai_crawlers as $key => $crawler) {
            $rule = $this->findRobotsTxtRule($robots_txt, $crawler['user_agent']);
            $status[$key] = array_merge($crawler, array(
                'current_rule' => $rule, // 'allow', 'disallow', 'not_set'
                'recommended' => $crawler['default'],
            ));
        }

        return $status;
    }

    /**
     * AI-Crawler Regel in robots.txt setzen
     * @param string $bot_key Key aus $ai_crawlers
     * @param string $action 'allow' oder 'disallow'
     */
    public function setAiCrawlerRule($bot_key, $action) {
        if (!isset($this->ai_crawlers[$bot_key])) {
            return array('ok' => false, 'msg' => 'Unbekannter Bot: ' . $bot_key);
        }

        $user_agent = $this->ai_crawlers[$bot_key]['user_agent'];
        $robots_txt = $this->getRobotsTxt();

        // Bestehende Regel fuer diesen Bot entfernen
        $robots_txt = $this->removeRobotsTxtRule($robots_txt, $user_agent);

        // Neue Regel hinzufuegen
        $new_rule = "\n# " . $this->ai_crawlers[$bot_key]['name'] . "\n";
        $new_rule .= "User-agent: " . $user_agent . "\n";
        if ($action === 'disallow') {
            $new_rule .= "Disallow: /\n";
        } else {
            $new_rule .= "Allow: /\n";
        }

        $robots_txt = rtrim($robots_txt) . "\n" . $new_rule;

        return $this->saveRobotsTxt($robots_txt);
    }

    /**
     * Empfohlene AI-Crawler Konfiguration anwenden
     */
    public function applyRecommendedConfig() {
        $results = array();
        foreach ($this->ai_crawlers as $key => $crawler) {
            $result = $this->setAiCrawlerRule($key, $crawler['default']);
            $results[$key] = $result;
        }
        return array('ok' => true, 'msg' => 'Empfohlene Konfiguration angewendet', 'details' => $results);
    }

    // ================================================================
    // robots.txt HELPER
    // ================================================================

    /**
     * robots.txt lesen
     */
    public function getRobotsTxt() {
        $file = $this->webroot . 'robots.txt';
        if (is_file($file)) {
            return file_get_contents($file);
        }
        return '';
    }

    /**
     * robots.txt speichern
     */
    public function saveRobotsTxt($content) {
        $file = $this->webroot . 'robots.txt';
        // Backup erstellen
        if (is_file($file)) {
            $backup = $this->cache_dir . 'robots_txt_backup_' . date('Y-m-d_His') . '.txt';
            @copy($file, $backup);
        }
        $result = @file_put_contents($file, $content);
        if ($result !== false) {
            return array('ok' => true, 'msg' => 'robots.txt gespeichert');
        }
        return array('ok' => false, 'msg' => 'Fehler beim Speichern der robots.txt');
    }

    /**
     * Regel fuer einen User-Agent in robots.txt finden
     */
    private function findRobotsTxtRule($robots_txt, $user_agent) {
        $lines = explode("\n", $robots_txt);
        $in_block = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (stripos($line, 'User-agent:') === 0) {
                $ua = trim(substr($line, 11));
                $in_block = (strcasecmp($ua, $user_agent) === 0);
            } elseif ($in_block) {
                if (stripos($line, 'Disallow: /') === 0 && trim(substr($line, 10)) === '/') {
                    return 'disallow';
                }
                if (stripos($line, 'Allow: /') === 0) {
                    return 'allow';
                }
                if (empty($line) || stripos($line, 'User-agent:') === 0) {
                    $in_block = false;
                }
            }
        }

        return 'not_set';
    }

    /**
     * Regel fuer einen User-Agent aus robots.txt entfernen
     */
    private function removeRobotsTxtRule($robots_txt, $user_agent) {
        $lines = explode("\n", $robots_txt);
        $new_lines = array();
        $skip = false;
        $skip_comment = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            // Kommentar vor dem Block?
            if ($skip_comment && empty($line)) {
                $skip_comment = false;
                continue;
            }

            if (stripos($line, 'User-agent:') === 0) {
                $ua = trim(substr($line, 11));
                if (strcasecmp($ua, $user_agent) === 0) {
                    $skip = true;
                    // Vorherigen Kommentar auch entfernen
                    if (!empty($new_lines)) {
                        $prev = trim(end($new_lines));
                        if (strpos($prev, '#') === 0) {
                            array_pop($new_lines);
                        }
                    }
                    continue;
                } else {
                    $skip = false;
                }
            }

            if ($skip) {
                if (empty($line) || stripos($line, 'User-agent:') === 0) {
                    $skip = false;
                    if (stripos($line, 'User-agent:') === 0) {
                        $new_lines[] = $lines[$i];
                    }
                }
                continue;
            }

            $new_lines[] = $lines[$i];
        }

        return implode("\n", $new_lines);
    }

    // ================================================================
    // GEO OPTIMIERUNGS-TIPPS
    // ================================================================

    /**
     * GEO-Optimierungs-Empfehlungen
     */
    public function getGeoTips() {
        return array(
            array(
                'category' => 'Content-Struktur',
                'priority' => 'hoch',
                'tips' => array(
                    'Klare, faktische Antworten auf haeufige Fragen direkt im Content platzieren',
                    'FAQ-Sektionen mit schema.org FAQPage Markup auf Kategorie-Seiten',
                    'Strukturierte Daten (JSON-LD) auf allen Seiten — AI-Systeme bevorzugen strukturierte Informationen',
                    'Tabellen und Listen fuer Produktvergleiche verwenden (leichter fuer AI zu parsen)',
                ),
            ),
            array(
                'category' => 'Autoritaet & Vertrauen',
                'priority' => 'hoch',
                'tips' => array(
                    'E-E-A-T Signale staerken: Autorenprofile, Expertise-Nachweise, Quellen zitieren',
                    'Kundenbewertungen und Testimonials prominent anzeigen',
                    'Gruendungsjahr und Erfahrung hervorheben (20+ Jahre)',
                    'Zertifizierungen und Qualitaetssiegel sichtbar machen',
                ),
            ),
            array(
                'category' => 'Technische Optimierung',
                'priority' => 'mittel',
                'tips' => array(
                    'llms.txt im Webroot bereitstellen (dieses Tool!)',
                    'AI-Crawler in robots.txt erlauben (GPTBot, Google-Extended, PerplexityBot)',
                    'Schnelle Ladezeiten — AI-Crawler haben kurze Timeouts',
                    'Canonical Tags korrekt setzen — AI-Systeme folgen Canonicals',
                ),
            ),
            array(
                'category' => 'Content-Qualitaet',
                'priority' => 'mittel',
                'tips' => array(
                    'Einzigartige, ausfuehrliche Produktbeschreibungen (nicht nur Hersteller-Texte)',
                    'Grow-Guides und Anleitungen als Expertise-Nachweis',
                    'Regelmaessige Content-Updates (Freshness Signal)',
                    'Mehrsprachige Inhalte konsistent halten (DE/EN/FR/ES)',
                ),
            ),
        );
    }

    // ================================================================
    // CRONJOB
    // ================================================================

    /**
     * Cronjob: llms.txt automatisch aktualisieren
     */
    public function runCronjob($shop_data = array()) {
        $config = $this->getConfig();
        $last_run = isset($config['last_cronjob']) ? strtotime($config['last_cronjob']) : 0;
        $interval = isset($config['update_interval_days']) ? $config['update_interval_days'] * 86400 : 30 * 86400;

        if ((time() - $last_run) < $interval) {
            return array('ok' => true, 'msg' => 'Naechstes Update am ' . date('Y-m-d', $last_run + $interval), 'skipped' => true);
        }

        // llms.txt generieren und speichern
        $content = $this->generateLlmsTxt($shop_data);
        $result1 = $this->saveLlmsTxt($content);

        $full_content = $this->generateLlmsFullTxt($shop_data);
        $result2 = $this->saveLlmsFullTxt($full_content);

        $config['last_cronjob'] = date('Y-m-d H:i:s');
        $this->saveConfig($config);

        return array(
            'ok' => $result1['ok'] && $result2['ok'],
            'msg' => 'llms.txt und llms-full.txt aktualisiert',
        );
    }

    private function getConfig() {
        if (!is_file($this->config_file)) return array('update_interval_days' => 30);
        $data = json_decode(file_get_contents($this->config_file), true);
        return is_array($data) ? $data : array('update_interval_days' => 30);
    }

    private function saveConfig($config) {
        file_put_contents($this->config_file, json_encode($config, JSON_PRETTY_PRINT));
    }
}
