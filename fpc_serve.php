<?php
/**
 * Mr. Hanf Full Page Cache v8.3.0 - Cache-Handler
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
 * CHANGELOG v8.3.0:
 *   - NEU: Besucherstatistik-Tracker (fpc_tracker.php) Pixel-Injection
 *     Leichtgewichtiges 1x1 Pixel-Tracking fuer Seitenaufrufe, Verweildauer,
 *     Absprungrate, Geraetetyp und Traffic-Quellen. DSGVO-konform.
 *   - v8.2.0: AJAX-Warenkorb fuer gecachte Seiten
 *   - v8.1.0: Session-Initializer JavaScript Injection
 *   - v8.0.9: FIX: Redirect-Loop bei Warenkorb-Aktionen behoben
 *
 * @version   8.3.0
 * @date      2026-03-27
 */

// ============================================================
// KONFIGURATION
// ============================================================
$FPC_MIN_FILESIZE  = 500;      // Mindestgroesse in Bytes
$FPC_MAX_AGE       = 172800;   // Max. Alter in Sekunden (48h Fallback)
$FPC_HEALTH_MARKER = '<!-- FPC-VALID -->';  // Pflicht-Marker im HTML
$FPC_AUTO_DELETE   = true;     // Korrupte Dateien automatisch loeschen

// ============================================================
// SICHERHEITSCHECKS
// ============================================================

// Nur GET-Requests cachen
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    return false;
}

// v8.0.7: Bypass-Cookie pruefen
// Das Autoinclude 95_fpc_bypass_cookie.php setzt "fpc_bypass=1" wenn:
//   - Der Warenkorb Artikel enthaelt
//   - Der Benutzer eingeloggt ist
// HINWEIS: MODsid wird NICHT geprueft (modified setzt es bei jedem Gast).
if (isset($_COOKIE['fpc_bypass']) && $_COOKIE['fpc_bypass'] === '1') {
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
    return false;
}

// Realpath-Check (verhindert Directory Traversal)
$real_file = realpath($cache_file);
if ($real_file === false || strpos($real_file, $real_cache) !== 0) {
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
    return false;
}

// 2. TTL-Check: Abgelaufene Dateien nicht ausliefern
$mtime = filemtime($cache_file);
$age = time() - $mtime;
if ($age > $FPC_MAX_AGE) {
    if ($FPC_AUTO_DELETE) {
        @unlink($cache_file);
    }
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
    return false;
}

// ============================================================
// CACHE-DATEI AUSLIEFERN (validiert!)
// ============================================================

header('Content-Type: text/html; charset=utf-8');
header('X-FPC-Cache: HIT');
header('X-FPC-Version: 8.3.0');
header('X-FPC-Cached-At: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
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
<script data-fpc-inject="8.3.0">
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

// Ausgabe
echo $html;
exit;
