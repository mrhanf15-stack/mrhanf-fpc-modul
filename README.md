# Mr. Hanf Full Page Cache (FPC) v10.4.0

**Cron-basiertes Full Page Cache System fuer modified eCommerce (xt:Commerce Fork)**

## Ueberblick

Das FPC-Modul generiert statische HTML-Dateien fuer alle Shop-Seiten und laesst Apache diese **direkt** ausliefern — ohne PHP-Worker. Das Ergebnis: Ladezeiten unter 100ms fuer Gastbesucher bei minimaler Serverbelastung.

Mit Version 10.4.0 wurde die **Smart Refresh Strategie** eingefuehrt. Statt den Cache jede Nacht komplett zu loeschen und neu aufzubauen (was zu massiver Serverlast und kalten Caches am Morgen fuehrte), werden abgelaufene Seiten nun intelligent im Hintergrund aktualisiert, waehrend Besucher dank **Stale-While-Revalidate** niemals eine langsame Seite sehen.

## Architektur v10.4.0

```
Gast-Besucher  → Apache → fpc_serve.php → readfile(cache/fpc/{url}/index.html) → ~77ms
Eingeloggter   → Apache → index.php → modified eCommerce → dynamische Seite
```

## Dateien

| Datei | Zweck |
|-------|-------|
| `htaccess_fpc_rules.txt` | Apache RewriteRules — in .htaccess einfuegen |
| `fpc_preloader.php` | Cron-Job: Baut Cache auf (NEU: `--refresh` Modus) |
| `fpc_serve.php` | Fallback: PHP-Auslieferung (NEU: Stale-While-Revalidate) |
| `fpc_flush.php` | CLI: Cache leeren (NEU: `--stale` und `--stats` Modus) |
| `fpc_session_init.php` | AJAX-Endpoint: Startet PHP-Session fuer FPC-Besucher |
| `fpc_tracker.php` | Leichtgewichtiger Besucherstatistik-Tracker (DSGVO-konform) |
| `admin_q9wKj6Ds/.../mrhanf_fpc.php` | Admin-Modul fuer Konfiguration + manueller Cache-Rebuild |
| `lang/{de,en,fr,es}/.../mrhanf_fpc.php` | Sprachdateien (4 Sprachen) |
| `admin_q9wKj6Ds/fpc_dashboard.php` | **FPC Schaltzentrale** - 8 Tabs: Dashboard, Steuerung, URLs, Logs, Monitoring, Health-Check, Statistik, Fehler-Log |
| `admin_q9wKj6Ds/fpc_dashboard_install.php` | Installations-Script fuer Menueeintrag |
| `admin_q9wKj6Ds/fpc_dashboard_menu_patch.txt` | Manueller Menueeintrag-Code fuer column_left.php |
| `lang/{de,en}/admin/fpc_dashboard.php` | Sprachdateien fuer die Schaltzentrale |
| `fpc_healthcheck.php` | Cron: Automatischer Health-Check mit HIT-Rate, TTFB, Redirect-Pruefung |
| `fpc_seo.php` | SEO-Modul fuer 404-Log, Redirects und System-URL Filterung |
| `fpc_ai.php` | AI-Analyse-Modul mit OpenAI-Integration |

## Installation

### 1. Dateien auf den Server kopieren

```bash
SHOP="/home/www/doc/28856/dcp288560004/mr-hanf.de/www"

# Kern-Dateien
cp fpc_serve.php fpc_preloader.php fpc_flush.php fpc_session_init.php fpc_healthcheck.php fpc_tracker.php fpc_seo.php fpc_ai.php "$SHOP/"

# API und Config-Verzeichnis erstellen (v10.3.0+)
mkdir -p "$SHOP/api/fpc"
mkdir -p "$SHOP/cache/fpc/tracker"

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

# Bypass-Cookie Hooks (Warenkorb-Fix)
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

**WICHTIG:** Zusaetzlich muessen zwei Anpassungen in der bestehenden .htaccess gemacht werden:

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

### 4. Cron-Job einrichten (NEU v10.4.0)

Ab v10.4.0 wird empfohlen, den vollen Flush (`fpc_flush.php` ohne Argumente) komplett zu vermeiden. Stattdessen wird die **Smart Refresh Strategie** genutzt:

```bash
# 1. Smart Refresh (alle 2 Stunden) - Erneuert NUR abgelaufene Seiten
0 */2 * * * cd /pfad/zum/shop && php fpc_preloader.php --refresh >> cache/fpc/preloader.log 2>&1

# 2. Lückenfüller (täglich 2:00 Uhr) - Findet neue URLs aus der Sitemap
0 2 * * * cd /pfad/zum/shop && php fpc_preloader.php >> cache/fpc/preloader.log 2>&1

# 3. Uralt-Cleanup (täglich 3:00 Uhr) - Löscht nur Dateien die älter als 2x TTL sind
0 3 * * * cd /pfad/zum/shop && php fpc_flush.php --stale >> cache/fpc/flush.log 2>&1
```

## Konfiguration (Admin)

| Einstellung | Standard | Beschreibung |
|-------------|----------|--------------|
| Status | True | Modul aktivieren/deaktivieren |
| Cache-Lebensdauer | 86400 | TTL in Sekunden (24 Stunden) |
| Ausgeschlossene Seiten | checkout,login,... | Kommagetrennte URL-Teile |
| Max. Seiten pro Cron | 2000 | Limit pro Preloader-Durchlauf |

## Cache leeren (v10.4.0)

Ein vollstaendiger Flush loescht alle 30.000+ Seiten und zwingt den Server zu einem massiven Rebuild. Dies sollte vermieden werden.

```bash
php fpc_flush.php --expired         # EMPFOHLEN: Nur abgelaufene Dateien (> TTL)
php fpc_flush.php --stale           # Nur uralte Dateien (> 2x TTL)
php fpc_flush.php --url /pfad/      # Einzelne Seite
php fpc_flush.php --pattern /samen* # Seiten nach Muster
php fpc_flush.php --stats           # Zeigt Cache-Statistik an
php fpc_flush.php --force           # WARNUNG: Komplett leeren
```

## Changelog

### v10.4.0 (2026-03-29) - Smart Refresh & Stale-While-Revalidate
- **NEU**: `fpc_preloader.php --refresh` Modus
  - Scannt den `cache/fpc/` Ordner statt der Sitemap (viel schneller)
  - Erneuert gezielt nur abgelaufene Dateien (> TTL)
  - Priorisiert automatisch die aeltesten Dateien
- **NEU**: Stale-While-Revalidate in `fpc_serve.php`
  - Abgelaufene Cache-Dateien werden bis zu 48h (MAX_AGE) weiterhin ausgeliefert
  - Setzt Header `X-FPC-Stale: true` fuer Analyse-Zwecke
  - Verhindert langsame Ladezeiten waehrend der Cache im Hintergrund erneuert wird
- **NEU**: Verbesserter `fpc_flush.php`
  - Voller Flush erfordert jetzt `--force` Flag (Sicherheitswarnung)
  - Neuer `--stale` Modus loescht nur Dateien die aelter als 2x TTL sind
  - Neuer `--pattern` Modus zum gezielten Loeschen (z.B. `/autoflowering-*`)
  - Neuer `--stats` Modus zeigt Altersverteilung des Caches an
- **VERBESSERT**: Config-Dateien sind sicher in `api/fpc/` geschuetzt und werden vom Flush ignoriert.

### v10.3.1 (2026-03-29) - AI & SEO Fixes
- **FIX**: URL Scanner nutzt Chrome User-Agent zur Vermeidung von 403 Fehlern
- **FIX**: AI Analyse Output-Buffering und Fehlerbehandlung repariert
- **FIX**: System-URLs (fpc_serve.php, index.php) werden in der SEO-Analyse gefiltert
- **FIX**: 404-Log URLs sind klickbar und koennen im Dashboard getestet werden

### v10.3.0 (2026-03-28)
- **NEU**: Config-Dateien (api_credentials.json, fpc_settings.json, ai_system_prompt.txt) in geschuetzten Ordner `api/fpc/` migriert
- **NEU**: AI System Prompt kann direkt im Dashboard bearbeitet werden

### v9.0.0 (2026-03-27)
- **NEU**: Besucherstatistik-Tracker (`fpc_tracker.php`)
- **NEU**: FPC Schaltzentrale v9.0.0 - jetzt mit 8 Tabs!
- **KRITISCHER FIX**: Warenkorb-Bypass-Cookie wird jetzt VOR dem Redirect gesetzt!
- **NEU**: AJAX-Warenkorb fuer gecachte Seiten

## Lizenz

Proprietaer — Mr. Hanf / mrhanf15-stack
