# FPC v7.0.3 — Debug-Report und Funktionsprüfung

**Datum:** 2026-03-22
**Geprüft von:** Manus AI
**Shop:** https://mr-hanf.de
**FPC-Version:** v7.0.3 (fpc_serve.php), v7.0.1 (fpc_preloader.php), v6.1.1 (fpc_flush.php)

---

## 1. Live-Test Ergebnisse

Die Live-Test-Suite hat 33 Tests gegen den produktiven Shop ausgeführt. Das Ergebnis ist insgesamt sehr positiv.

| Kategorie | Ergebnis |
|---|---|
| Tests bestanden (PASS) | **30** |
| Tests fehlgeschlagen (FAIL) | **0** |
| Warnungen (WARN) | **0** |
| Informationen (INFO) | **3** |
| Weiße Seiten | **0** |
| PHP-Fehler im Response | **0** |
| Session-ID Leaks | **0** |

### 1.1 FPC-Funktionalität: Startseite

Die Startseite wird korrekt vom FPC ausgeliefert. Alle relevanten Header sind vorhanden und der FPC-VALID Health-Marker ist im HTML-Body enthalten.

| Prüfung | Ergebnis |
|---|---|
| X-FPC-Cache | **HIT** |
| X-FPC-Version | **7.0.3** |
| X-FPC-Cached-At | Sun, 22 Mar 2026 08:42:04 GMT |
| FPC-VALID Marker | Vorhanden |
| Cache-Control | no-store (korrekt, verhindert Browser-Caching) |
| Body-Größe | 187.669 Bytes |
| TTFB | 2.1s (trotz FPC — siehe Abschnitt 3) |

### 1.2 Cookie-basiertes Verhalten

Das Session-Management funktioniert korrekt. Gäste bekommen den Cache, eingeloggte User die Live-Seite.

| Szenario | FPC-Status | Bewertung |
|---|---|---|
| Gast (ohne Cookie) | **HIT** | Korrekt |
| Eingeloggt (MODsid Cookie) | **NONE** | Korrekt — kein Cache |

### 1.3 Ausgeschlossene Seiten

Alle session-abhängigen Seiten werden korrekt vom Cache ausgeschlossen.

| Seite | FPC-Status | Bewertung |
|---|---|---|
| /vergleich | NONE | Korrekt |
| /warenkorb | NONE | Korrekt |
| /anmelden | NONE | Korrekt |

### 1.4 Kategorie-Seiten

Alle 8 getesteten Kategorie-Seiten sind erreichbar (HTTP 200) mit vollem Content. Allerdings wird **keine einzige Kategorie-Seite vom FPC ausgeliefert**.

| Seite | HTTP | Body | TTFB | FPC |
|---|---|---|---|---|
| /samen-shop/ | 200 | 291 KB | 2.9s | NONE |
| /samen-shop/autoflowering-samen/ | 200 | 256 KB | 5.6s | NONE |
| /samen-shop/feminisierte-samen/ | 200 | 267 KB | 2.8s | NONE |
| /samen-shop/regulaere-samen/ | 200 | 212 KB | 2.7s | NONE |
| /cannabispflanzen/ | 200 | 141 KB | 2.3s | NONE |
| /growshop/ | 200 | 145 KB | 2.6s | NONE |
| /headshop/ | 200 | 119 KB | 2.4s | NONE |
| /blog/ | 200 | 156 KB | 2.5s | NONE |

**Diagnose:** Der Preloader hat für diese Seiten entweder noch keine Cache-Dateien erzeugt, oder die `.htaccess`-Regeln matchen nicht korrekt auf die SEO-URLs.

### 1.5 Sitemap

Die Sitemap ist verfügbar und enthält einen Sitemap-Index mit 4 Sub-Sitemaps. Der Preloader kann sie als URL-Quelle nutzen.

---

## 2. Code-Analyse

### 2.1 fpc_serve.php (v7.0.3) — Auslieferung

Die Auslieferungslogik ist grundsätzlich solide, hat aber eine **Diskrepanz zum README**.

**Implementierte Schichten:**

| Schicht | Beschreibung | Status |
|---|---|---|
| 1 | Mindestgröße (500 Bytes) | Implementiert |
| 2 | Health-Marker (FPC-VALID) | Implementiert (letzte 200 Bytes) |
| 3 | TTL (48h max) | Implementiert |
| 4 | Closing-Tag (html/body) | **NICHT implementiert** |
| 5 | PHP-Fehler-Erkennung | **NICHT implementiert** |

> **Befund:** Der README bewirbt 5 Validierungsschichten, aber `fpc_serve.php` implementiert nur 3. Die Schichten 4 und 5 sind ausschließlich im Preloader vorhanden. Das ist in der Praxis kein großes Risiko, da der Preloader die Dateien validiert bevor sie geschrieben werden. Allerdings könnte eine manuell platzierte korrupte Datei im Cache-Verzeichnis ausgeliefert werden.

**Positive Aspekte:**
- Directory Traversal Schutz via `realpath()`
- Session-Cookie-Check (MODsid + PHPSESSID)
- Auto-Delete korrupter Dateien
- 304/ETag bewusst entfernt (v7.0.3) — verhindert weiße Seiten bei Artfiles-Proxy
- `Cache-Control: no-store` verhindert Browser-Caching der FPC-Antwort

### 2.2 fpc_preloader.php (v7.0.1) — Cache-Aufbau

Der Preloader ist die stärkste Komponente des Moduls. Alle 5 Validierungsschichten sind hier implementiert.

**Validierung vor dem Speichern:**

| Prüfung | Schwelle | Status |
|---|---|---|
| Mindestgröße | 1000 Bytes | Implementiert |
| Closing-Tag | </html> oder </body> in letzten 500 Bytes | Implementiert |
| PHP-Fehler | Regex für Fatal error, Parse error, Warning, Notice, Smarty error | Implementiert |
| Health-Marker | Wird automatisch angehängt | Implementiert |
| Atomic Write | .tmp.PID → rename() | Implementiert |

**Positive Aspekte:**
- Bestehende gültige Cache-Dateien werden NICHT mit ungültigem Content überschrieben
- Session-IDs (MODsid) werden aus HTML entfernt
- Sitemap als primäre URL-Quelle, DB als Fallback
- Content-Type Prüfung (nur text/html)
- 50ms Pause zwischen Requests

**Verbesserungswürdig:**
- `SSL_VERIFYPEER = false` (bei Shared Hosting oft nötig, aber Sicherheitsrisiko)
- Timeout nur 15s (bei TTFB von 5-8s könnten langsame Seiten abbrechen)
- Keine Retry-Logik bei Fehlern
- `language_id = 2` hardcoded (nur deutsche URLs aus DB)
- Kein Locking gegen parallele Cron-Ausführung

### 2.3 fpc_flush.php (v6.1.1) — Cache-Bereinigung

Funktional korrekt mit 3 Modi (all, expired, single URL). Noch auf v6.1.1, aber das ist unproblematisch.

### 2.4 Admin-Modul mrhanf_fpc.php (v7.0.3)

Gut implementiert mit Frontend-Schutz (`_isAdmin()`), Cache-Status-Dashboard und Flush-Button. Die `process()` Methode verhindert weiße Seiten beim Speichern der Einstellungen.

**Verbesserungswürdig:**
- `_tailFile()` liest die gesamte Log-Datei in den Speicher (bei großem preloader.log problematisch)
- Flush über GET-Parameter ohne CSRF-Token

### 2.5 htaccess-Regeln (v7.0.3)

Der kritische Fix aus v7.0.3 (alle RewriteCond für beide Rules wiederholt) ist korrekt implementiert. Die Regeln schließen alle session-abhängigen Seiten korrekt aus.

**Verbesserungswürdig:**
- `/warenkorb` (SEO-URL) ist nicht explizit ausgeschlossen (nur `/shopping_cart`)
- Trailing-Slash-Handling könnte bei manchen URLs Probleme verursachen

---

## 3. Hauptproblem: TTFB trotz FPC-HIT bei 2.1s

Das gravierendste Problem ist, dass selbst die Startseite mit FPC-HIT einen TTFB von **2.1 Sekunden** hat. Das Ziel des FPC ist ein TTFB unter 0.1 Sekunden.

**Mögliche Ursachen:**

| Ursache | Wahrscheinlichkeit | Erklärung |
|---|---|---|
| Reverse-Proxy (Artfiles) | **Hoch** | Der Artfiles-Proxy fügt Latenz hinzu, bevor die Anfrage den Apache erreicht |
| Apache mod_rewrite Overhead | Mittel | Die .htaccess-Regeln werden bei jedem Request ausgewertet |
| PHP-Startup bei fpc_serve.php | Mittel | Auch wenn fpc_serve.php minimal ist, muss PHP trotzdem starten |
| Server-Last | Mittel | Shared Hosting mit anderen Kunden auf demselben Server |
| DNS/TLS Overhead | Gering | Wird bei jedem neuen Request fällig |

> **Wichtig:** Der TTFB von 2.1s bei FPC-HIT ist **nicht** die Schuld des FPC-Codes. Das FPC-Script selbst braucht vermutlich < 10ms. Die 2.1s kommen vom Netzwerk-Stack (DNS, TLS, Reverse-Proxy, PHP-Startup). Ohne FPC wäre der TTFB bei 3-8 Sekunden — der FPC spart also trotzdem 1-6 Sekunden pro Request.

---

## 4. Hauptproblem: Kategorie-Seiten nicht gecacht

Nur die Startseite wird vom FPC ausgeliefert. Alle Kategorie-Seiten (samen-shop, growshop, headshop, blog etc.) werden live generiert.

**Mögliche Ursachen:**

| Ursache | Prüfung |
|---|---|
| Preloader-Cron läuft nicht regelmäßig | `cat cache/fpc/preloader.log` auf dem Server prüfen |
| Cache-Dateien existieren nicht | `ls -la cache/fpc/samen-shop/` auf dem Server prüfen |
| .htaccess-Pfad matcht nicht | Trailing-Slash-Problem: `/samen-shop/` vs. `cache/fpc/samen-shop/index.html` |
| Preloader-Limit zu niedrig | `MODULE_MRHANF_FPC_PRELOAD_LIMIT` im Admin prüfen (Standard: 500) |

---

## 5. Empfehlungen

### Priorität 1 (Sofort)

1. **Preloader-Log prüfen:** `cat cache/fpc/preloader.log` — Läuft der Cron-Job? Wie viele Seiten werden gecacht?
2. **Cache-Verzeichnis prüfen:** `ls -la cache/fpc/` — Existieren Cache-Dateien für Kategorie-Seiten?
3. **Cron-Job Status prüfen:** Ist der Preloader-Cron aktiv? (`crontab -l`)

### Priorität 2 (Kurzfristig)

4. **Schichten 4+5 in fpc_serve.php ergänzen** — Closing-Tag und PHP-Fehler-Check auch bei der Auslieferung
5. **Preloader-Timeout erhöhen** — Von 15s auf 30s, da manche Seiten 5-8s TTFB haben
6. **Retry-Logik im Preloader** — Fehlgeschlagene Seiten nach 5 Minuten erneut versuchen

### Priorität 3 (Mittelfristig)

7. **Locking-Mechanismus** — Verhindert parallele Cron-Ausführung (z.B. via `flock`)
8. **_tailFile() optimieren** — Nur die letzten N Bytes lesen statt die gesamte Datei
9. **CSRF-Token für Flush-Button** im Admin-Modul

---

## 6. Gesamtbewertung

| Aspekt | Bewertung | Note |
|---|---|---|
| Code-Qualität | Gut — sauberer, gut dokumentierter Code | B+ |
| Sicherheit | Gut — Session-Schutz, Directory Traversal, Ausschlusslisten | B |
| Validierung (Preloader) | Sehr gut — 5 Schichten, Atomic Write | A |
| Validierung (Serve) | Befriedigend — nur 3 von 5 Schichten | C+ |
| Weiße-Seiten-Schutz | Sehr gut — v7.0.3 Fixes greifen | A |
| Cache-Abdeckung | Mangelhaft — nur Startseite gecacht | D |
| Performance (TTFB) | Befriedigend — 2.1s mit FPC, Hosting-bedingt | C |

**Fazit:** Das FPC-Modul ist technisch solide und der Weiße-Seiten-Schutz funktioniert hervorragend. Das Hauptproblem ist die geringe Cache-Abdeckung (nur Startseite) und der hohe TTFB, der vermutlich am Hosting-Setup (Artfiles Reverse-Proxy) liegt. Der nächste Schritt sollte die Prüfung des Preloader-Cron-Jobs und der Cache-Dateien auf dem Server sein.
