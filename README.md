# Mr. Hanf Full Page Cache (FPC) v8.0.0

**Cron-basiertes Full Page Cache System fuer modified eCommerce (xt:Commerce Fork)**

## Ueberblick

Das FPC-Modul generiert statische HTML-Dateien fuer alle Shop-Seiten und laesst Apache diese **direkt** ausliefern — ohne PHP-Worker. Das Ergebnis: Ladezeiten unter 100ms fuer Gastbesucher bei minimaler Serverbelastung.

## Architektur v8.0

```
Gast-Besucher  → Apache → cache/fpc/{url}/index.html → HTML direkt (kein PHP!)
Eingeloggter   → Apache → index.php → modified eCommerce → dynamische Seite
```

### Vorher (v7.x)
```
Gast → Apache → fpc_serve.php (PHP-Worker) → readfile() → HTML
```

### Jetzt (v8.0)
```
Gast → Apache → HTML-Datei direkt (0 PHP-Worker belegt)
```

## Dateien

| Datei | Zweck |
|-------|-------|
| `htaccess_fpc_rules.txt` | Apache RewriteRules — in .htaccess einfuegen |
| `fpc_preloader.php` | Cron-Job: Baut Cache auf (Rate-Limited, Load-geschuetzt) |
| `fpc_serve.php` | Fallback: PHP-basierte Auslieferung (nur bei Bedarf) |
| `fpc_flush.php` | CLI: Cache leeren (komplett, einzeln, oder abgelaufen) |
| `admin_q9wKj6Ds/.../mrhanf_fpc.php` | Admin-Modul fuer Konfiguration |
| `lang/{de,en,fr,es}/.../mrhanf_fpc.php` | Sprachdateien (4 Sprachen) |
| `DEPLOY_v8.sh` | Automatisches Deployment-Script |

## Installation

### 1. Deployment ausfuehren

```bash
bash DEPLOY_v8.sh
```

Oder manuell:

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

Den Inhalt von `htaccess_fpc_rules.txt` am **Anfang** der `.htaccess` einfuegen, **vor** allen anderen RewriteRules. Die alten FPC-Regeln (v7.x) muessen entfernt werden.

### 3. Admin-Modul aktivieren

Im Shop-Admin unter **Module → System-Module** das Modul "Mr. Hanf Full Page Cache" installieren (falls noch nicht geschehen).

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

Oder im Admin-Bereich ueber den "Cache leeren" Button.

## Ausgeschlossene Seiten (Standard)

Folgende Seiten werden **niemals** gecacht (sessionabhaengig):

| Seite | Grund |
|-------|-------|
| `/vergleich` | Produktvergleich (Session) |
| `/wishlist` | Merkzettel (benutzerspezifisch) |
| `/checkout` | Bestellprozess |
| `/login` | Login-Seite |
| `/account` | Kundenkonto |
| `/shopping_cart` | Warenkorb |
| `/logoff` | Abmelden |
| `/admin` | Admin-Bereich |

## Sicherheitsfeatures

### Session-Erkennung
Eingeloggte User (MODsid/PHPSESSID Cookie) bekommen **immer** die dynamische Seite.

### HTML-Validierung (v8.0 — 7 Schichten)

| Schicht | Pruefung | Beschreibung |
|---------|----------|--------------|
| 1 | Mindestgroesse | Min. 1000 Bytes |
| 2 | DOCTYPE/HTML | `<!DOCTYPE` oder `<html>` am Anfang |
| 3 | BODY-Tag | `<body>` muss vorhanden sein |
| 4 | Closing-Tag | `</html>` oder `</body>` am Ende |
| 5 | PHP-Fehler | Kein `Fatal error`, `Warning` etc. |
| 6 | Leere-Seiten | strip_tags() muss > 100 Zeichen ergeben |
| 7 | Verify-After-Write | Cache-Datei wird nach Schreiben zurueckgelesen |

### Weitere Schutzmechanismen
- **Health-Marker**: `<!-- FPC-VALID -->` in jeder Cache-Datei
- **Atomic Write**: tmp-Datei → rename() (keine Partial Reads)
- **Fehlerquoten-Schutz**: Stoppt bei > 20% Fehlern (Server-Problem)

## Rate-Limiting (Preloader)

| Parameter | Wert | Beschreibung |
|-----------|------|--------------|
| Request-Pause | 500ms | Zwischen jedem Request |
| Load-Schwelle | 3.0 | Pausiert bei hoher Last |
| Load-Pause | 30s | Wartezeit bei hoher Last |
| Batch-Groesse | 100 | Seiten pro Batch |
| Batch-Pause | 30s | Erholung zwischen Batches |
| Slow-Threshold | 3000ms | Ab hier Pause verdoppeln |
| Max. Laufzeit | 45min | Harter Abbruch |

## Fallback auf PHP-Auslieferung

Falls die direkte Apache-Auslieferung Probleme macht, kann auf die PHP-basierte Auslieferung (v7.x Modus) zurueckgeschaltet werden:

In `.htaccess` aendern:
```apache
# v8.0 (direkt):
RewriteRule ^(.+)$ cache/fpc/$1/index.html [L,T=text/html]

# Fallback (PHP):
RewriteRule ^(.+)$ fpc_serve.php [L,QSA]
```

## Changelog

### v8.0.0 (2026-03-22)
- **NEU**: Direkte Apache-Auslieferung (kein PHP-Worker fuer gecachte Seiten)
- **NEU**: Erweiterte HTML-Validierung (DOCTYPE, BODY, Leere-Seiten-Erkennung)
- **NEU**: Verify-After-Write (Cache-Datei wird nach Schreiben zurueckgelesen)
- **NEU**: Fehlerquoten-Schutz (stoppt bei > 20% Fehlern)
- **AUFGERAEUMT**: Nur noch FPC-relevante Dateien im Repository

### v7.1.0 (2026-03-22)
- Rate-Limiting fuer Preloader
- Server-Load-Schutz und adaptive Drosselung
- Batch-Pausen und maximale Laufzeit

### v7.0.3 (2026-03-22)
- Fix: 304 Not Modified entfernt (verursachte weisse Seiten auf Artfiles)
- Fix: Alle RewriteCond fuer beide RewriteRules wiederholt
- Fix: PHP-Fehler-Erkennung mit Regex statt strpos

### v7.0.0 (2026-03-22)
- Erste ausfallsichere Version mit 5-facher Validierung
- Atomic Write Pattern und Health-Marker System

### v6.1.1 (2026-03-20)
- Produktvergleich und Merkzettel aus Cache ausgeschlossen

### v6.0.0
- Initiale Version

## Fehlerbehebung

| Problem | Loesung |
|---------|---------|
| Weisse Seiten | Cache leeren: `php fpc_flush.php` |
| Cache wird nicht aufgebaut | `cache/fpc/` Schreibrechte (777) pruefen, `preloader.log` lesen |
| Seiten nicht schneller | .htaccess pruefen, Inkognito-Fenster testen |
| Eingeloggte User langsam | FPC greift nur fuer Gaeste — das ist normal |

## Lizenz

Proprietaer — Mr. Hanf / mrhanf15-stack
