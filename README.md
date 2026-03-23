# Mr. Hanf Full Page Cache (FPC) v8.0.3

**Cron-basiertes Full Page Cache System fuer modified eCommerce (xt:Commerce Fork)**

## Ueberblick

Das FPC-Modul generiert statische HTML-Dateien fuer alle Shop-Seiten und laesst Apache diese **direkt** ausliefern — ohne PHP-Worker. Das Ergebnis: Ladezeiten unter 100ms fuer Gastbesucher bei minimaler Serverbelastung.

## Architektur v8.0

```
Gast-Besucher  → Apache → cache/fpc/{url}/index.html → HTML direkt (kein PHP!)
Eingeloggter   → Apache → index.php → modified eCommerce → dynamische Seite
```

## Dateien

| Datei | Zweck |
|-------|-------|
| `htaccess_fpc_rules.txt` | Apache RewriteRules — in .htaccess einfuegen |
| `fpc_preloader.php` | Cron-Job: Baut Cache auf (Sitemap + DB-Kategorien) |
| `fpc_serve.php` | Fallback: PHP-basierte Auslieferung (nur bei Bedarf) |
| `fpc_flush.php` | CLI: Cache leeren (komplett, einzeln, oder abgelaufen) |
| `admin_q9wKj6Ds/.../mrhanf_fpc.php` | Admin-Modul fuer Konfiguration |
| `lang/{de,en,fr,es}/.../mrhanf_fpc.php` | Sprachdateien (4 Sprachen) |

## Installation

### 1. Dateien auf den Server kopieren

```bash
SHOP="/home/www/doc/28856/dcp288560004/mr-hanf.de/www"
cp fpc_serve.php fpc_preloader.php fpc_flush.php "$SHOP/"
cp admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php \
   "$SHOP/admin_q9wKj6Ds/includes/modules/system/"
for LANG in german english french spanish; do
  mkdir -p "$SHOP/lang/$LANG/modules/system"
  cp "lang/$LANG/modules/system/mrhanf_fpc.php" \
     "$SHOP/lang/$LANG/modules/system/"
done
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
# Preloader (alle 2 Stunden)
0 */2 * * * cd /pfad/zum/shop && /usr/local/bin/php fpc_preloader.php >> cache/fpc/preloader.log 2>&1

# Cache-Bereinigung (taeglich 3 Uhr)
0 3 * * * cd /pfad/zum/shop && /usr/local/bin/php fpc_flush.php --expired >> cache/fpc/flush.log 2>&1
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
