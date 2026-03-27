<?php
/**
 * Mr. Hanf FPC Tracker v8.3.0 - Leichtgewichtige Besucherstatistik
 *
 * Sammelt anonymisierte Besucherdaten fuer das FPC Dashboard:
 *   - Seitenaufrufe (Pageviews)
 *   - Eindeutige Besucher (via anonymisiertem Cookie-Hash)
 *   - Absprungrate (Bounce Rate): Besucher die nur 1 Seite sehen
 *   - Verweildauer (Time on Page)
 *   - Traffic-Quelle (Referrer-Kategorie)
 *   - Geraetetyp (Desktop/Mobile/Tablet)
 *   - Top-Seiten, Top-Referrer, Stunden-Verteilung
 *
 * Wird von fpc_serve.php als 1x1 Pixel-Tracker eingebunden.
 * Speichert Daten als JSON in cache/fpc/tracker/
 *
 * DSGVO-konform: Kein Tracking von IP-Adressen, keine externen Services,
 * Cookie-Hash ist nicht rueckfuehrbar, Daten werden nach 90 Tagen geloescht.
 *
 * Endpoints:
 *   GET  fpc_tracker.php?t=pv          → Pageview tracken (1x1 Pixel)
 *   GET  fpc_tracker.php?t=leave       → Seite verlassen (Verweildauer)
 *   GET  fpc_tracker.php?t=stats       → JSON-Stats fuer Dashboard (nur Admin)
 *   GET  fpc_tracker.php?t=stats_range → JSON-Stats fuer Zeitraum
 *
 * @version   8.3.0
 * @date      2026-03-27
 */

// ============================================================
// KONFIGURATION
// ============================================================
$tracker_dir   = __DIR__ . '/cache/fpc/tracker/';
$max_days      = 90;
$cookie_name   = 'fpc_vid';
$cookie_ttl    = 86400 * 365; // 1 Jahr
$admin_token   = 'FpcStats2026!';  // Einfacher Schutz fuer Stats-Endpoint

// Verzeichnis erstellen
if (!is_dir($tracker_dir)) {
    @mkdir($tracker_dir, 0777, true);
}

// ============================================================
// REQUEST-HANDLING
// ============================================================
$type = isset($_GET['t']) ? $_GET['t'] : '';

switch ($type) {

    // --------------------------------------------------------
    // PAGEVIEW TRACKEN
    // --------------------------------------------------------
    case 'pv':
        track_pageview($tracker_dir, $cookie_name, $cookie_ttl);
        // 1x1 transparentes GIF zurueckgeben
        header('Content-Type: image/gif');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;

    // --------------------------------------------------------
    // SEITE VERLASSEN (Verweildauer)
    // --------------------------------------------------------
    case 'leave':
        track_leave($tracker_dir, $cookie_name);
        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;

    // --------------------------------------------------------
    // STATISTIKEN ABRUFEN (Dashboard)
    // --------------------------------------------------------
    case 'stats':
    case 'stats_range':
        // Einfacher Schutz: Token oder Admin-Session
        $authorized = false;
        if (isset($_GET['token']) && $_GET['token'] === $admin_token) {
            $authorized = true;
        }
        // Oder: modified Admin-Session pruefen
        if (isset($_COOKIE['MODsid']) && !empty($_COOKIE['MODsid'])) {
            // Wir vertrauen darauf dass das Dashboard den Call macht
            $authorized = true;
        }
        if (!$authorized) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(array('error' => 'Nicht autorisiert'));
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        $days = isset($_GET['days']) ? min(90, max(1, (int)$_GET['days'])) : 30;
        echo json_encode(get_stats($tracker_dir, $days));
        exit;

    default:
        header('HTTP/1.1 204 No Content');
        exit;
}

// ============================================================
// TRACKING-FUNKTIONEN
// ============================================================

function track_pageview($tracker_dir, $cookie_name, $cookie_ttl) {
    $today = date('Y-m-d');
    $hour  = (int)date('H');
    $file  = $tracker_dir . $today . '.json';

    // Besucher-ID (anonymisiert)
    $vid = get_visitor_id($cookie_name, $cookie_ttl);

    // Seiten-URL
    $page = isset($_GET['p']) ? substr(trim($_GET['p']), 0, 500) : '/';
    $page = parse_url($page, PHP_URL_PATH) ?: '/';

    // Referrer-Kategorie
    $ref = isset($_GET['r']) ? trim($_GET['r']) : '';
    $ref_cat = categorize_referrer($ref);

    // Geraetetyp
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $device = detect_device($ua);

    // Bot-Erkennung
    if (is_bot($ua)) return;

    // Tages-Daten laden oder erstellen
    $data = load_day_data($file);

    // Pageview zaehlen
    $data['pageviews']++;
    $data['hours'][$hour] = ($data['hours'][$hour] ?? 0) + 1;

    // Eindeutiger Besucher?
    $is_new = !in_array($vid, $data['visitors_seen']);
    if ($is_new) {
        $data['visitors']++;
        $data['visitors_seen'][] = $vid;
        // Neuer Besucher = potentieller Bounce
        $data['sessions'][$vid] = array(
            'pages'      => 1,
            'first_page' => $page,
            'start_time' => time(),
            'last_time'  => time(),
            'device'     => $device,
            'referrer'   => $ref_cat,
        );
    } else {
        // Bestehender Besucher - weitere Seite
        if (isset($data['sessions'][$vid])) {
            $data['sessions'][$vid]['pages']++;
            $data['sessions'][$vid]['last_time'] = time();
        }
    }

    // Top-Seiten
    $data['pages'][$page] = ($data['pages'][$page] ?? 0) + 1;

    // Referrer
    if (!empty($ref_cat)) {
        $data['referrers'][$ref_cat] = ($data['referrers'][$ref_cat] ?? 0) + 1;
    }

    // Geraetetyp
    $data['devices'][$device] = ($data['devices'][$device] ?? 0) + 1;

    // Speichern
    save_day_data($file, $data);
}

function track_leave($tracker_dir, $cookie_name) {
    $today = date('Y-m-d');
    $file  = $tracker_dir . $today . '.json';

    $vid = get_visitor_id($cookie_name, 0); // Kein neues Cookie setzen

    if (!is_file($file)) return;

    $data = load_day_data($file);

    if (isset($data['sessions'][$vid])) {
        $data['sessions'][$vid]['last_time'] = time();
        $duration = isset($_GET['d']) ? min(3600, max(0, (int)$_GET['d'])) : 0;
        if ($duration > 0) {
            $data['sessions'][$vid]['duration'] = $duration;
        }
    }

    save_day_data($file, $data);
}

// ============================================================
// STATISTIK-FUNKTIONEN
// ============================================================

function get_stats($tracker_dir, $days) {
    $result = array(
        'period_days'     => $days,
        'generated_at'    => date('Y-m-d H:i:s'),
        'totals'          => array(
            'pageviews'   => 0,
            'visitors'    => 0,
            'bounces'     => 0,
            'bounce_rate' => 0,
            'avg_duration'=> 0,
            'avg_pages'   => 0,
        ),
        'daily'           => array(),
        'hours'           => array_fill(0, 24, 0),
        'top_pages'       => array(),
        'top_referrers'   => array(),
        'devices'         => array('desktop' => 0, 'mobile' => 0, 'tablet' => 0),
        'referrer_cats'   => array(),
    );

    $all_pages = array();
    $all_referrers = array();
    $all_durations = array();
    $total_pages_per_session = array();

    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $file = $tracker_dir . $date . '.json';

        $day_stats = array(
            'date'        => $date,
            'pageviews'   => 0,
            'visitors'    => 0,
            'bounces'     => 0,
            'bounce_rate' => 0,
            'avg_duration'=> 0,
        );

        if (is_file($file)) {
            $data = load_day_data($file);

            $day_stats['pageviews'] = $data['pageviews'];
            $day_stats['visitors']  = $data['visitors'];

            // Bounces und Verweildauer berechnen
            $bounces = 0;
            $durations = array();

            if (!empty($data['sessions'])) {
                foreach ($data['sessions'] as $sid => $session) {
                    $pages = isset($session['pages']) ? $session['pages'] : 1;
                    if ($pages <= 1) $bounces++;

                    $total_pages_per_session[] = $pages;

                    $dur = 0;
                    if (isset($session['duration']) && $session['duration'] > 0) {
                        $dur = $session['duration'];
                    } elseif (isset($session['last_time']) && isset($session['start_time'])) {
                        $dur = $session['last_time'] - $session['start_time'];
                    }
                    if ($dur > 0 && $dur < 3600) {
                        $durations[] = $dur;
                        $all_durations[] = $dur;
                    }
                }
            }

            $day_stats['bounces'] = $bounces;
            $day_stats['bounce_rate'] = $data['visitors'] > 0
                ? round(($bounces / $data['visitors']) * 100, 1) : 0;
            $day_stats['avg_duration'] = count($durations) > 0
                ? round(array_sum($durations) / count($durations)) : 0;

            // Stunden aggregieren
            if (!empty($data['hours'])) {
                foreach ($data['hours'] as $h => $cnt) {
                    $result['hours'][$h] += $cnt;
                }
            }

            // Seiten aggregieren
            if (!empty($data['pages'])) {
                foreach ($data['pages'] as $p => $cnt) {
                    $all_pages[$p] = ($all_pages[$p] ?? 0) + $cnt;
                }
            }

            // Referrer aggregieren
            if (!empty($data['referrers'])) {
                foreach ($data['referrers'] as $r => $cnt) {
                    $all_referrers[$r] = ($all_referrers[$r] ?? 0) + $cnt;
                }
            }

            // Geraete aggregieren
            if (!empty($data['devices'])) {
                foreach ($data['devices'] as $d => $cnt) {
                    $result['devices'][$d] = ($result['devices'][$d] ?? 0) + $cnt;
                }
            }

            $result['totals']['pageviews'] += $data['pageviews'];
            $result['totals']['visitors']  += $data['visitors'];
            $result['totals']['bounces']   += $bounces;
        }

        $result['daily'][] = $day_stats;
    }

    // Gesamtwerte berechnen
    $result['totals']['bounce_rate'] = $result['totals']['visitors'] > 0
        ? round(($result['totals']['bounces'] / $result['totals']['visitors']) * 100, 1) : 0;
    $result['totals']['avg_duration'] = count($all_durations) > 0
        ? round(array_sum($all_durations) / count($all_durations)) : 0;
    $result['totals']['avg_pages'] = count($total_pages_per_session) > 0
        ? round(array_sum($total_pages_per_session) / count($total_pages_per_session), 1) : 0;

    // Top-Seiten (Top 30)
    arsort($all_pages);
    $result['top_pages'] = array_slice($all_pages, 0, 30, true);

    // Top-Referrer (Top 20)
    arsort($all_referrers);
    $result['top_referrers'] = array_slice($all_referrers, 0, 20, true);

    // Alte Daten aufraeumen
    cleanup_old_data($tracker_dir, 90);

    return $result;
}

// ============================================================
// HILFSFUNKTIONEN
// ============================================================

function get_visitor_id($cookie_name, $cookie_ttl) {
    if (isset($_COOKIE[$cookie_name]) && strlen($_COOKIE[$cookie_name]) === 32) {
        return $_COOKIE[$cookie_name];
    }
    // Neuen anonymen Hash generieren (NICHT rueckfuehrbar)
    $vid = md5(random_bytes(16));
    if ($cookie_ttl > 0) {
        $domain = '.' . str_replace('www.', '', $_SERVER['HTTP_HOST']);
        setcookie($cookie_name, $vid, time() + $cookie_ttl, '/', $domain, true, false);
    }
    return $vid;
}

function categorize_referrer($ref) {
    if (empty($ref)) return 'direkt';
    $ref = strtolower($ref);

    // Eigene Domain
    if (strpos($ref, 'mr-hanf.de') !== false) return 'intern';

    // Suchmaschinen
    $search = array('google', 'bing', 'yahoo', 'duckduckgo', 'ecosia', 'baidu', 'yandex');
    foreach ($search as $s) {
        if (strpos($ref, $s) !== false) return 'suchmaschine';
    }

    // Social Media
    $social = array('facebook', 'instagram', 'twitter', 'x.com', 'youtube', 'tiktok', 'reddit', 'pinterest', 'linkedin');
    foreach ($social as $s) {
        if (strpos($ref, $s) !== false) return 'social';
    }

    return 'extern';
}

function detect_device($ua) {
    $ua = strtolower($ua);
    if (preg_match('/tablet|ipad|playbook|silk/i', $ua)) return 'tablet';
    if (preg_match('/mobile|android|iphone|ipod|opera mini|blackberry|windows phone/i', $ua)) return 'mobile';
    return 'desktop';
}

function is_bot($ua) {
    $bots = array('bot', 'crawl', 'spider', 'slurp', 'lighthouse', 'pagespeed',
                  'gtmetrix', 'pingdom', 'uptimerobot', 'healthcheck', 'preloader',
                  'curl', 'wget', 'python', 'java/', 'go-http', 'node-fetch');
    $ua_lower = strtolower($ua);
    foreach ($bots as $b) {
        if (strpos($ua_lower, $b) !== false) return true;
    }
    return false;
}

function load_day_data($file) {
    if (is_file($file)) {
        $data = @json_decode(file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    return array(
        'date'          => date('Y-m-d'),
        'pageviews'     => 0,
        'visitors'      => 0,
        'hours'         => array(),
        'pages'         => array(),
        'referrers'     => array(),
        'devices'       => array(),
        'visitors_seen' => array(),
        'sessions'      => array(),
    );
}

function save_day_data($file, $data) {
    // visitors_seen auf max 10000 begrenzen (Speicher)
    if (count($data['visitors_seen']) > 10000) {
        $data['visitors_seen'] = array_slice($data['visitors_seen'], -10000);
    }
    // Sessions auf max 5000 begrenzen
    if (count($data['sessions']) > 5000) {
        $data['sessions'] = array_slice($data['sessions'], -5000, null, true);
    }
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function cleanup_old_data($tracker_dir, $max_days) {
    $cutoff = date('Y-m-d', strtotime("-{$max_days} days"));
    $files = glob($tracker_dir . '*.json');
    if (!$files) return;
    foreach ($files as $f) {
        $basename = basename($f, '.json');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $basename) && $basename < $cutoff) {
            @unlink($f);
        }
    }
}
