# Mr. Hanf Full Page Cache (FPC) v8.2.0

**Cron-basiertes Full Page Cache System fuer modified eCommerce (xt:Commerce Fork)**

## Ueberblick

Das FPC-Modul generiert statische HTML-Dateien fuer alle Shop-Seiten und laesst Apache diese **direkt** ausliefern — ohne PHP-Worker. Das Ergebnis: Ladezeiten unter 100ms fuer Gastbesucher bei minimaler Serverbelastung.

## Architektur v8.2

```
Gast-Besucher  → Apache → fpc_serve.php → readfile(cache/fpc/{url}/index.html) → ~77ms
Eingeloggter   → Apache → index.php → modified eCommerce → dynamische Seite
```

## Dateien

| Datei | Zweck |
|-------|-------|
| `htaccess_fpc_rules.txt` | Apache RewriteRules — in .htaccess einfuegen |
| `fpc_preloader.php` | Cron-Job: Baut Cache auf (Sitemap + DB-Kategorien) |
| `fpc_serve.php` | Fallback: PHP-basierte Auslieferung (nur bei Bedarf) |
| `fpc_flush.php` | CLI: Cache leeren (komplett, einzeln, oder abgelaufen) |
| `fpc_session_init.php` | AJAX-Endpoint: Startet PHP-Session fuer FPC-Besucher (v8.1.0) |
| `admin_q9wKj6Ds/.../mrhanf_fpc.php` | Admin-Modul fuer Konfiguration + manueller Cache-Rebuild |
| `lang/{de,en,fr,es}/.../mrhanf_fpc.php` | Sprachdateien (4 Sprachen) |
| `admin_q9wKj6Ds/fpc_dashboard.php` | **FPC Schaltzentrale** - Dashboard mit Charts, Logs, Steuerung |
| `admin_q9wKj6Ds/fpc_dashboard_install.php` | Installations-Script fuer Menueeintrag |
| `admin_q9wKj6Ds/fpc_dashboard_menu_patch.txt` | Manueller Menueeintrag-Code fuer column_left.php |
| `lang/{de,en}/admin/fpc_dashboard.php` | Sprachdateien fuer die Schaltzentrale |
| `fpc_healthcheck.php` | Cron: Automatischer Health-Check mit HIT-Rate, TTFB, Redirect-Pruefung |
| `shoproot/.../add_product_before_redirect/95_fpc_bypass.php` | **KRITISCH**: Setzt fpc_bypass Cookie VOR dem Redirect nach Warenkorb-Add |
| `shoproot/.../buy_now_before_redirect/95_fpc_bypass.php` | **KRITISCH**: Setzt fpc_bypass Cookie VOR dem Redirect nach buy_now |
| `shoproot/.../application_top_end/95_fpc_bypass_cookie.php` | Sicherheitsnetz: Cookie-Management fuer eingeloggte User und nicht-leere Warenkoerbe |

## Installation

### 1. Dateien auf den Server kopieren

```bash
SHOP="/home/www/doc/28856/dcp288560004/mr-hanf.de/www"

# Kern-Dateien
cp fpc_serve.php fpc_preloader.php fpc_flush.php fpc_session_init.php fpc_healthcheck.php "$SHOP/"

# Admin-Modul
cp admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php \
   "$SHOP/admin_q9wKj6Ds/includes/modules/system/"

# FPC Schaltzentrale (Dashboard)
cp admin_q9wKj6Ds/fpc_dashboard.php "$SHOP/admin_q9wKj6Ds/"
cp admin_q9wKj6Ds/includes/extra/menu/fpc_dashboard.php \
   "$SHOP/admin_q9wKj6Ds/includes/extra/menu/"
cp admin_q9wKj6Ds/includes/extra/filenames/fpc_dashboard.php \
   "$SHOP/admin_q9wKj6Ds/includes/extra/filenames/"

# Sprachdateien
for LANG in german english french spanish; do
  mkdir -p "$SHOP/lang/$LANG/modules/system"
  cp "lang/$LANG/modules/system/mrhanf_fpc.php" \
     "$SHOP/lang/$LANG/modules/system/" 2>/dev/null
done
for LANG in german english; do
  mkdir -p "$SHOP/lang/$LANG/admin"
  cp "lang/$LANG/admin/fpc_dashboard.php" \
     "$SHOP/lang/$LANG/admin/" 2>/dev/null
done

# v8.2.0 KRITISCH: Bypass-Cookie Hooks (Warenkorb-Fix)
mkdir -p "$SHOP/includes/extra/cart_actions/add_product_before_redirect"
mkdir -p "$SHOP/includes/extra/cart_actions/buy_now_before_redirect"
cp shoproot/includes/extra/cart_actions/add_product_before_redirect/95_fpc_bypass.php \
   "$SHOP/includes/extra/cart_actions/add_product_before_redirect/"
cp shoproot/includes/extra/cart_actions/buy_now_before_redirect/95_fpc_bypass.php \
   "$SHOP/includes/extra/cart_actions/buy_now_before_redirect/"
cp shoproot/includes/extra/application_top/application_top_end/95_fpc_bypass_cookie.php \
   "$SHOP/includes/extra/application_top/application_top_end/"

mkdir -p "$SHOP/cache/fpc"
```

### 2. .htaccess aktualisieren

Den Inhalt von `htaccess_fpc_rules.txt` am **Anfang** der `.htaccess` einfuegen, **vor** allen anderen RewriteRules.

**WICHTIG v8.0.3:** Zusaetzlich muessen zwei Anpassungen in der bestehenden .htaccess gemacht werden:

**a) cache/fpc/.htaccess erstellen** (erlaubt Apache den Zugriff auf HTML-Dateien):
```apache
<FilesMatch "\.html$">
  <IfModule mod_authz_core.c>
    Require all granted
  </IfModule>
  <IfModule !mod_authz_core.c>
    Order Allow,Deny
    Allow from all
  </IfModule>
</FilesMatch>
```

**b) CLEAN SEO URL Block:** Vor der `index.html`-Redirect-Regel einfuegen:
```apache
  RewriteCond %{REQUEST_URI} !^/cache/fpc/ [NC]
  RewriteRule ^(.+)/index\.(htm|html|php)$ /$1/ [r=301,L]
```

### 3. Admin-Modul aktivieren

Im Shop-Admin unter **Module → System-Module** das Modul "Mr. Hanf Full Page Cache" installieren.

### 4. Cron-Job einrichten

```bash
# Preloader (alle 2 Stunden) - v8.0.5: mkdir -p als Absicherung
0 */2 * * * cd /pfad/zum/shop && mkdir -p cache/fpc && /usr/local/bin/php fpc_preloader.php >> cache/fpc/preloader.log 2>&1

# Cache-Bereinigung (taeglich 3 Uhr) - v8.0.5: mkdir -p als Absicherung
0 3 * * * cd /pfad/zum/shop && mkdir -p cache/fpc && /usr/local/bin/php fpc_flush.php --expired >> cache/fpc/flush.log 2>&1
```

## Konfiguration (Admin)

| Einstellung | Standard | Beschreibung |
|-------------|----------|--------------|
| Status | True | Modul aktivieren/deaktivieren |
| Cache-Lebensdauer | 86400 | TTL in Sekunden (24 Stunden) |
| Ausgeschlossene Seiten | checkout,login,... | Kommagetrennte URL-Teile |
| Max. Seiten pro Cron | 500 | Limit pro Preloader-Durchlauf |

## Cache leeren

```bash
php fpc_flush.php              # Komplett
php fpc_flush.php --url /pfad/ # Einzelne Seite
php fpc_flush.php --expired    # Nur abgelaufene
```

## Ausgeschlossene Seiten

| Seite | Grund |
|-------|-------|
| `/checkout` | Bestellprozess (alte URL) |
| `/kasse` | Kasse/Checkout (SEO-URL) |
| `/login` | Login-Seite |
| `/account` | Kundenkonto |
| `/shopping_cart` | Warenkorb (alte URL) |
| `/warenkorb` | Warenkorb (SEO-URL) |
| `/vergleich` | Produktvergleich (Session) |
| `/wishlist` | Merkzettel |
| `/logoff` | Abmelden |
| `/admin` | Admin-Bereich |

## Sicherheitsfeatures

### HTML-Validierung (7 Schichten)

| Schicht | Pruefung | Beschreibung |
|---------|----------|--------------|
| 1 | Mindestgroesse | Min. 1000 Bytes |
| 2 | DOCTYPE/HTML | `<!DOCTYPE` oder `<html>` am Anfang |
| 3 | BODY-Tag | `<body>` muss vorhanden sein |
| 4 | Closing-Tag | `</html>` oder `</body>` am Ende |
| 5 | PHP-Fehler | Kein `Fatal error`, `Warning` etc. |
| 6 | Leere-Seiten | strip_tags() muss > 100 Zeichen ergeben |
| 7 | Verify-After-Write | Cache-Datei wird nach Schreiben zurueckgelesen |

## Changelog

### v8.2.0 (2026-03-27)
- **KRITISCHER FIX**: Warenkorb-Bypass-Cookie wird jetzt VOR dem Redirect gesetzt!
  - **Root Cause**: Nach dem Warenkorb-Add macht modified einen 302-Redirect.
    Das Bypass-Cookie in `application_top_end` wurde NACH dem Redirect gesetzt,
    daher lieferte der FPC die gecachte Seite (mit leerem Warenkorb) aus.
  - **Loesung**: Neue Hooks `add_product_before_redirect` und `buy_now_before_redirect`
    setzen das `fpc_bypass=1` Cookie exakt zwischen Warenkorb-Add und Redirect.
  - **Neue Dateien**:
    - `includes/extra/cart_actions/add_product_before_redirect/95_fpc_bypass.php`
    - `includes/extra/cart_actions/buy_now_before_redirect/95_fpc_bypass.php`
- **NEU**: AJAX-Warenkorb fuer gecachte Seiten
  - Formular-Submit wird per JavaScript abgefangen und per AJAX gesendet
  - Mini-Warenkorb wird ohne Seitenreload aktualisiert (Badge + Dropdown)
  - `fpc_bypass` Cookie wird per JavaScript gesetzt
  - Free Shipping Bar wird automatisch getriggert
  - Button-Animation: Spinner → Haekchen → Original
  - Toast-Benachrichtigung bei Erfolg/Fehler
  - Session-Initializer integriert (wartet auf Session bevor POST)
  - Kein Seitenreload mehr noetig!
- **FIX**: FPC Schaltzentrale Dashboard-Buttons repariert
  - Chart.js wird jetzt mit `defer` geladen (blockiert nicht mehr die Seite)
  - IIFE durch `DOMContentLoaded` Event ersetzt (robusterer Timing)
  - Chart-Rendering mit `typeof Chart` Check abgesichert
  - Error-Boundary um Init-Code
  - Version auf v8.2.0 aktualisiert

### v8.1.0 (2026-03-27)
- **KRITISCHER FIX**: Warenkorb funktioniert jetzt beim ersten Klick!
  - **Problem**: Gecachte Seiten wurden ohne PHP-Session ausgeliefert.
    Beim ersten Klick auf "In den Warenkorb" startete modified eine neue Session,
    verarbeitete den Warenkorb-Eintrag aber nicht korrekt (Session noch nicht initialisiert).
    Erst beim zweiten Klick (mit bestehender Session) funktionierte der Warenkorb.
  - **Loesung**: `fpc_serve.php` injiziert ein kleines JavaScript-Snippet in gecachte Seiten.
    Das Snippet ruft `/fpc_session_init.php` per AJAX auf und startet die PHP-Session
    im Hintergrund, bevor der Besucher den Warenkorb-Button klickt.
  - **Overhead**: ~2ms zusaetzlich in fpc_serve.php (file_get_contents statt readfile)
  - **Neue Datei**: `fpc_session_init.php` — AJAX-Endpoint fuer Session-Initialisierung
- **GEAENDERT**: `fpc_serve.php` verwendet jetzt file_get_contents + str_replace statt readfile
  um das Session-Init-Script vor </body> zu injizieren

### v8.0.9 (2026-03-27) + FPC Schaltzentrale v1.0
- **NEU**: FPC Schaltzentrale als eigenstaendige Admin-Seite
  - Tab 1: **Dashboard** - KPI-Kacheln, Cache-Verteilung Chart, Preloader-Statistik
  - Tab 2: **Steuerung** - Cache leeren, neu aufbauen, einzelne URLs cachen, eigene URLs verwalten
  - Tab 3: **URLs** - Alle gecachten URLs durchsuchen, filtern, einzeln loeschen/neu cachen
  - Tab 4: **Logs** - Preloader-Log und Rebuild-Log mit Syntax-Highlighting und Auto-Refresh
  - Tab 5: **Monitoring** - Automatische Cache-Tests, HIT-Rate/TTFB Charts, Test-Historie
  - Menueeintrag unter Statistiken
  - Installations-Script fuer Menueeintrag
  - Dark-Theme UI mit Chart.js Visualisierungen
  - AJAX-basiert: Alle Aktionen ohne Seitenreload
- **KRITISCHER FIX**: Redirect-Loop bei Warenkorb-Aktionen behoben!
  - **Bug 1**: `fpc_bypass` Cookie wurde mit leerer Domain gesetzt.
    Browser sendete das Cookie bei Redirects nicht zuverlaessig mit.
    Fix: Domain auf `.mr-hanf.de`, SameSite=Lax, Secure=true.
  - **Bug 2**: Preloader cachte Seiten unter der Original-URL statt der
    finalen URL nach Redirects. Wenn der Shop z.B. von `/autoflowering-seeds/`
    nach `/autoflowering-samen/` redirected, wurde die Seite unter der alten
    URL gespeichert. Besucher bekamen gecachte Seite mit Links zur neuen URL,
    was bei Interaktionen (Warenkorb) zu Redirect-Loops fuehrte.
    Fix: Preloader erkennt jetzt Redirects und cached unter finaler URL.
  - **Bug 3**: Cookie hatte kein SameSite-Attribut (inkonsistent mit MODsid).
    Fix: SameSite=Lax explizit gesetzt.
- **GEAENDERT**: `95_fpc_bypass_cookie.php` verwendet jetzt PHP 7.3+ Options-Array
- **GEAENDERT**: Preloader loggt Redirect-Erkennung

### v8.0.8 (2026-03-27)
- **NEU**: "Cache neu aufbauen" Button im Admin-Modul
  - Startet `fpc_preloader.php` als Hintergrund-Prozess (nohup)
  - Admin-Browser wird nicht blockiert, Seite kann geschlossen werden
  - PID-Tracking: Status-Anzeige ob Rebuild gerade laeuft
  - Stop-Button zum Abbrechen eines laufenden Rebuilds

### v8.0.7 (2026-03-25)
- **NEU**: `fpc_bypass` Cookie-System fuer Warenkorb und Login
  - Autoinclude `95_fpc_bypass_cookie.php` setzt `fpc_bypass=1` wenn Warenkorb gefuellt oder Benutzer eingeloggt
  - `.htaccess` und `fpc_serve.php` pruefen dieses Cookie
  - Gastbesucher ohne Warenkorb bekommen weiterhin den FPC-Cache
  - Cookie wird automatisch geloescht wenn Warenkorb leer und Benutzer ausgeloggt
- **NEU**: Datei `shoproot/includes/extra/application_top/application_top_end/95_fpc_bypass_cookie.php`

### v8.0.6 (2026-03-25)
- **FIX**: Cookie-Check (MODsid/PHPSESSID) aus .htaccess UND fpc_serve.php entfernt!
  modified-Shop setzt bei JEDEM Besucher (auch Gaeste) sofort ein MODsid-Cookie.
  Dadurch wurde der FPC fuer ALLE Besucher blockiert.
  Schutz erfolgt jetzt ueber URL-basierte Ausschlussliste in fpc_serve.php.
- **FIX**: `%{DOCUMENT_ROOT}` durch absoluten Pfad ersetzt.
  Artfiles liefert Symlink-Pfad statt realen Pfad, `-f` Check schlug fehl.
- **FIX**: `[END]` durch `[L]` ersetzt (bessere Kompatibilitaet mit Artfiles Apache)
- **FIX**: `RewriteRule ^$` durch `^/?$` ersetzt (robuster fuer Startseite)
- **WICHTIG**: .htaccess muss komplett aktualisiert werden (siehe htaccess_fpc_rules.txt)

### v8.0.5 (2026-03-25)
- **FIX**: Verzeichnis-Schutz: `cache/fpc/` wird automatisch neu erstellt wenn es fehlt
- **FIX**: Admin "Cache leeren" loescht nur Inhalt, nicht das Verzeichnis selbst
- **FIX**: `fpc_flush.php` erstellt `cache/fpc/` + `.gitkeep` nach Flush automatisch
- **FIX**: Cronjob-Empfehlung: `mkdir -p cache/fpc` vor PHP-Aufruf
- **HINWEIS**: Cronjob muss aktualisiert werden (siehe Installation)

### v8.0.3 (2026-03-23)
- **FIX**: Cache-Control Header-Konflikt behoben (`Header always set` statt `Header set`)
- **FIX**: Preloader cached jetzt auch Kategorie-Seiten (aus DB priorisiert)
- **FIX**: Startseite + statische Seiten werden immer zuerst gecacht
- **FIX**: `cache/.htaccess` blockierte HTML-Zugriff (Require all denied)
- **FIX**: CLEAN SEO URL `index.html`-Redirect verursachte Loop mit FPC
- **AUFGERAEUMT**: Debug-Dateien und Backups entfernt

### v8.0.2 (2026-03-23)
- **FIX**: FPC-Header per env-Variable statt FilesMatch

### v8.0.1 (2026-03-23)
- **NEU**: /kasse und /warenkorb zur Ausschlussliste

### v8.0.0 (2026-03-22)
- **NEU**: Direkte Apache-Auslieferung (kein PHP-Worker)
- **NEU**: Erweiterte HTML-Validierung (7 Schichten)
- **NEU**: Verify-After-Write und Fehlerquoten-Schutz

### v7.1.0
- Rate-Limiting, Server-Load-Schutz, adaptive Drosselung

### v7.0.3
- Fix: 304 Not Modified, RewriteCond-Wiederholung, PHP-Fehler-Regex

### v7.0.0
- Erste ausfallsichere Version mit 5-facher Validierung

## Lizenz

Proprietaer — Mr. Hanf / mrhanf15-stack
