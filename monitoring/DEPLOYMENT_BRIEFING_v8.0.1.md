# Deployment-Briefing: FPC v8.0.1 — Direkte Apache-Auslieferung

**Datum:** 2026-03-23
**Priorität:** Hoch
**Geschätzter Aufwand:** 15-30 Minuten (mit SSH-Zugang)

---

## Zusammenfassung

Der Live-Server (mr-hanf.de) läuft aktuell mit **FPC v6.1.1**, die einen **kritischen Bug** hat und den FPC **nicht korrekt aktiviert**. Das Repository enthält die fertige **v8.0.1**, die Apache gecachte HTML-Dateien **direkt** ausliefern lässt — ohne einen einzigen PHP-Worker zu belegen.

## Aktueller Zustand des Live-Servers

| Prüfpunkt | Ergebnis | Problem? |
|---|---|---|
| Startseite (/) | **HTTP 403 Forbidden** | JA — separates Problem |
| Unterseiten (/samen-shop/ etc.) | HTTP 200, funktionieren | Nein |
| FPC aktiv? | **NEIN** — kein X-FPC-Cache Header | JA |
| FPC-VALID Marker im HTML? | **NEIN** | JA |
| htaccess-Version | v6.1.1 (kritischer Bug) | JA |
| fpc_serve.php direkt abrufbar? | JA (HTTP 200) | Sicherheitsrisiko |

**Fazit:** Der FPC ist auf dem Live-Server aktuell **komplett inaktiv**. Alle Seiten werden live vom PHP-Backend generiert (TTFB 2-8 Sekunden).

## Was v8.0.1 ändert

### Vorher (v6.1.1 — aktuell auf dem Server)
```
Gast → Apache → fpc_serve.php (PHP-Worker) → readfile() → HTML
```
**Problem:** Jeder gecachte Request belegt trotzdem einen PHP-Worker. Bei 50 gleichzeitigen Besuchern = 50 PHP-Worker belegt.

### Nachher (v8.0.1 — im Repository bereit)
```
Gast → Apache → cache/fpc/{url}/index.html → HTML direkt (0 PHP-Worker!)
```
**Vorteil:** PHP-Worker werden NUR für eingeloggte User und nicht-gecachte Seiten benötigt. Bei 50 gleichzeitigen Gast-Besuchern = 0 PHP-Worker belegt.

## Deployment-Schritte

### Schritt 1: SSH-Verbindung zum Server

```bash
ssh user@artfiles-server
```

### Schritt 2: Deployment-Script ausführen

```bash
cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www
wget -q -O DEPLOY_v8.sh "https://raw.githubusercontent.com/mrhanf15-stack/mrhanf-fpc-modul/master/DEPLOY_v8.sh"
bash DEPLOY_v8.sh
```

Das Script macht automatisch:
1. Backup aller alten Dateien (mit Timestamp)
2. Download der neuen PHP-Dateien von GitHub
3. Download der Sprachdateien
4. Alten Cache leeren
5. Debug-Dateien aufräumen
6. Versionsprüfung
7. Preloader-Smoke-Test

### Schritt 3: .htaccess MANUELL aktualisieren (WICHTIG!)

Das Deployment-Script ändert die .htaccess **nicht automatisch** (zu riskant). Das muss manuell gemacht werden:

**3a. Backup prüfen:**
```bash
ls -la cache/fpc_backup_*/htaccess.bak
```

**3b. .htaccess bearbeiten:**
```bash
nano .htaccess
```

**3c. Alten FPC-Block finden und löschen:**
Alles zwischen `# --- FPC START ---` und `# --- FPC ENDE ---` entfernen.

**3d. Neuen v8.0.1 Block einfügen:**
Den Inhalt von `htaccess_fpc_rules.txt` am **ANFANG** der .htaccess einfügen, **VOR** allen anderen RewriteRules.

Der neue Block sieht so aus (Kurzfassung):
```apache
# --- FPC START v8.0 ---
<IfModule mod_rewrite.c>
RewriteEngine On

# === Startseite ===
RewriteCond %{REQUEST_METHOD} =GET
RewriteCond %{HTTP_COOKIE} !MODsid [NC]
RewriteCond %{HTTP_COOKIE} !PHPSESSID [NC]
RewriteCond %{REQUEST_URI} !^/admin [NC]
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{DOCUMENT_ROOT}/cache/fpc/index.html -f
RewriteRule ^$ cache/fpc/index.html [L,T=text/html]

# === Unterseiten (ALLE Conds wiederholt!) ===
RewriteCond %{REQUEST_METHOD} =GET
RewriteCond %{HTTP_COOKIE} !MODsid [NC]
RewriteCond %{HTTP_COOKIE} !PHPSESSID [NC]
RewriteCond %{REQUEST_URI} !^/admin [NC]
RewriteCond %{REQUEST_URI} !\.(css|js|jpg|...) [NC]
RewriteCond %{QUERY_STRING} ^$
RewriteCond %{REQUEST_URI} !checkout [NC]
RewriteCond %{REQUEST_URI} !^/kasse [NC]        # NEU v8.0.1
RewriteCond %{REQUEST_URI} !^/warenkorb [NC]    # NEU v8.0.1
... (weitere Ausschlüsse) ...
RewriteCond %{DOCUMENT_ROOT}/cache/fpc/%{REQUEST_URI}/index.html -f
RewriteRule ^(.+)$ cache/fpc/$1/index.html [L,T=text/html]
</IfModule>

# === FPC Cache-Control Header ===
<IfModule mod_headers.c>
<FilesMatch "^cache/fpc/.+\.html$">
    Header set X-FPC-Cache "HIT"
    Header set X-FPC-Version "8.0.1"
    Header set Cache-Control "no-store, no-cache, must-revalidate"
</FilesMatch>
</IfModule>
# --- FPC ENDE v8.0 ---
```

Die vollständige Datei liegt im Repository: `htaccess_fpc_rules.txt`

### Schritt 4: Cron-Job prüfen

```bash
crontab -l
```

Soll enthalten:
```bash
# FPC Preloader (alle 2 Stunden)
0 */2 * * * cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www && /usr/local/bin/php fpc_preloader.php >> cache/fpc/preloader.log 2>&1

# FPC Cache-Bereinigung (täglich 3 Uhr)
0 3 * * * cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www && /usr/local/bin/php fpc_flush.php --expired >> cache/fpc/flush.log 2>&1
```

### Schritt 5: Testen

```bash
# 1. Preloader manuell starten (baut Cache auf)
cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www
/usr/local/bin/php fpc_preloader.php 2>&1 | head -20

# 2. Prüfen ob Cache-Dateien erstellt wurden
ls -la cache/fpc/
ls -la cache/fpc/samen-shop/

# 3. Von extern testen (anderer Rechner, Inkognito-Fenster)
curl -sI "https://mr-hanf.de/samen-shop/" | grep -i "x-fpc"
# Erwartete Ausgabe: X-FPC-Cache: HIT
#                    X-FPC-Version: 8.0.1
```

## Kritischer Bug in v6.1.1 (aktuell auf dem Server)

Die aktuelle .htaccess hat folgenden Bug:

```apache
# v6.1.1 (FEHLERHAFT):
RewriteCond %{REQUEST_METHOD} =GET      # ← Diese Bedingungen gelten
RewriteCond %{HTTP_COOKIE} !MODsid      #   NUR für die erste Rule!
RewriteCond ... (viele weitere)
RewriteRule ^$ fpc_serve.php [L,QSA]    # ← Startseite (OK)

# ACHTUNG: Für diese zweite Rule gelten die Conds NICHT!
RewriteCond %{DOCUMENT_ROOT}/cache/fpc/%{REQUEST_URI}/index.html -f
RewriteRule ^(.+)$ fpc_serve.php [L,QSA]  # ← Unterseiten (KEIN Schutz!)
```

In Apache gelten RewriteCond **nur für die unmittelbar folgende RewriteRule**. Die v6.1.1 wiederholt die Bedingungen nicht für die zweite Rule — das bedeutet, dass Unterseiten theoretisch auch für eingeloggte User aus dem Cache ausgeliefert werden könnten.

Die v8.0.1 behebt das, indem **alle Bedingungen für beide Rules wiederholt** werden.

## Separates Problem: Startseite 403

Die Startseite gibt HTTP 403 Forbidden zurück. Das ist **nicht** vom FPC verursacht (der FPC ist ja nicht aktiv). Mögliche Ursachen:

1. **DirectoryIndex** fehlt oder ist falsch konfiguriert
2. **index.php Berechtigungen** sind falsch (chmod 644 nötig)
3. **.htaccess Fehler** — der v6.1.1 Bug könnte einen Redirect-Loop verursachen
4. **Rate-Limiting/WAF** blockiert bestimmte Requests

**Prüfung:**
```bash
ls -la /home/www/doc/28856/dcp288560004/mr-hanf.de/www/index.php
cat .htaccess | head -30
```

## Rollback-Plan

Falls nach dem Deployment Probleme auftreten:

```bash
# Backup-Verzeichnis finden
ls -la cache/fpc_backup_*/

# Alte Dateien wiederherstellen
BACKUP="cache/fpc_backup_YYYYMMDD_HHMMSS"
cp "${BACKUP}/fpc_serve.php.bak" fpc_serve.php
cp "${BACKUP}/fpc_preloader.php.bak" fpc_preloader.php
cp "${BACKUP}/fpc_flush.php.bak" fpc_flush.php
cp "${BACKUP}/htaccess.bak" .htaccess
```

## Erwartetes Ergebnis nach Deployment

| Metrik | Vorher (v6.1.1) | Nachher (v8.0.1) |
|---|---|---|
| TTFB (Gast, gecacht) | 2-8 Sekunden | < 100ms |
| PHP-Worker pro gecachtem Request | 1 | **0** |
| FPC Cache HIT-Rate | 0% (nicht aktiv) | > 90% |
| Payment/Kasse geschützt | Teilweise | **Vollständig** (5 Schichten) |
| Weiße-Seiten-Schutz | Nicht vorhanden | **7-fache Validierung** |

---

**Repository:** https://github.com/mrhanf15-stack/mrhanf-fpc-modul
**Branch:** master
**Aktuelle Version:** v8.0.1
