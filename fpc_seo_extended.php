<?php
/**
 * Mr. Hanf FPC SEO Extended v1.0.0
 *
 * Erweiterungsmodul fuer FpcSeo mit neuen 2026-Features.
 * Wird von fpc_seo.php eingebunden oder separat geladen.
 *
 * Features:
 *   - hreflang Validator (DE, EN, FR, ES)
 *   - robots.txt Editor mit Validierung
 *   - Sitemap Validator
 *   - Meta-Tag Audit & Editor (DB-basiert)
 *   - Content Audit (Thin Content, Freshness)
 *   - Internal Links Analyse
 *   - Keyword Monitor (GSC-basiert)
 *
 * @version   1.0.0
 * @date      2026-03-30
 */

class FpcSeoExtended {

    private $base_dir;
    private $seo_dir;
    private $site_url;
    private $languages = array('de', 'en', 'fr', 'es');
    private $lang_prefixes = array(
        'de' => '',
        'en' => '/en',
        'fr' => '/fr',
        'es' => '/es',
    );

    // DB-Verbindung (optional, fuer Content Audit und Meta-Tags)
    private $db = null;

    public function __construct($base_dir, $site_url = 'https://mr-hanf.de') {
        $this->base_dir = rtrim($base_dir, '/') . '/';
        $this->site_url = rtrim($site_url, '/');
        $this->seo_dir = $this->base_dir . 'cache/fpc/seo/';

        if (!is_dir($this->seo_dir)) {
            @mkdir($this->seo_dir, 0755, true);
        }
    }

    /**
     * DB-Verbindung setzen (fuer Content Audit / Meta-Tags)
     */
    public function setDbConnection($host, $name, $user, $pass) {
        try {
            $this->db = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                $user, $pass,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * DB-Verbindung aus configure.php laden
     */
    public function loadDbFromConfig() {
        $config_paths = array(
            $this->base_dir . 'includes/configure.php',
            $this->base_dir . '../includes/configure.php',
            dirname($this->base_dir) . '/includes/configure.php',
        );
        foreach ($config_paths as $path) {
            if (is_file($path)) {
                // Constants extrahieren ohne die Datei zu includen
                $content = file_get_contents($path);
                $vars = array();
                if (preg_match("/define\s*\(\s*'DB_SERVER'\s*,\s*'([^']+)'/", $content, $m)) $vars['host'] = $m[1];
                if (preg_match("/define\s*\(\s*'DB_DATABASE'\s*,\s*'([^']+)'/", $content, $m)) $vars['name'] = $m[1];
                if (preg_match("/define\s*\(\s*'DB_SERVER_USERNAME'\s*,\s*'([^']+)'/", $content, $m)) $vars['user'] = $m[1];
                if (preg_match("/define\s*\(\s*'DB_SERVER_PASSWORD'\s*,\s*'([^']+)'/", $content, $m)) $vars['pass'] = $m[1];

                if (count($vars) === 4) {
                    return $this->setDbConnection($vars['host'], $vars['name'], $vars['user'], $vars['pass']);
                }
            }
        }
        return false;
    }

    // ================================================================
    // HREFLANG VALIDATOR
    // ================================================================

    /**
     * hreflang Tags einer URL pruefen
     * @param string $url URL zu pruefen
     * @return array Ergebnis mit gefundenen Tags und Fehlern
     */
    public function scanHreflang($url) {
        if (strpos($url, 'http') !== 0) {
            $url = $this->site_url . (strpos($url, '/') === 0 ? '' : '/') . $url;
        }

        $result = array(
            'url' => $url,
            'timestamp' => date('Y-m-d H:i:s'),
            'http_status' => 0,
            'hreflang_tags' => array(),
            'has_xdefault' => false,
            'issues' => array(),
            'score' => 0,
        );

        // HTML laden
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWREDIRECTS => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'MrHanf-SEO-Scanner/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $html = curl_exec($ch);
        $result['http_status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$html || $result['http_status'] >= 400) {
            $result['issues'][] = 'HTTP Fehler: Status ' . $result['http_status'];
            return $result;
        }

        // hreflang Tags extrahieren
        // Pattern: <link rel="alternate" hreflang="de" href="...">
        if (preg_match_all('/<link[^>]*rel=["\']alternate["\'][^>]*hreflang=["\']([^"\']+)["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $result['hreflang_tags'][$match[1]] = $match[2];
            }
        }
        // Auch umgekehrte Attribut-Reihenfolge
        if (preg_match_all('/<link[^>]*hreflang=["\']([^"\']+)["\'][^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']alternate["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!isset($result['hreflang_tags'][$match[1]])) {
                    $result['hreflang_tags'][$match[1]] = $match[2];
                }
            }
        }

        // x-default pruefen
        $result['has_xdefault'] = isset($result['hreflang_tags']['x-default']);

        // Validierung
        if (empty($result['hreflang_tags'])) {
            $result['issues'][] = 'Keine hreflang-Tags gefunden';
        } else {
            // Alle erwarteten Sprachen vorhanden?
            foreach ($this->languages as $lang) {
                if (!isset($result['hreflang_tags'][$lang])) {
                    $result['issues'][] = 'hreflang fuer "' . $lang . '" fehlt';
                }
            }

            // x-default vorhanden?
            if (!$result['has_xdefault']) {
                $result['issues'][] = 'x-default hreflang fehlt (sollte auf DE-Version zeigen)';
            }

            // Self-referencing pruefen
            $current_lang = $this->detectLanguage($url);
            if ($current_lang && isset($result['hreflang_tags'][$current_lang])) {
                $self_url = $result['hreflang_tags'][$current_lang];
                if (rtrim($self_url, '/') !== rtrim($url, '/')) {
                    $result['issues'][] = 'Self-referencing hreflang URL stimmt nicht ueberein: ' . $self_url . ' vs ' . $url;
                }
            }

            // URLs erreichbar pruefen (Stichprobe)
            foreach ($result['hreflang_tags'] as $lang => $href) {
                if ($lang === 'x-default') continue;
                if (strpos($href, 'http') !== 0) {
                    $result['issues'][] = 'hreflang "' . $lang . '": Relative URL statt absolute URL: ' . $href;
                }
            }
        }

        // Score berechnen
        $max_score = 100;
        $deduction = count($result['issues']) * 15;
        $result['score'] = max(0, $max_score - $deduction);

        return $result;
    }

    /**
     * Vollstaendiger hreflang Audit (alle Sitemap-URLs)
     */
    public function runHreflangAudit($max_urls = 200) {
        $start_time = time();
        $urls = $this->getSitemapUrls();

        // Nur DE-URLs pruefen (die anderen Sprachen werden als Referenz geprueft)
        $de_urls = array_filter($urls, function($url) {
            return !preg_match('#/(en|fr|es)/#', $url);
        });

        if (count($de_urls) > $max_urls) {
            $de_urls = array_slice($de_urls, 0, $max_urls);
        }

        $results = array();
        $stats = array(
            'total_scanned' => 0,
            'with_hreflang' => 0,
            'without_hreflang' => 0,
            'with_xdefault' => 0,
            'issues_count' => 0,
            'avg_score' => 0,
            'lang_coverage' => array(),
            'common_issues' => array(),
        );

        $scores = array();
        $issue_counts = array();

        foreach ($de_urls as $url) {
            $scan = $this->scanHreflang($url);
            $results[] = $scan;
            $stats['total_scanned']++;

            if (!empty($scan['hreflang_tags'])) $stats['with_hreflang']++;
            else $stats['without_hreflang']++;

            if ($scan['has_xdefault']) $stats['with_xdefault']++;

            $stats['issues_count'] += count($scan['issues']);
            $scores[] = $scan['score'];

            // Sprach-Abdeckung zaehlen
            foreach ($this->languages as $lang) {
                if (!isset($stats['lang_coverage'][$lang])) $stats['lang_coverage'][$lang] = 0;
                if (isset($scan['hreflang_tags'][$lang])) $stats['lang_coverage'][$lang]++;
            }

            // Haeufige Issues zaehlen
            foreach ($scan['issues'] as $issue) {
                if (!isset($issue_counts[$issue])) $issue_counts[$issue] = 0;
                $issue_counts[$issue]++;
            }

            // Timeout-Schutz
            if ((time() - $start_time) > 300) break;
            usleep(200000);
        }

        // Durchschnitte
        if (!empty($scores)) {
            $stats['avg_score'] = round(array_sum($scores) / count($scores), 1);
        }

        // Sprach-Abdeckung in Prozent
        foreach ($stats['lang_coverage'] as $lang => &$count) {
            $count = array(
                'count' => $count,
                'percentage' => $stats['total_scanned'] > 0 ? round(($count / $stats['total_scanned']) * 100, 1) : 0,
            );
        }

        // Top Issues
        arsort($issue_counts);
        $stats['common_issues'] = array_slice($issue_counts, 0, 10, true);

        $audit = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'duration_seconds' => time() - $start_time,
            'stats' => $stats,
            'results' => $results,
        );

        // Speichern
        $this->writeJson($this->seo_dir . 'hreflang_audit.json', $audit);
        $this->addHreflangHistory($stats);

        return array(
            'ok' => true,
            'msg' => $stats['total_scanned'] . ' URLs gescannt',
            'stats' => $stats,
        );
    }

    /**
     * hreflang Audit-Ergebnisse abrufen
     */
    public function getHreflangAudit() {
        return $this->readJson($this->seo_dir . 'hreflang_audit.json', array('has_data' => false));
    }

    /**
     * hreflang History abrufen
     */
    public function getHreflangHistory() {
        return $this->readJson($this->seo_dir . 'hreflang_history.json', array());
    }

    private function addHreflangHistory($stats) {
        $history = $this->getHreflangHistory();
        $history[] = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'total_scanned' => $stats['total_scanned'],
            'with_hreflang' => $stats['with_hreflang'],
            'avg_score' => $stats['avg_score'],
            'lang_coverage' => $stats['lang_coverage'],
        );
        if (count($history) > 52) $history = array_slice($history, -52);
        $this->writeJson($this->seo_dir . 'hreflang_history.json', $history);
    }

    // ================================================================
    // ROBOTS.TXT EDITOR
    // ================================================================

    /**
     * robots.txt lesen
     */
    public function getRobotsTxt() {
        $webroot = $this->detectWebroot();
        $file = $webroot . 'robots.txt';
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
     * robots.txt speichern (mit Backup)
     */
    public function saveRobotsTxt($content) {
        $webroot = $this->detectWebroot();
        $file = $webroot . 'robots.txt';

        // Backup
        if (is_file($file)) {
            @copy($file, $this->seo_dir . 'robots_backup_' . date('Y-m-d_His') . '.txt');
        }

        $result = @file_put_contents($file, $content);
        if ($result !== false) {
            return array('ok' => true, 'msg' => 'robots.txt gespeichert (' . strlen($content) . ' Bytes)');
        }
        return array('ok' => false, 'msg' => 'Fehler beim Speichern. Schreibrechte pruefen.');
    }

    /**
     * robots.txt validieren
     */
    public function validateRobotsTxt($content) {
        $issues = array();
        $warnings = array();
        $info = array();

        $lines = explode("\n", $content);
        $has_sitemap = false;
        $has_wildcard = false;
        $user_agents = array();
        $line_num = 0;

        foreach ($lines as $line) {
            $line_num++;
            $trimmed = trim($line);
            if (empty($trimmed) || $trimmed[0] === '#') continue;

            if (stripos($trimmed, 'User-agent:') === 0) {
                $ua = trim(substr($trimmed, 11));
                $user_agents[] = $ua;
                if ($ua === '*') $has_wildcard = true;
            }

            if (stripos($trimmed, 'Sitemap:') === 0) {
                $has_sitemap = true;
                $sitemap_url = trim(substr($trimmed, 8));
                if (strpos($sitemap_url, 'http') !== 0) {
                    $issues[] = 'Zeile ' . $line_num . ': Sitemap URL muss absolut sein (mit http/https)';
                }
            }

            if (stripos($trimmed, 'Disallow: /') === 0 && trim(substr($trimmed, 10)) === '/') {
                $warnings[] = 'Zeile ' . $line_num . ': "Disallow: /" blockiert den gesamten Zugriff fuer den aktuellen User-Agent';
            }
        }

        if (!$has_sitemap) {
            $warnings[] = 'Keine Sitemap-Referenz gefunden. Empfohlen: "Sitemap: https://mr-hanf.de/sitemap.xml"';
        }

        if (!$has_wildcard) {
            $info[] = 'Kein Wildcard User-Agent (*) definiert. Nicht explizit genannte Bots haben uneingeschraenkten Zugriff.';
        }

        return array(
            'valid' => empty($issues),
            'issues' => $issues,
            'warnings' => $warnings,
            'info' => $info,
            'user_agents' => array_unique($user_agents),
            'has_sitemap' => $has_sitemap,
        );
    }

    // ================================================================
    // SITEMAP VALIDATOR
    // ================================================================

    /**
     * Sitemap validieren
     */
    public function validateSitemap($sitemap_url = null) {
        if (!$sitemap_url) $sitemap_url = $this->site_url . '/sitemap.xml';

        $result = array(
            'url' => $sitemap_url,
            'timestamp' => date('Y-m-d H:i:s'),
            'valid' => false,
            'type' => 'unknown',
            'total_urls' => 0,
            'sub_sitemaps' => array(),
            'issues' => array(),
            'warnings' => array(),
            'sample_checks' => array(),
        );

        // Sitemap laden
        $ch = curl_init($sitemap_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $xml_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$xml_content || $http_code >= 400) {
            $result['issues'][] = 'Sitemap nicht erreichbar (HTTP ' . $http_code . ')';
            return $result;
        }

        // XML validieren
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($xml_content);
        if (!$xml) {
            $result['issues'][] = 'Ungültiges XML';
            foreach (libxml_get_errors() as $error) {
                $result['issues'][] = 'XML Fehler Zeile ' . $error->line . ': ' . trim($error->message);
            }
            libxml_clear_errors();
            return $result;
        }

        $result['valid'] = true;

        // Sitemap Index?
        if ($xml->getName() === 'sitemapindex' || isset($xml->sitemap)) {
            $result['type'] = 'index';
            foreach ($xml->sitemap as $sm) {
                $loc = (string)$sm->loc;
                $lastmod = isset($sm->lastmod) ? (string)$sm->lastmod : null;
                $result['sub_sitemaps'][] = array('url' => $loc, 'lastmod' => $lastmod);
                $result['total_urls']++;
            }
        } else {
            $result['type'] = 'urlset';
            $urls = array();
            foreach ($xml->url as $url_node) {
                $urls[] = (string)$url_node->loc;
            }
            $result['total_urls'] = count($urls);

            // Stichproben-Check: 20 zufaellige URLs auf Erreichbarkeit pruefen
            if (count($urls) > 20) {
                $sample = array_rand(array_flip($urls), 20);
            } else {
                $sample = $urls;
            }

            foreach ($sample as $check_url) {
                $ch = curl_init($check_url);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOBODY => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_FOLLOWREDIRECTS => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                ));
                curl_exec($ch);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $result['sample_checks'][] = array(
                    'url' => $check_url,
                    'status' => $status,
                    'ok' => ($status >= 200 && $status < 400),
                );

                if ($status >= 400) {
                    $result['warnings'][] = 'URL in Sitemap nicht erreichbar (HTTP ' . $status . '): ' . $check_url;
                }

                usleep(100000);
            }

            // Duplikate pruefen
            $unique = array_unique($urls);
            if (count($unique) < count($urls)) {
                $dupes = count($urls) - count($unique);
                $result['warnings'][] = $dupes . ' doppelte URLs in der Sitemap gefunden';
            }
        }

        // Ergebnis speichern
        $this->writeJson($this->seo_dir . 'sitemap_validation.json', $result);

        return $result;
    }

    // ================================================================
    // META-TAG AUDIT & EDITOR (DB-basiert)
    // ================================================================

    /**
     * Meta-Tag Audit: Alle Produkte/Kategorien auf Meta-Tags pruefen
     */
    public function getMetaTagAudit() {
        if (!$this->db) {
            if (!$this->loadDbFromConfig()) {
                return array('ok' => false, 'msg' => 'Keine DB-Verbindung. Bitte DB-Credentials in den Einstellungen konfigurieren.');
            }
        }

        $audit = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'products' => array('total' => 0, 'with_title' => 0, 'with_desc' => 0, 'title_too_long' => 0, 'title_too_short' => 0, 'desc_too_long' => 0, 'desc_too_short' => 0, 'missing_title' => 0, 'missing_desc' => 0),
            'categories' => array('total' => 0, 'with_title' => 0, 'with_desc' => 0, 'title_too_long' => 0, 'title_too_short' => 0, 'desc_too_long' => 0, 'desc_too_short' => 0, 'missing_title' => 0, 'missing_desc' => 0),
            'issues' => array(),
            'samples' => array(),
        );

        // Produkte pruefen
        try {
            $stmt = $this->db->query("
                SELECT pd.products_id, pd.products_name, pd.products_meta_title, pd.products_meta_description, pd.language_id
                FROM products_description pd
                WHERE pd.language_id = 2
                ORDER BY pd.products_id DESC
                LIMIT 5000
            ");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($products as $p) {
                $audit['products']['total']++;
                $title = trim($p['products_meta_title'] ?? '');
                $desc = trim($p['products_meta_description'] ?? '');

                if (!empty($title)) {
                    $audit['products']['with_title']++;
                    $len = mb_strlen($title);
                    if ($len > 60) $audit['products']['title_too_long']++;
                    if ($len < 20) $audit['products']['title_too_short']++;
                } else {
                    $audit['products']['missing_title']++;
                }

                if (!empty($desc)) {
                    $audit['products']['with_desc']++;
                    $len = mb_strlen($desc);
                    if ($len > 160) $audit['products']['desc_too_long']++;
                    if ($len < 50) $audit['products']['desc_too_short']++;
                } else {
                    $audit['products']['missing_desc']++;
                }
            }
        } catch (PDOException $e) {
            $audit['issues'][] = 'DB Fehler (Produkte): ' . $e->getMessage();
        }

        // Kategorien pruefen
        try {
            $stmt = $this->db->query("
                SELECT cd.categories_id, cd.categories_name, cd.categories_meta_title, cd.categories_meta_description, cd.language_id
                FROM categories_description cd
                WHERE cd.language_id = 2
                ORDER BY cd.categories_id
            ");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($categories as $c) {
                $audit['categories']['total']++;
                $title = trim($c['categories_meta_title'] ?? '');
                $desc = trim($c['categories_meta_description'] ?? '');

                if (!empty($title)) {
                    $audit['categories']['with_title']++;
                    $len = mb_strlen($title);
                    if ($len > 60) $audit['categories']['title_too_long']++;
                    if ($len < 20) $audit['categories']['title_too_short']++;
                } else {
                    $audit['categories']['missing_title']++;
                }

                if (!empty($desc)) {
                    $audit['categories']['with_desc']++;
                    $len = mb_strlen($desc);
                    if ($len > 160) $audit['categories']['desc_too_long']++;
                    if ($len < 50) $audit['categories']['desc_too_short']++;
                } else {
                    $audit['categories']['missing_desc']++;
                }
            }
        } catch (PDOException $e) {
            $audit['issues'][] = 'DB Fehler (Kategorien): ' . $e->getMessage();
        }

        // Ergebnis speichern
        $this->writeJson($this->seo_dir . 'meta_audit.json', $audit);

        return $audit;
    }

    /**
     * Meta-Tags fuer ein Produkt lesen
     */
    public function getProductMetaTags($product_id, $language_id = 2) {
        if (!$this->db && !$this->loadDbFromConfig()) {
            return array('ok' => false, 'msg' => 'Keine DB-Verbindung');
        }

        $stmt = $this->db->prepare("
            SELECT products_id, products_name, products_meta_title, products_meta_description, products_meta_keywords
            FROM products_description
            WHERE products_id = ? AND language_id = ?
        ");
        $stmt->execute(array($product_id, $language_id));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return array('ok' => false, 'msg' => 'Produkt nicht gefunden');

        return array(
            'ok' => true,
            'data' => $row,
            'title_length' => mb_strlen($row['products_meta_title'] ?? ''),
            'desc_length' => mb_strlen($row['products_meta_description'] ?? ''),
        );
    }

    /**
     * Meta-Tags fuer ein Produkt speichern
     */
    public function saveProductMetaTags($product_id, $title, $description, $keywords = '', $language_id = 2) {
        if (!$this->db && !$this->loadDbFromConfig()) {
            return array('ok' => false, 'msg' => 'Keine DB-Verbindung');
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE products_description
                SET products_meta_title = ?, products_meta_description = ?, products_meta_keywords = ?
                WHERE products_id = ? AND language_id = ?
            ");
            $stmt->execute(array($title, $description, $keywords, $product_id, $language_id));

            return array('ok' => true, 'msg' => 'Meta-Tags gespeichert fuer Produkt #' . $product_id);
        } catch (PDOException $e) {
            return array('ok' => false, 'msg' => 'DB Fehler: ' . $e->getMessage());
        }
    }

    /**
     * Meta-Tags fuer eine Kategorie speichern
     */
    public function saveCategoryMetaTags($category_id, $title, $description, $language_id = 2) {
        if (!$this->db && !$this->loadDbFromConfig()) {
            return array('ok' => false, 'msg' => 'Keine DB-Verbindung');
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE categories_description
                SET categories_meta_title = ?, categories_meta_description = ?
                WHERE categories_id = ? AND language_id = ?
            ");
            $stmt->execute(array($title, $description, $category_id, $language_id));

            return array('ok' => true, 'msg' => 'Meta-Tags gespeichert fuer Kategorie #' . $category_id);
        } catch (PDOException $e) {
            return array('ok' => false, 'msg' => 'DB Fehler: ' . $e->getMessage());
        }
    }

    // ================================================================
    // CONTENT AUDIT (DB-basiert)
    // ================================================================

    /**
     * Content Audit: Thin Content, Freshness, Wortanzahl
     */
    public function getContentAudit() {
        if (!$this->db) {
            if (!$this->loadDbFromConfig()) {
                return array('ok' => false, 'msg' => 'Keine DB-Verbindung');
            }
        }

        $audit = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'products' => array(
                'total' => 0,
                'thin_content' => 0,       // < 100 Woerter
                'short_content' => 0,      // 100-300 Woerter
                'good_content' => 0,       // 300-1000 Woerter
                'rich_content' => 0,       // > 1000 Woerter
                'no_description' => 0,
                'avg_word_count' => 0,
                'freshness' => array('fresh' => 0, 'aging' => 0, 'stale' => 0),
            ),
            'categories' => array(
                'total' => 0,
                'thin_content' => 0,
                'short_content' => 0,
                'good_content' => 0,
                'rich_content' => 0,
                'no_description' => 0,
                'avg_word_count' => 0,
            ),
            'thin_content_list' => array(),
            'stale_content_list' => array(),
        );

        // Produkte analysieren
        try {
            $stmt = $this->db->query("
                SELECT pd.products_id, pd.products_name, pd.products_description,
                       p.products_date_added, p.products_last_modified, p.products_status
                FROM products_description pd
                JOIN products p ON p.products_id = pd.products_id
                WHERE pd.language_id = 2 AND p.products_status = 1
                ORDER BY pd.products_id DESC
                LIMIT 5000
            ");
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $word_counts = array();
            $now = time();

            foreach ($products as $p) {
                $audit['products']['total']++;
                $text = strip_tags($p['products_description'] ?? '');
                $word_count = str_word_count($text, 0, 'äöüÄÖÜß');
                $word_counts[] = $word_count;

                if ($word_count === 0) {
                    $audit['products']['no_description']++;
                    $audit['thin_content_list'][] = array('type' => 'product', 'id' => $p['products_id'], 'name' => $p['products_name'], 'words' => 0);
                } elseif ($word_count < 100) {
                    $audit['products']['thin_content']++;
                    $audit['thin_content_list'][] = array('type' => 'product', 'id' => $p['products_id'], 'name' => $p['products_name'], 'words' => $word_count);
                } elseif ($word_count < 300) {
                    $audit['products']['short_content']++;
                } elseif ($word_count < 1000) {
                    $audit['products']['good_content']++;
                } else {
                    $audit['products']['rich_content']++;
                }

                // Freshness
                $last_mod = $p['products_last_modified'] ? strtotime($p['products_last_modified']) : strtotime($p['products_date_added']);
                $age_days = ($now - $last_mod) / 86400;
                if ($age_days < 90) {
                    $audit['products']['freshness']['fresh']++;
                } elseif ($age_days < 365) {
                    $audit['products']['freshness']['aging']++;
                } else {
                    $audit['products']['freshness']['stale']++;
                    if (count($audit['stale_content_list']) < 50) {
                        $audit['stale_content_list'][] = array(
                            'type' => 'product', 'id' => $p['products_id'],
                            'name' => $p['products_name'],
                            'last_modified' => $p['products_last_modified'] ?: $p['products_date_added'],
                            'age_days' => round($age_days),
                        );
                    }
                }
            }

            if (!empty($word_counts)) {
                $audit['products']['avg_word_count'] = round(array_sum($word_counts) / count($word_counts));
            }
        } catch (PDOException $e) {
            $audit['issues'][] = 'DB Fehler (Produkte): ' . $e->getMessage();
        }

        // Kategorien analysieren
        try {
            $stmt = $this->db->query("
                SELECT cd.categories_id, cd.categories_name, cd.categories_description
                FROM categories_description cd
                WHERE cd.language_id = 2
            ");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $cat_word_counts = array();
            foreach ($categories as $c) {
                $audit['categories']['total']++;
                $text = strip_tags($c['categories_description'] ?? '');
                $word_count = str_word_count($text, 0, 'äöüÄÖÜß');
                $cat_word_counts[] = $word_count;

                if ($word_count === 0) {
                    $audit['categories']['no_description']++;
                } elseif ($word_count < 100) {
                    $audit['categories']['thin_content']++;
                } elseif ($word_count < 300) {
                    $audit['categories']['short_content']++;
                } elseif ($word_count < 1000) {
                    $audit['categories']['good_content']++;
                } else {
                    $audit['categories']['rich_content']++;
                }
            }

            if (!empty($cat_word_counts)) {
                $audit['categories']['avg_word_count'] = round(array_sum($cat_word_counts) / count($cat_word_counts));
            }
        } catch (PDOException $e) {
            $audit['issues'][] = 'DB Fehler (Kategorien): ' . $e->getMessage();
        }

        // Thin Content Liste begrenzen
        $audit['thin_content_list'] = array_slice($audit['thin_content_list'], 0, 50);

        // Ergebnis speichern
        $this->writeJson($this->seo_dir . 'content_audit.json', $audit);

        return $audit;
    }

    // ================================================================
    // INTERNAL LINKS ANALYSE
    // ================================================================

    /**
     * Internal Links Analyse (Crawl-basiert, Stichprobe)
     */
    public function analyzeInternalLinks($max_urls = 100) {
        $start_time = time();
        $urls = $this->getSitemapUrls();

        if (count($urls) > $max_urls) {
            // Wichtige URLs zuerst, dann Stichprobe
            $important = array_slice($urls, 0, 20);
            $rest = array_slice($urls, 20);
            shuffle($rest);
            $urls = array_merge($important, array_slice($rest, 0, $max_urls - 20));
        }

        $link_map = array();      // URL => array of outgoing internal links
        $incoming = array();      // URL => count of incoming links
        $broken_links = array();

        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWREDIRECTS => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'MrHanf-SEO-Scanner/1.0',
            ));
            $html = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$html || $status >= 400) continue;

            // Interne Links extrahieren
            $internal_links = array();
            if (preg_match_all('/<a[^>]+href=["\']([^"\'#]+)["\'][^>]*>/i', $html, $matches)) {
                foreach ($matches[1] as $href) {
                    // Nur interne Links
                    if (strpos($href, '/') === 0 || strpos($href, $this->site_url) === 0) {
                        $full_url = strpos($href, 'http') === 0 ? $href : $this->site_url . $href;
                        $full_url = strtok($full_url, '?'); // Query-Parameter entfernen
                        $full_url = rtrim($full_url, '/');
                        $internal_links[] = $full_url;

                        // Incoming Links zaehlen
                        if (!isset($incoming[$full_url])) $incoming[$full_url] = 0;
                        $incoming[$full_url]++;
                    }
                }
            }

            $link_map[$url] = array_unique($internal_links);

            // Timeout-Schutz
            if ((time() - $start_time) > 300) break;
            usleep(100000);
        }

        // Verwaiste Seiten finden (in Sitemap aber keine Incoming Links)
        $all_sitemap_urls = array_map(function($u) { return rtrim($u, '/'); }, $this->getSitemapUrls());
        $orphan_pages = array();
        foreach ($all_sitemap_urls as $sitemap_url) {
            if (!isset($incoming[$sitemap_url]) || $incoming[$sitemap_url] === 0) {
                $orphan_pages[] = $sitemap_url;
            }
        }

        // Link-Verteilung
        arsort($incoming);
        $top_linked = array_slice($incoming, 0, 20, true);
        $least_linked = array_slice(array_filter($incoming, function($c) { return $c > 0; }), -20, 20, true);

        $analysis = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'urls_crawled' => count($link_map),
            'total_internal_links' => array_sum(array_map('count', $link_map)),
            'unique_linked_urls' => count($incoming),
            'orphan_pages' => array_slice($orphan_pages, 0, 50),
            'orphan_count' => count($orphan_pages),
            'top_linked' => $top_linked,
            'least_linked' => $least_linked,
            'avg_links_per_page' => count($link_map) > 0
                ? round(array_sum(array_map('count', $link_map)) / count($link_map), 1)
                : 0,
        );

        $this->writeJson($this->seo_dir . 'internal_links.json', $analysis);

        return $analysis;
    }

    /**
     * Internal Links Ergebnisse abrufen
     */
    public function getInternalLinksAnalysis() {
        return $this->readJson($this->seo_dir . 'internal_links.json', array('has_data' => false));
    }

    // ================================================================
    // KEYWORD MONITOR (GSC-basiert)
    // ================================================================

    /**
     * Keyword Rankings aus GSC-Daten extrahieren und speichern
     * @param array $gsc_data GSC Performance-Daten (von FPC_GoogleSearchConsole)
     */
    public function updateKeywordMonitor($gsc_data) {
        $monitor = $this->readJson($this->seo_dir . 'keyword_monitor.json', array('snapshots' => array()));

        $snapshot = array(
            'timestamp' => date('Y-m-d H:i:s'),
            'keywords' => array(),
        );

        if (isset($gsc_data['byQuery']) && is_array($gsc_data['byQuery'])) {
            foreach ($gsc_data['byQuery'] as $q) {
                $snapshot['keywords'][] = array(
                    'query' => $q['keys'][0] ?? $q['query'] ?? '',
                    'clicks' => $q['clicks'] ?? 0,
                    'impressions' => $q['impressions'] ?? 0,
                    'ctr' => round(($q['ctr'] ?? 0) * 100, 2),
                    'position' => round($q['position'] ?? 0, 1),
                );
            }
        }

        // Nach Position sortieren
        usort($snapshot['keywords'], function($a, $b) {
            return $a['position'] - $b['position'];
        });

        $monitor['snapshots'][] = $snapshot;

        // Max 26 Snapshots (6 Monate bei 14-Tage-Intervall)
        if (count($monitor['snapshots']) > 26) {
            $monitor['snapshots'] = array_slice($monitor['snapshots'], -26);
        }

        $this->writeJson($this->seo_dir . 'keyword_monitor.json', $monitor);

        return array(
            'ok' => true,
            'msg' => count($snapshot['keywords']) . ' Keywords gespeichert',
            'top_keywords' => array_slice($snapshot['keywords'], 0, 20),
        );
    }

    /**
     * Keyword Monitor Daten abrufen
     */
    public function getKeywordMonitor() {
        return $this->readJson($this->seo_dir . 'keyword_monitor.json', array('has_data' => false, 'snapshots' => array()));
    }

    /**
     * Keyword Ranking-Veraenderungen berechnen
     */
    public function getKeywordChanges() {
        $monitor = $this->getKeywordMonitor();
        if (count($monitor['snapshots']) < 2) {
            return array('ok' => false, 'msg' => 'Mindestens 2 Snapshots noetig fuer Vergleich');
        }

        $current = end($monitor['snapshots']);
        $previous = $monitor['snapshots'][count($monitor['snapshots']) - 2];

        $current_map = array();
        foreach ($current['keywords'] as $kw) {
            $current_map[$kw['query']] = $kw;
        }

        $previous_map = array();
        foreach ($previous['keywords'] as $kw) {
            $previous_map[$kw['query']] = $kw;
        }

        $changes = array('improved' => array(), 'declined' => array(), 'new' => array(), 'lost' => array());

        foreach ($current_map as $query => $kw) {
            if (isset($previous_map[$query])) {
                $diff = $previous_map[$query]['position'] - $kw['position']; // Positiv = verbessert
                if ($diff > 1) {
                    $changes['improved'][] = array_merge($kw, array('change' => round($diff, 1)));
                } elseif ($diff < -1) {
                    $changes['declined'][] = array_merge($kw, array('change' => round($diff, 1)));
                }
            } else {
                $changes['new'][] = $kw;
            }
        }

        foreach ($previous_map as $query => $kw) {
            if (!isset($current_map[$query])) {
                $changes['lost'][] = $kw;
            }
        }

        // Sortieren
        usort($changes['improved'], function($a, $b) { return $b['change'] - $a['change']; });
        usort($changes['declined'], function($a, $b) { return $a['change'] - $b['change']; });

        return array(
            'ok' => true,
            'period' => array('from' => $previous['timestamp'], 'to' => $current['timestamp']),
            'changes' => $changes,
            'summary' => array(
                'improved' => count($changes['improved']),
                'declined' => count($changes['declined']),
                'new' => count($changes['new']),
                'lost' => count($changes['lost']),
            ),
        );
    }

    // ================================================================
    // HELPER
    // ================================================================

    private function detectLanguage($url) {
        if (preg_match('#/(en)/#', $url)) return 'en';
        if (preg_match('#/(fr)/#', $url)) return 'fr';
        if (preg_match('#/(es)/#', $url)) return 'es';
        return 'de';
    }

    private function detectWebroot() {
        $candidates = array(
            $this->base_dir,
            dirname($this->base_dir) . '/',
            '/var/www/html/',
        );
        foreach ($candidates as $path) {
            if (is_file($path . 'robots.txt') || is_file($path . 'index.php')) {
                return $path;
            }
        }
        return $this->base_dir;
    }

    private function getSitemapUrls() {
        $sitemap_url = $this->site_url . '/sitemap.xml';
        $urls = array();

        $ch = curl_init($sitemap_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $xml = curl_exec($ch);
        curl_close($ch);

        if (!$xml) return $urls;

        if (strpos($xml, '<sitemapindex') !== false) {
            if (preg_match_all('/<loc>(.*?)<\/loc>/i', $xml, $matches)) {
                foreach ($matches[1] as $sub_url) {
                    $ch2 = curl_init($sub_url);
                    curl_setopt_array($ch2, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false));
                    $sub_xml = curl_exec($ch2);
                    curl_close($ch2);
                    if ($sub_xml && preg_match_all('/<loc>(.*?)<\/loc>/i', $sub_xml, $sub_matches)) {
                        $urls = array_merge($urls, $sub_matches[1]);
                    }
                }
            }
        } else {
            if (preg_match_all('/<loc>(.*?)<\/loc>/i', $xml, $matches)) {
                $urls = $matches[1];
            }
        }

        return $urls;
    }

    private function readJson($file, $default = array()) {
        if (!is_file($file)) return $default;
        $data = @json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : $default;
    }

    private function writeJson($file, $data) {
        return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
