<?php
/**
 * Mr. Hanf Full Page Cache v9.0.0 - Cache-Handler
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
 * CHANGELOG v9.0.0:
 *   - NEU: Request-Logging fuer Live Inspector und SEO-Bot-Tracking
 *     Jeder Request wird mit Status (HIT/MISS/BYPASS), Grund, TTFB,
 *     Bot-Erkennung in Tages-Log-Dateien geschrieben.
 *   - NEU: X-FPC-Reason Header bei MISS/BYPASS
 *   - NEU: Bot-Erkennung (Googlebot, Bing, Ahrefs, Semrush, GPTBot, etc.)
 *   - NEU: Automatische Log-Rotation (7 Tage)
 *   - v8.3.0: Besucherstatistik-Tracker Pixel-Injection
 *   - v8.2.0: AJAX-Warenkorb fuer gecachte Seiten
 *   - v8.1.0: Session-Initializer JavaScript Injection
 *
 * @version   10.0.0
 * @date      2026-03-27
 */

// ============================================================
// KONFIGURATION
// ============================================================
$FPC_MIN_FILESIZE  = 500;      // Mindestgroesse in Bytes
$FPC_MAX_AGE       = 172800;   // Max. Alter in Sekunden (48h Fallback)
$FPC_HEALTH_MARKER = '<!-- FPC-VALID -->';  // Pflicht-Marker im HTML
$FPC_AUTO_DELETE   = true;     // Korrupte Dateien automatisch loeschen
$FPC_REQUEST_LOG   = true;     // Request-Logging fuer Inspector/SEO (v9.0.0)
$FPC_LOG_DIR       = __DIR__ . '/cache/fpc/logs';  // Log-Verzeichnis

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
// Das Autoinclude 95_fpc_bypass_cookie.php setzt "fpc_bypass=1" wenn:
//   - Der Warenkorb Artikel enthaelt
//   - Der Benutzer eingeloggt ist
// HINWEIS: MODsid wird NICHT geprueft (modified setzt es bei jedem Gast).
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
// Prueft ob fuer die aktuelle URI ein Redirect definiert ist.
// Wenn ja: HTTP-Redirect senden und Request beenden.
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

// 2. TTL-Check: Abgelaufene Dateien nicht ausliefern
$mtime = filemtime($cache_file);
$age = time() - $mtime;
if ($age > $FPC_MAX_AGE) {
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
    $fpc_miss_reason = 'EXPIRED';
    fpc_log_request($fpc_request_start, $fpc_cache_status, $fpc_miss_reason, $fpc_is_bot, $fpc_bot_name, $fpc_http_code);
    return false;
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
header('X-FPC-Cache: HIT');
header('X-FPC-Version: 10.0.0');
$fpc_cache_status = 'HIT';
$fpc_miss_reason = '';
header('X-FPC-Cached-At: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
if ($fpc_miss_reason) header('X-FPC-Reason: ' . $fpc_miss_reason);
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
<script data-fpc-inject="9.0.0">
(function(){
    'use strict';

    // ========================================================
    // 1. SESSION-INITIALIZER (v8.1.0)
    // ========================================================
    // Startet eine PHP-Session im Hintergrund wenn noch keine existiert.
    // Notwendig damit der erste Warenkorb-POST korrekt verarbeitet wird.

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
    // Fängt alle Warenkorb-Formulare ab und sendet den POST per AJAX.
    // Nach Erfolg: Mini-Warenkorb aktualisieren, fpc_bypass Cookie setzen,
    // Free Shipping Bar triggern - OHNE Seitenreload.

    // --- Hilfsfunktionen ---

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

        // Einblenden
        requestAnimationFrame(function() {
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
        });

        // Ausblenden nach 3s
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
        // 1. Mini-Warenkorb Dropdown aktualisieren
        var dropdown = document.querySelector('.dropdown-menu.toggle_cart');
        if (dropdown) {
            if (cartCount > 0) {
                // Lade den Mini-Warenkorb-Inhalt per Seitenreload des Dropdowns
                // Da wir keinen dedizierten AJAX-Endpoint haben, aktualisieren wir
                // den Text und fuegen einen Link zum Warenkorb hinzu
                var header = dropdown.querySelector('.card-header');
                if (header) {
                    header.innerHTML = '<span class="font-weight-bold">' + cartCount + ' Artikel im Warenkorb</span>' +
                        '<div class="mt-2"><a href="/warenkorb" class="btn btn-success btn-sm btn-block">' +
                        '<span class="fa fa-shopping-cart mr-2"></span>Zum Warenkorb</a></div>';
                }
            }
        }

        // 2. Badge am Warenkorb-Icon hinzufuegen/aktualisieren
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
                // Puls-Animation
                badge.style.animation = 'none';
                badge.offsetHeight; // Reflow
                badge.style.animation = 'fpc-pulse 0.4s ease';
            }
        }

        // 3. Warenkorb-Text aktualisieren
        var cartText = cartLink ? cartLink.querySelector('.d-none.d-lg-block.small') : null;
        if (cartText && cartCount > 0) {
            cartText.textContent = 'Warenkorb (' + cartCount + ')';
        }
    }

    function triggerFSBUpdate() {
        // Free Shipping Bar aktualisieren (wenn vorhanden)
        if (typeof fsbFetch === 'function') {
            try { fsbFetch(); } catch(e) {}
        }
    }

    // --- CSS fuer Badge-Animation injizieren ---
    var style = document.createElement('style');
    style.textContent = '@keyframes fpc-pulse{0%{transform:scale(1)}50%{transform:scale(1.3)}100%{transform:scale(1)}}';
    document.head.appendChild(style);

    // --- Hauptlogik: Formulare abfangen ---

    function handleCartSubmit(e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;

        // Nur Formulare mit add_product abfangen
        var action = form.getAttribute('action') || '';
        if (action.indexOf('add_product') === -1) return;

        // Pruefen ob der Submit durch den Warenkorb-Button ausgeloest wurde
        // (nicht durch Wishlist oder Vergleich)
        var submitBtn = form.querySelector('.btn-cart[type="submit"]');
        if (!submitBtn) return;

        // Wishlist-Button hat name="wishlist" - diesen NICHT abfangen
        var activeElement = document.activeElement;
        if (activeElement && activeElement.name === 'wishlist') return;

        // Event abfangen
        e.preventDefault();
        e.stopPropagation();

        var originalHtml = submitBtn.innerHTML;
        var restoreButton = animateButton(submitBtn, originalHtml);

        // Sicherstellen dass Session existiert, dann POST senden
        initSession(function() {
            var formData = new FormData(form);

            // Sicherstellen dass action=add_product im Query-String ist
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
                    // Erfolg! (200 oder 302 - XMLHttpRequest folgt Redirects automatisch)

                    // fpc_bypass Cookie setzen damit naechste Seitenaufrufe dynamisch sind
                    setCookie('fpc_bypass', '1', '/', '.mr-hanf.de');

                    // Mini-Warenkorb per FSB-Endpoint aktualisieren
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

    // Event-Delegation auf document-Ebene (fängt alle Formulare ab)
    document.addEventListener('submit', handleCartSubmit, true);

    // ========================================================
    // 3. BESUCHERSTATISTIK-TRACKER (v8.3.0)
    // ========================================================
    // Leichtgewichtiges 1x1 Pixel-Tracking fuer Seitenaufrufe und Verweildauer.
    // DSGVO-konform: Kein IP-Tracking, anonymisierter Cookie-Hash.

    var pageLoadTime = Date.now();

    // Pageview tracken (1x1 Pixel)
    var trkImg = new Image();
    trkImg.src = '/fpc_tracker.php?t=pv&p=' + encodeURIComponent(location.pathname) + '&r=' + encodeURIComponent(document.referrer) + '&_=' + Date.now();

    // Verweildauer beim Verlassen senden
    function sendLeaveEvent() {
        var duration = Math.round((Date.now() - pageLoadTime) / 1000);
        if (duration < 1 || duration > 3600) return;
        // navigator.sendBeacon fuer zuverlaessiges Senden beim Schliessen
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/fpc_tracker.php?t=leave&d=' + duration);
        } else {
            var img = new Image();
            img.src = '/fpc_tracker.php?t=leave&d=' + duration + '&_=' + Date.now();
        }
    }

    // beforeunload + visibilitychange fuer maximale Abdeckung
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
