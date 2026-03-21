# Mr. Hanf Full Page Cache (FPC) v7.0.0

Ein extrem schnelles, Cron-basiertes Full Page Cache System für modified eCommerce (v2.0.7.2).
Speziell entwickelt, um Reverse-Proxies (wie bei Artfiles) zu umgehen, indem statische HTML-Dateien generiert und per `.htaccess` ausgeliefert werden.

## Changelog

### v7.0.0 (2026-03-21) — Ausfallsicher / Failsafe
- **NEU: 5 Schutzschichten gegen weisse Seiten (White Pages)**
  1. **Mindestgroesse-Validierung** — Serve: 500 Bytes, Preloader: 1000 Bytes
  2. **Health-Marker-Pruefung** — Jede gecachte Datei muss `<!-- FPC-VALID -->` enthalten
  3. **TTL-Validierung** — Maximales Alter 48 Stunden (Notfall-TTL)
  4. **Closing-Tag-Pruefung** — `</html>` oder `</body>` muss vorhanden sein
  5. **PHP-Fehler-Erkennung** — Keine `Fatal error`, `Warning`, `Parse error` im Cache
- **NEU: Atomic Write Operations** — Preloader schreibt in `.tmp`-Datei, validiert, dann `rename()` (verhindert korrupte Cache-Dateien)
- **NEU: Graceful Fallback** — Bei ungueltigem Cache wird die Seite live vom Shop geladen (kein White Page)
- **Bugfix:** `process()` Methode im Admin-Modul hinzugefuegt (verhindert weisse Seite beim Speichern der Einstellungen)
- **Bugfix:** Sprachdateien fuer alle 4 Sprachen (DE, EN, FR, ES) hinzugefuegt

### v6.1.1 (2026-03-20)
- **Bugfix:** `/vergleich` (Produktvergleichsseite) aus dem Cache ausgeschlossen — die Seite ist sessionabhängig und darf nicht gecacht werden
- **Bugfix:** `/wishlist` (Merkzettel) aus dem Cache ausgeschlossen
- Zweite Sicherheitsstufe in `fpc_serve.php`: URL-basierte Ausschlussliste verhindert Auslieferung von sessionabhängigen Seiten, auch wenn `.htaccess`-Regeln sie durchlassen sollten
- Standard-Ausschlussliste im Admin-Modul um `vergleich` und `wishlist` erweitert

### v6.0.0
- Initiale Version mit Cron-basiertem Preloading und `.htaccess`-Auslieferung

## Warum v7.0? (Ausfallsicher / Failsafe)

**Das Problem:** In v6.x konnte es vorkommen, dass der Cache leere oder fehlerhafte HTML-Dateien enthielt (z.B. durch PHP-Fehler, Timeout beim Preloading, oder korrupte Schreibvorgaenge). Wenn `fpc_serve.php` solche Dateien auslieferte, sah der Besucher eine **weisse Seite** statt des Shops.

**Die Loesung in v7.0:** Jede Cache-Datei wird vor der Auslieferung durch 5 unabhaengige Pruefungen validiert. Faellt auch nur eine Pruefung durch, wird die Datei **nicht** ausgeliefert und der normale Shop-Ablauf greift. Zusaetzlich schreibt der Preloader Dateien atomar (erst `.tmp`, dann `rename()`), sodass nie eine halb geschriebene Datei im Cache landet.

## Features
- **TTFB unter 0.1 Sekunden** für Gäste
- **5-fache Validierung** gegen weisse Seiten (White Pages)
- **Atomic Write** — keine korrupten Cache-Dateien moeglich
- **Graceful Fallback** — bei ungueltigem Cache wird die Live-Seite geladen
- Komplett unsichtbar für eingeloggte Kunden (diese sehen immer die Live-Seite)
- Admin-Modul zur einfachen Konfiguration (TTL, max. Seiten, Ausschlussliste)
- Cache-Status und "Cache leeren" Button direkt im Admin-Bereich
- Völlig unabhängig von `application_top` Hooks
- **Doppelte Sicherheit:** Sessionabhängige Seiten werden sowohl per `.htaccess` als auch per PHP-Ausschlussliste in `fpc_serve.php` ausgesperrt

## Ausgeschlossene Seiten (Standard)

Die folgenden Seiten werden **niemals** gecacht, da sie sessionabhängige oder benutzerspezifische Inhalte enthalten:

| Seite | Grund |
|---|---|
| `/vergleich` | Produktvergleich — zeigt Produkte aus der Session des Benutzers |
| `/wishlist` | Merkzettel — benutzerspezifisch |
| `/checkout` | Bestellprozess |
| `/login` | Login-Seite |
| `/account` | Kundenkonto |
| `/shopping_cart` | Warenkorb |
| `/logoff` | Abmelden |
| `/password_double_opt` | Passwort-Opt-In |
| `/create_account` | Registrierung |
| `/contact_us` | Kontaktformular |
| `/tell_a_friend` | Weiterempfehlen |
| `/product_reviews_write` | Bewertung schreiben |
| `/admin` | Admin-Bereich |

Weitere Seiten können im Admin-Bereich unter **Module → System Module → Mr. Hanf Full Page Cache** in der Einstellung **Ausgeschlossene Seiten** hinzugefügt werden (kommagetrennt).

## Installation

### 1. Dateien hochladen
Kopieren Sie alle Dateien aus diesem Repository in das Hauptverzeichnis Ihres Shops.
*(Die Ordnerstruktur ist bereits korrekt angelegt. Achten Sie darauf, den Ordner `admin_q9wKj6Ds` entsprechend Ihres tatsächlichen Admin-Verzeichnisses umzubenennen!)*

### 2. Modul im Admin-Bereich installieren
1. Loggen Sie sich in Ihren modified eCommerce Admin-Bereich ein.
2. Gehen Sie zu **Module** -> **System Module**.
3. Suchen Sie nach **Mr. Hanf Full Page Cache** und klicken Sie auf **Installieren**.
4. Passen Sie die Konfiguration nach Ihren Wünschen an (z.B. Cache Lebensdauer, ausgeschlossene Seiten).

### 3. .htaccess anpassen (WICHTIG!)
Öffnen Sie die `.htaccess` Datei im Hauptverzeichnis Ihres Shops.
Kopieren Sie den Inhalt der Datei `htaccess_fpc_rules.txt` und fügen Sie ihn **ganz oben** in Ihre `.htaccess` ein, noch **VOR** den regulären RewriteRules des Shops.

### 4. Cron-Jobs einrichten
Richten Sie in Ihrem Hosting-Control-Panel (oder per SSH) folgende Cron-Jobs ein:

**Preloader (z.B. alle 30 Minuten):**
Besucht den Shop und generiert die HTML-Dateien.
```bash
*/30 * * * * cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www && /usr/local/bin/php fpc_preloader.php >> cache/fpc/preloader.log 2>&1
```

**Cache-Bereinigung (z.B. 1x täglich nachts):**
Löscht abgelaufene Cache-Dateien.
```bash
0 3 * * * cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www && /usr/local/bin/php fpc_flush.php --expired >> cache/fpc/flush.log 2>&1
```

*(Passen Sie den Pfad `/home/www/doc/28856/dcp288560004/mr-hanf.de/www` an Ihr System an!)*

## Update von v6.x auf v7.0.0

Wenn das Modul bereits installiert ist, sind folgende Schritte nötig:

1. **Dateien ersetzen:** `fpc_serve.php`, `fpc_preloader.php` und `admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php` durch die neuen v7.0 Versionen ersetzen.
2. **Sprachdateien hochladen:** Die 4 Sprachdateien unter `lang/{german,english,french,spanish}/extra/admin/mrhanf_fpc.php` hochladen.
3. **Cache komplett leeren:** Im Admin-Bereich auf "Cache leeren" klicken oder per SSH: `rm -rf cache/fpc/*.html cache/fpc/*/*.html` — Die alten Cache-Dateien enthalten keinen `<!-- FPC-VALID -->` Health-Marker und werden von v7.0 abgelehnt.
4. **OpCache leeren:** `php -r "opcache_reset();"` oder im Admin-Bereich eine beliebige Seite aufrufen.
5. **Preloader manuell testen:** `php fpc_preloader.php 2>&1 | head -30` — Prüfen ob die neuen Validierungen greifen.

## Validierungsschichten (v7.0)

| Schicht | Pruefung | Serve-Schwelle | Preloader-Schwelle |
|---|---|---|---|
| 1 | Mindestgroesse | 500 Bytes | 1000 Bytes |
| 2 | Health-Marker | `<!-- FPC-VALID -->` | `<!-- FPC-VALID -->` |
| 3 | TTL | 48h max (Notfall) | 24h (konfigurierbar) |
| 4 | Closing-Tag | `</html>` oder `</body>` | `</html>` oder `</body>` |
| 5 | PHP-Fehler | Kein `Fatal error` etc. | Kein `Fatal error` etc. |

## Cache manuell leeren
Sie können den Cache auf drei Arten leeren:
1. Im Admin-Bereich unter **Module** -> **System Module** -> **Mr. Hanf Full Page Cache** auf "Cache leeren" klicken.
2. Per SSH: `php fpc_flush.php`
3. Per SSH für eine einzelne URL: `php fpc_flush.php --url /vergleich`

## Produktvergleich Cookie-Fix (v6.1.1)

Das Modul enthält einen separaten Fix für ein Bug im Produktvergleich-System:

**Problem:** Wenn ein angemeldeter Kunde Produkte in den Vergleich legt, sich abmeldet und dann den Vergleich leert, bleibt das Vergleichs-Icon trotzdem befüllt. Nach einem Refresh erscheinen die Produkte sogar wieder im Vergleich — weil der `pc_compare_ids`-Cookie beim Abmelden nicht gelöscht wird und der `cookieRestore`-Mechanismus die Produkte erneut in die Server-Session schreibt.

**Lösung:** Zwei PHP-Dateien im `includes/extra/`-System von modified:

| Datei | Funktion |
|---|---|
| `includes/extra/application_top/mrhanf_compare_fix.php` | Löscht beim Logoff den `pc_compare_ids`-Cookie serverseitig |
| `includes/extra/application_bottom/mrhanf_compare_fix_js.php` | Löscht beim Logoff den Cookie auch clientseitig per JavaScript |

**Installation:**
1. Beide Dateien in die entsprechenden Verzeichnisse des Shops hochladen
2. Die Dateien werden von modified automatisch eingebunden — kein weiterer Eingriff nötig
3. Optional: `sql/mrhanf_compare_saved.sql` ausführen, wenn die Vergleichsliste nach dem Login wiederhergestellt werden soll

**Gewünschtes Verhalten nach dem Fix:**
- Abmelden → Vergleichs-Icon sofort auf 0, Cookie gelöscht
- Anmelden → leere Vergleichsliste (sauberer Start)
- Optional: Anmelden → gespeicherte Vergleichsliste aus DB wiederherstellen

## Fehlerbehebung
- **Weisse Seiten (v7.0 sollte das verhindern):** Prüfen Sie `cache/fpc/preloader.log` auf Fehler. Wenn trotzdem weisse Seiten auftreten, prüfen Sie ob `fpc_serve.php` die v7.0 Version ist (`grep FPC-VALID fpc_serve.php`).
- **Der Cache wird nicht aufgebaut:** Prüfen Sie, ob der Cron-Job korrekt läuft und ob das Verzeichnis `cache/fpc/` Schreibrechte (777) hat. Lesen Sie die Datei `cache/fpc/preloader.log`.
- **Die Seiten laden nicht schneller:** Prüfen Sie, ob die `.htaccess` Regeln korrekt an den Anfang der Datei kopiert wurden. Testen Sie den Aufruf in einem Inkognito-Fenster (ohne Login).
- **Vergleichs-Icons werden nicht aktualisiert:** Stellen Sie sicher, dass `/vergleich` in der Ausschlussliste steht und die `.htaccess`-Regeln aktualisiert wurden (v6.1.1). Leeren Sie anschließend den Cache.
