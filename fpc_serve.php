<?php
/**
 * Mr. Hanf Full Page Cache v10.4.0 - Cache-Handler
 *
 * Dieses Script wird von Apache via RewriteRule [END] aufgerufen
 * und liefert gecachte HTML-Dateien per readfile() aus.
 *
 * Warum PHP statt direkter Apache-Auslieferung?
 *   - Artfiles cache/.htaccess blockiert .html mit 403
 *   - Direkte Auslieferung verursacht Redirect-Loop mit CLEAN SEO URL
 *   - PHP-Overhead: ~5ms (validiert + readfile + exit)
 *   - Zusaetzliche Validierung zur Laufzeit benoetigt wird
 *
 * CHANGELOG v10.4.0:
 *   - NEU: Stale-While-Revalidate - abgelaufene Cache-Dateien werden weiterhin
 *     ausgeliefert (bis MAX_AGE), statt sofort MISS zu liefern
 *   - NEU: X-FPC-Stale Header wenn abgelaufene Datei ausgeliefert wird
 *   - NEU: TTL und Stale-Grenze aus DB konfigurierbar
 *   - VERBESSERT: Kein Auto-Delete mehr bei abgelaufenen Dateien (Refresh-Modus erneuert sie)
 *
 * CHANGELOG v9.0.0:
 *   - NEU: Request-Logging fuer Live Inspector und SEO-Bot-Tracking
 *   - NEU: X-FPC-Reason Header bei MISS/BYPASS
 *   - NEU: Bot-Erkennung (Googlebot, Bing, Ahrefs, Semrush, GPTBot, etc.)
 *   - NEU: Automatische Log-Rotation (7 Tage)
 *   - v8.3.0: Besucherstatistik-Tracker Pixel-Injection
 *   - v8.2.0: AJAX-Warenkorb fuer gecachte Seiten
 *   - v8.1.0: Session-Initializer JavaScript Injection
 *
 * @version   10.4.0
 * @date      2026-03-29
 */

// ============================================================
// KONFIGURATION
// ============================================================
$FPC_MIN_FILESIZE  = 500;      // Mindestgroesse in Bytes
$FPC_HEALTH_MARKER = '<!-- FPC-VALID -->';  // Pflicht-Marker im HTML
$FPC_AUTO_DELETE   = true;     // Korrupte Dateien automatisch loeschen
$FPC_REQUEST_LOG   = true;     // Request-Logging fuer Inspector/SEO (v9.0.0)
$FPC_LOG_DIR       = __DIR__ . '/cache/fpc/logs';  // Log-Verzeichnis

// v10.4.0: TTL-Konfiguration
// $FPC_CACHE_TTL = primaere Lebensdauer (aus DB, default 24h)
// $FPC_MAX_AGE   = maximales Stale-Alter (Datei wird bis hierhin ausgeliefert, default 48h)
// Zwischen TTL und MAX_AGE: Datei wird als STALE ausgeliefert (Header: X-FPC-Stale: true)
// Nach MAX_AGE: Datei wird NICHT mehr ausgeliefert (MISS)
$FPC_CACHE_TTL = 86400;   // 24h - wird spaeter aus DB ueberschrieben
$FPC_MAX_AGE   = 172800;  // 48h - absolute Obergrenze

// ============================================================
// v10.4.0: TTL aus DB laden (einmalig, gecacht)
// ============================================================
$fpc_ttl_cache_file = __DIR__ . '/cache/fpc/ttl_config.json';
$fpc_ttl_loaded = false;

// TTL-Config wird alle 5 Minuten aus DB aktualisiert
if (is_file($fpc_ttl_cache_file) && (time() - filemtime($fpc_ttl_cache_file)) < 300) {
    $ttl_data = @json_decode(@file_get_contents($fpc_ttl_cache_file), true);
    if (is_array($ttl_data) && isset($ttl_data['cache_ttl'])) {
        $FPC_CACHE_TTL = (int) $ttl_data['cache_ttl'];
        $FPC_MAX_AGE   = (int) ($ttl_data['max_age'] ?? $FPC_CACHE_TTL * 2);
        $fpc_ttl_loaded = true;
    }
}

if (!$fpc_ttl_loaded) {
    // Aus DB laden (nur wenn configure.php existiert)
    if (is_file(__DIR__ . '/includes/configure.php')) {
        if (!defined('_VALID_XTC')) define('_VALID_XTC', true);
        @include_once(__DIR__ . '/includes/configure.php');
        if (defined('DB_SERVER') && defined('DB_SERVER_USERNAME')) {
            $fpc_db = @new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
            if (!$fpc_db->connect_error) {
                $r = $fpc_db->query("SELECT configuration_value FROM configuration WHERE configuration_key = 'MODULE_MRHANF_FPC_CACHE_TIME' LIMIT 1");
                if ($r && $row = $r->fetch_assoc()) {
                    $FPC_CACHE_TTL = (int) $row['configuration_value'];
                    $FPC_MAX_AGE = $FPC_CACHE_TTL * 2; // Stale-Grenze = 2x TTL
                }
                $fpc_db->close();
                // Cache fuer 5 Minuten
                @file_put_contents($fpc_ttl_cache_file, json_encode(array(
                    'cache_ttl' => $FPC_CACHE_TTL,
                    'max_age'   => $FPC_MAX_AGE,
                    'updated'   => date('Y-m-d H:i:s'),
                )));
            }
        }
    }
}

// ============================================================
// SICHERHEITSCHECKS
// ============================================================

// Request-Start-Zeit fuer TTFB-Messung (v9.0.0)
$fpc_request_start = microtime(true);
$fpc_cache_status = 'MISS';
$fpc_miss_reason = '';
$fpc_http_code = 200;

// Bot-Erkennung (v9.0.0)
$fpc_ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$fpc_is_bot = false;
$fpc_bot_name = '';
$bot_patterns = array(
    'Googlebot' => 'Googlebot',
    'bingbot' => 'Bing',
    'Baiduspider' => 'Baidu',
    'YandexBot' => 'Yandex',
    'DuckDuckBot' => 'DuckDuckGo',
    'Slurp' => 'Yahoo',
    'facebot' => 'Facebook',
    'Twitterbot' => 'Twitter',
    'AhrefsBot' => 'Ahrefs',
    'SemrushBot' => 'Semrush',
    'MJ12bot' => 'Majestic',
    'PetalBot' => 'Petal',
    'Applebot' => 'Apple',
    'GPTBot' => 'GPTBot',
    'ClaudeBot' => 'ClaudeBot',
);
foreach ($bot_patterns as $pattern => $name) {
    if (stripos($fpc_ua, $pattern) !== false) {
        $fpc_is_bot = true;
        $fpc_bot_name = $name;
        break;
    }
}

// Nur GET-Requests cachen
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $fpc_miss_reason = 'NOT_GET';
    fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
    return false;
}

// v8.0.7: Bypass-Cookie pruefen
if (isset($_COOKIE['fpc_bypass']) && $_COOKIE['fpc_bypass'] === '1') {
    $fpc_miss_reason = 'BYPASS_COOKIE';
    $fpc_cache_status = 'BYPASS';
    fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
    return false;
}

// Request-URI bereinigen
$uri = $_SERVER['REQUEST_URI'];

// Query-String entfernen (gecachte Seiten sind ohne Parameter)
$pos = strpos($uri, '?');
if ($pos !== false) {
    $uri = substr($uri, 0, $pos);
}

// Pfad normalisieren
$uri = rtrim($uri, '/');
if ($uri === '') {
    $uri = '/';
}

// ============================================================
// v10.0.0: SEO REDIRECT CHECK (vor Cache-Pruefung)
// ============================================================
$fpc_seo_file = __DIR__ . '/fpc_seo.php';
if (is_file($fpc_seo_file)) {
    try {
        require_once $fpc_seo_file;
        $fpc_seo = new FpcSeo(__DIR__ . '/');
        $fpc_redirect = $fpc_seo->findRedirect($uri);
        if ($fpc_redirect) {
            $fpc_redir_code = intval($fpc_redirect['type']);
            if ($fpc_redir_code === 410) {
                // 410 Gone - Seite existiert nicht mehr
                header('HTTP/1.1 410 Gone');
                header('X-FPC-SEO: GONE');
                $fpc_cache_status = 'REDIRECT';
                $fpc_miss_reason = 'SEO_410_GONE';
                $fpc_http_code = 410;
                fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
                echo '<!DOCTYPE html><html><head><title>410 Gone</title></head><body><h1>410 Gone</h1><p>Diese Seite existiert nicht mehr.</p></body></html>';
                exit;
            }
            // 301, 302, 307 Redirect
            if (!in_array($fpc_redir_code, array(301, 302, 307))) $fpc_redir_code = 301;
            $fpc_redir_target = $fpc_redirect['target'];
            // Relative URLs zu absoluten machen
            if (strpos($fpc_redir_target, 'http') !== 0) {
                $fpc_redir_target = 'https://' . $_SERVER['HTTP_HOST'] . $fpc_redir_target;
            }
            header('Location: ' . $fpc_redir_target, true, $fpc_redir_code);
            header('X-FPC-SEO: REDIRECT-' . $fpc_redir_code);
            $fpc_cache_status = 'REDIRECT';
            $fpc_miss_reason = 'SEO_REDIRECT_' . $fpc_redir_code;
            $fpc_http_code = $fpc_redir_code;
            fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
            exit;
        }
    } catch (Exception $e) {
        // SEO-Fehler darf Cache nicht blockieren - still ignorieren
    }
}

// --- Zweite Sicherheitsstufe: URL-basierte Ausschlussliste ---
$excluded_paths = array(
    '/vergleich',           // Produktvergleich (sessionabhaengig)
    '/wishlist',            // Merkzettel (sessionabhaengig)
    '/checkout',            // Bestellprozess
    '/kasse',               // Kasse/Checkout (SEO-URL) - v8.0.1
    '/login',               // Login-Seite
    '/account',             // Kundenkonto
    '/shopping_cart',       // Warenkorb (alt)
    '/warenkorb',           // Warenkorb (SEO-URL) - v8.0.1
    '/logoff',              // Abmelden
    '/password_double_opt', // Passwort-Opt-In
    '/create_account',      // Registrierung
    '/contact_us',          // Kontaktformular
    '/tell_a_friend',       // Weiterempfehlen
    '/product_reviews_write', // Bewertung schreiben
    '/admin',               // Admin-Bereich
);

foreach ($excluded_paths as $excluded) {
    if ($uri === $excluded || strpos($uri, $excluded . '/') === 0 || strpos($uri, $excluded . '?') === 0) {
        return false;
    }
}

// Cache-Datei Pfad berechnen
$cache_dir  = __DIR__ . '/cache/fpc';
$clean_path = trim($uri, '/');

if ($clean_path === '') {
    $cache_file = $cache_dir . '/index.html';
} else {
    $cache_file = $cache_dir . '/' . $clean_path . '/index.html';
}

// Sicherheitscheck: Pfad darf nicht aus dem Cache-Verzeichnis ausbrechen
$real_cache = realpath($cache_dir);
if ($real_cache === false) {
    return false;
}

// Cache-Datei existiert?
if (!is_file($cache_file)) {
    $fpc_miss_reason = 'FILE_NOT_FOUND';
    // v10.0.0: 404-Logging fuer SEO
    if (isset($fpc_seo) && $fpc_seo instanceof FpcSeo) {
        try {
            $fpc_referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
            $fpc_seo->log404($uri, $fpc_referer, $fpc_ua);
        } catch (Exception $e) {}
    }
    fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
    return false;
}

// Realpath-Check (verhindert Directory Traversal)
$real_file = realpath($cache_file);
if ($real_file === false || strpos($real_file, $real_cache) !== 0) {
    $fpc_miss_reason = 'SECURITY_CHECK';
    fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
    return false;
}

// ============================================================
// VALIDIERUNG VOR AUSLIEFERUNG
// ============================================================

// 1. Dateigroesse pruefen (leere/korrupte Dateien abfangen)
$filesize = filesize($cache_file);
if ($filesize === false || $filesize < $FPC_MIN_FILESIZE) {
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
    $fpc_miss_reason = 'FILE_TOO_SMALL';
    fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
    return false;
}

// 2. TTL-Check mit Stale-While-Revalidate (v10.4.0)
$mtime = filemtime($cache_file);
$age = time() - $mtime;
$is_stale = false;

if ($age > $FPC_MAX_AGE) {
    // ==========================================================
    // v10.4.0: Datei ist AELTER als MAX_AGE (z.B. > 48h)
    // -> Nicht mehr ausliefern, aber NICHT automatisch loeschen!
    // Der Refresh-Modus (fpc_preloader.php --refresh) kuemmert sich
    // um die Erneuerung. Nur wirklich uralte Dateien (> 7 Tage)
    // werden automatisch geloescht.
    // ==========================================================
    if ($age > 604800) {
        // Aelter als 7 Tage -> definitiv loeschen
        @unlink($cache_file);
    }
    $fpc_miss_reason = 'EXPIRED_MAX_AGE';
    fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
    return false;
} elseif ($age > $FPC_CACHE_TTL) {
    // ==========================================================
    // v10.4.0: STALE-WHILE-REVALIDATE
    // Datei ist abgelaufen (> TTL) aber noch nicht uralt (< MAX_AGE).
    // -> Trotzdem ausliefern! Der Preloader --refresh erneuert sie
    //    im Hintergrund beim naechsten Lauf.
    // Vorteil: Besucher sieht IMMER eine schnelle gecachte Seite,
    //          auch wenn der Cache gerade erneuert wird.
    // ==========================================================
    $is_stale = true;
}

// 3. Health-Marker pruefen (schnell: nur letzte 200 Bytes lesen)
$fp = fopen($cache_file, 'r');
if ($fp === false) {
    return false;
}
$seek_pos = max(0, $filesize - 200);
fseek($fp, $seek_pos);
$tail = fread($fp, 200);
fclose($fp);

if (strpos($tail, $FPC_HEALTH_MARKER) === false) {
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
    $fpc_miss_reason = 'NO_HEALTH_MARKER';
    fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
    return false;
}

// ============================================================
// CACHE-DATEI AUSLIEFERN (validiert!)
// ============================================================

header('Content-Type: text/html; charset=utf-8');

if ($is_stale) {
    // v10.4.0: Stale-While-Revalidate - abgelaufene aber noch brauchbare Datei
    header('X-FPC-Cache: STALE');
    header('X-FPC-Stale: true');
    header('X-FPC-Stale-Age: ' . $age . 's');
    $fpc_cache_status = 'STALE';
} else {
    header('X-FPC-Cache: HIT');
    $fpc_cache_status = 'HIT';
}

header('X-FPC-Version: 10.4.0');
$fpc_miss_reason = '';
header('X-FPC-Cached-At: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-FPC-Age: ' . $age . 's');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ============================================================
// v8.2.0: JAVASCRIPT INJECTION
// ============================================================
// Injiziert zwei Scripts vor </body>:
// 1. Session-Initializer (v8.1.0) - startet PHP-Session im Hintergrund
// 2. AJAX-Warenkorb (v8.2.0) - fängt Formular-Submit ab, sendet per AJAX,
//    aktualisiert Mini-Warenkorb ohne Seitenreload

$html = file_get_contents($cache_file);

$fpc_inject_js = <<<'FPCJS'
<script data-fpc-inject="10.4.0">
(function(){
    'use strict';

    // ========================================================
    // 1. SESSION-INITIALIZER (v8.1.0)
    // ========================================================
    var sessionReady = (document.cookie.indexOf('MODsid=') !== -1);

    function initSession(callback) {
        if (sessionReady) { if (callback) callback(); return; }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/fpc_session_init.php?t=' + Date.now(), true);
        xhr.withCredentials = true;
        xhr.timeout = 10000;
        xhr.onload = function() {
            if (xhr.status === 200) {
                sessionReady = true;
                document.documentElement.setAttribute('data-fpc-session', 'ready');
            }
            if (callback) callback();
        };
        xhr.onerror = function() { if (callback) callback(); };
        xhr.ontimeout = function() { if (callback) callback(); };
        xhr.send();
    }

    // Session sofort im Hintergrund starten
    initSession();

    // ========================================================
    // 2. AJAX-WARENKORB (v8.2.0)
    // ========================================================

    function setCookie(name, value, path, domain) {
        var c = name + '=' + value + '; path=' + (path || '/');
        if (domain) c += '; domain=' + domain;
        c += '; secure; SameSite=Lax';
        document.cookie = c;
    }

    function showToast(message, type) {
        var existing = document.getElementById('fpc-toast');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.id = 'fpc-toast';
        toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:14px 24px;border-radius:8px;color:#fff;font-size:14px;font-weight:600;box-shadow:0 4px 20px rgba(0,0,0,0.3);transition:opacity 0.4s,transform 0.4s;opacity:0;transform:translateY(-10px);';
        toast.style.background = (type === 'success') ? '#28a745' : (type === 'error') ? '#dc3545' : '#ffc107';
        toast.textContent = message;
        document.body.appendChild(toast);

        requestAnimationFrame(function() {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-10px)';
            setTimeout(function() { toast.remove(); }, 400);
        }, 3000);
    }

    function animateButton(btn, originalHtml) {
        btn.disabled = true;
        btn.style.opacity = '0.7';
        btn.innerHTML = '<span class="fa fa-spinner fa-spin"></span>&nbsp;&nbsp;Wird hinzugef\u00fcgt...';

        return function(success) {
            btn.disabled = false;
            btn.style.opacity = '1';
            if (success) {
                btn.innerHTML = '<span class="fa fa-check"></span>&nbsp;&nbsp;Hinzugef\u00fcgt!';
                btn.classList.remove('btn-secondary');
                btn.classList.add('btn-success');
                setTimeout(function() {
                    btn.innerHTML = originalHtml;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-secondary');
                }, 2000);
            } else {
                btn.innerHTML = originalHtml;
            }
        };
    }

    function updateMiniCart(cartCount) {
        var dropdown = document.querySelector('.dropdown-menu.toggle_cart');
        if (dropdown) {
            if (cartCount > 0) {
                var header = dropdown.querySelector('.card-header');
                if (header) {
                    header.innerHTML = '<span class="font-weight-bold">' + cartCount + ' Artikel im Warenkorb</span>' +
                        '<div class="mt-2"><a href="/warenkorb" class="btn btn-success btn-sm btn-block">' +
                        '<span class="fa fa-shopping-cart mr-2"></span>Zum Warenkorb</a></div>';
                }
            }
        }

        var cartLink = document.getElementById('toggle_cart');
        if (cartLink) {
            var badge = cartLink.querySelector('.fpc-cart-badge');
            if (!badge && cartCount > 0) {
                badge = document.createElement('span');
                badge.className = 'fpc-cart-badge';
                badge.style.cssText = 'position:absolute;top:-2px;right:-2px;background:#dc3545;color:#fff;border-radius:50%;width:18px;height:18px;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;line-height:1;';
                cartLink.style.position = 'relative';
                cartLink.appendChild(badge);
            }
            if (badge) {
                badge.textContent = cartCount;
                badge.style.display = (cartCount > 0) ? 'flex' : 'none';
                badge.style.animation = 'none';
                badge.offsetHeight;
                badge.style.animation = 'fpc-pulse 0.4s ease';
            }
        }

        var cartText = cartLink ? cartLink.querySelector('.d-none.d-lg-block.small') : null;
        if (cartText && cartCount > 0) {
            cartText.textContent = 'Warenkorb (' + cartCount + ')';
        }
    }

    function triggerFSBUpdate() {
        if (typeof fsbFetch === 'function') {
            try { fsbFetch(); } catch(e) {}
        }
    }

    var style = document.createElement('style');
    style.textContent = '@keyframes fpc-pulse{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}';
    document.head.appendChild(style);

    function handleCartSubmit(e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        var action = form.getAttribute('action') || '';
        if (action.indexOf('add_product') === -1) return;

        var submitBtn = form.querySelector('.btn-cart[type="submit"]');
        if (!submitBtn) return;

        var activeElement = document.activeElement;
        if (activeElement && activeElement.name === 'wishlist') return;

        e.preventDefault();
        e.stopPropagation();

        var originalHtml = submitBtn.innerHTML;
        var restoreButton = animateButton(submitBtn, originalHtml);

        initSession(function() {
            var formData = new FormData(form);

            var postUrl = action;
            if (postUrl.indexOf('action=add_product') === -1) {
                postUrl += (postUrl.indexOf('?') === -1 ? '?' : '&') + 'action=add_product';
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', postUrl, true);
            xhr.withCredentials = true;
            xhr.timeout = 15000;

            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 400) {
                    setCookie('fpc_bypass', '1', '/', '.mr-hanf.de');

                    var fsbXhr = new XMLHttpRequest();
                    fsbXhr.open('GET', '/ajax.php?ext=get_free_shipping_bar&t=' + Date.now(), true);
                    fsbXhr.withCredentials = true;
                    fsbXhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    fsbXhr.onload = function() {
                        if (fsbXhr.status === 200) {
                            try {
                                var data = JSON.parse(fsbXhr.responseText);
                                var count = data.cart_count || 1;
                                updateMiniCart(count);
                                triggerFSBUpdate();
                            } catch(ex) {
                                updateMiniCart(1);
                            }
                        } else {
                            updateMiniCart(1);
                        }
                    };
                    fsbXhr.onerror = function() { updateMiniCart(1); };
                    fsbXhr.send();

                    restoreButton(true);
                    showToast('Artikel wurde in den Warenkorb gelegt!', 'success');

                } else {
                    restoreButton(false);
                    showToast('Fehler beim Hinzuf\u00fcgen zum Warenkorb', 'error');
                }
            };

            xhr.onerror = function() {
                restoreButton(false);
                showToast('Netzwerkfehler - bitte erneut versuchen', 'error');
            };

            xhr.ontimeout = function() {
                restoreButton(false);
                showToast('Zeitüberschreitung - bitte erneut versuchen', 'error');
            };

            xhr.send(formData);
        });
    }

    document.addEventListener('submit', handleCartSubmit, true);

    // ========================================================
    // 3. BESUCHERSTATISTIK-TRACKER (v8.3.0)
    // ========================================================
    var pageLoadTime = Date.now();

    var trkImg = new Image();
    trkImg.src = '/fpc_tracker.php?t=pv&p=' + encodeURIComponent(location.pathname) + '&r=' + encodeURIComponent(document.referrer) + '&_=' + Date.now();

    function sendLeaveEvent() {
        var duration = Math.round((Date.now() - pageLoadTime) / 1000);
        if (duration < 1 || duration > 3600) return;
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/fpc_tracker.php?t=leave&d=' + duration);
        } else {
            var img = new Image();
            img.src = '/fpc_tracker.php?t=leave&d=' + duration + '&_=' + Date.now();
        }
    }

    window.addEventListener('beforeunload', sendLeaveEvent);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') sendLeaveEvent();
    });

})();
</script>
FPCJS;

// Injiziere vor </body>
$html = str_replace('</body>', $fpc_inject_js . "\n</body>", $html);

// Request-Logging (v9.0.0)
fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);

// Ausgabe
echo $html;
exit;

// ============================================================
// v9.0.0: REQUEST-LOGGING FUNKTION
// ============================================================
function fpc_log_request($start, $status, $reason, $is_bot, $bot_name, $http_code) {
    global $FPC_REQUEST_LOG, $FPC_LOG_DIR;
    if (!$FPC_REQUEST_LOG) return;

    $ttfb = round((microtime(true) - $start) * 1000, 1);
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-';

    // Log-Verzeichnis erstellen
    if (!is_dir($FPC_LOG_DIR)) {
        @mkdir($FPC_LOG_DIR, 0755, true);
    }

    // Tages-Log-Datei
    $logfile = $FPC_LOG_DIR . '/requests_' . date('Y-m-d') . '.log';

    // Kompaktes JSON-Format pro Zeile
    $entry = json_encode(array(
        'ts' => time(),
        'url' => $uri,
        'status' => $status,
        'reason' => $reason,
        'ttfb' => $ttfb,
        'bot' => $is_bot,
        'bot_name' => $bot_name,
        'http_code' => $http_code,
        'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '-',
    ), JSON_UNESCAPED_SLASHES) . "\n";

    @file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);

    // Alte Logs aufraumen (aelter als 7 Tage)
    static $cleanup_done = false;
    if (!$cleanup_done && mt_rand(1, 100) === 1) {
        $cleanup_done = true;
        $files = glob($FPC_LOG_DIR . '/requests_*.log');
        if ($files) {
            $cutoff = time() - (7 * 86400);
            foreach ($files as $f) {
                if (filemtime($f) < $cutoff) @unlink($f);
            }
        }
    }
}
