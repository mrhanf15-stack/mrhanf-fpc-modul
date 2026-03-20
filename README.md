# Mr. Hanf Full Page Cache (FPC) v6.1.1

Ein extrem schnelles, Cron-basiertes Full Page Cache System für modified eCommerce (v2.0.7.2).
Speziell entwickelt, um Reverse-Proxies (wie bei Artfiles) zu umgehen, indem statische HTML-Dateien generiert und per `.htaccess` ausgeliefert werden.

## Changelog

### v6.1.1 (2026-03-20)
- **Bugfix:** `/vergleich` (Produktvergleichsseite) aus dem Cache ausgeschlossen — die Seite ist sessionabhängig und darf nicht gecacht werden
- **Bugfix:** `/wishlist` (Merkzettel) aus dem Cache ausgeschlossen
- Zweite Sicherheitsstufe in `fpc_serve.php`: URL-basierte Ausschlussliste verhindert Auslieferung von sessionabhängigen Seiten, auch wenn `.htaccess`-Regeln sie durchlassen sollten
- Standard-Ausschlussliste im Admin-Modul um `vergleich` und `wishlist` erweitert

### v6.0.0
- Initiale Version mit Cron-basiertem Preloading und `.htaccess`-Auslieferung

## Warum v6.x? (Die Artfiles-Lösung)
Herkömmliche Caching-Module nutzen Hooks in PHP (`application_top.php`), um gecachte Seiten auszuliefern. Bei Hostern wie Artfiles sitzt jedoch ein Reverse-Proxy (Nginx/Varnish) *vor* dem PHP-Interpreter. Dieser Proxy fängt normale Seitenaufrufe ab und reicht sie gar nicht erst an PHP weiter, weshalb herkömmliche Hook-basierte Caches nicht funktionieren.

**Die Lösung:**
Dieses Modul nutzt einen Cron-Job, der im Hintergrund den Shop besucht und fertige HTML-Dateien unter `/cache/fpc/` speichert. Die `.htaccess` prüft bei jedem Aufruf, ob eine statische HTML-Datei existiert. Wenn ja, wird diese über ein winziges PHP-Script (`fpc_serve.php`) in unter 10ms ausgeliefert. Wenn nein, greift der normale Shop-Ablauf.

## Features
- **TTFB unter 0.1 Sekunden** für Gäste
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

## Update von v6.0.0 auf v6.1.1

Wenn das Modul bereits installiert ist, sind folgende Schritte nötig:

1. **Dateien ersetzen:** `fpc_serve.php`, `fpc_preloader.php`, `fpc_flush.php`, `htaccess_fpc_rules.txt` und `admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php` durch die neuen Versionen ersetzen.
2. **`.htaccess` aktualisieren:** Den FPC-Block in der `.htaccess` durch den neuen Inhalt aus `htaccess_fpc_rules.txt` ersetzen.
3. **Ausschlussliste im Admin aktualisieren:** Im Admin-Bereich unter **Module → System Module → Mr. Hanf Full Page Cache** die Einstellung **Ausgeschlossene Seiten** prüfen und `vergleich,wishlist` ergänzen (falls nicht bereits vorhanden).
4. **Cache leeren:** Im Admin-Bereich auf "Cache leeren" klicken oder per SSH `php fpc_flush.php` ausführen, damit keine veralteten Vergleichsseiten-Caches mehr ausgeliefert werden.
5. **Gecachte /vergleich-Datei löschen:** `php fpc_flush.php --url /vergleich`

## Cache manuell leeren
Sie können den Cache auf drei Arten leeren:
1. Im Admin-Bereich unter **Module** -> **System Module** -> **Mr. Hanf Full Page Cache** auf "Cache leeren" klicken.
2. Per SSH: `php fpc_flush.php`
3. Per SSH für eine einzelne URL: `php fpc_flush.php --url /vergleich`

## Fehlerbehebung
- **Der Cache wird nicht aufgebaut:** Prüfen Sie, ob der Cron-Job korrekt läuft und ob das Verzeichnis `cache/fpc/` Schreibrechte (777) hat. Lesen Sie die Datei `cache/fpc/preloader.log`.
- **Die Seiten laden nicht schneller:** Prüfen Sie, ob die `.htaccess` Regeln korrekt an den Anfang der Datei kopiert wurden. Testen Sie den Aufruf in einem Inkognito-Fenster (ohne Login).
- **Vergleichs-Icons werden nicht aktualisiert:** Stellen Sie sicher, dass `/vergleich` in der Ausschlussliste steht und die `.htaccess`-Regeln aktualisiert wurden (v6.1.1). Leeren Sie anschließend den Cache.
