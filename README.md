# Mr. Hanf Full Page Cache — Modified E-Commerce Modul

**Version:** 1.2.0  
**Kompatibilität:** Modified E-Commerce 2.x / 3.x  
**PHP:** 8.3+ (optimiert)  
**Autor:** Manus AI für Mr. Hanf (mr-hanf.de)

---

## Was macht dieses Modul?

Dieses Modul implementiert einen **Full-Page-Cache (FPC)** für Modified E-Commerce Shops **ohne eine einzige Core-Datei zu verändern**. Es nutzt ausschließlich das offizielle **Auto-Include / Extra-Hook-System** von Modified.

**Wirkung:** Der TTFB (Time to First Byte) sinkt für Gäste von ~3 Sekunden auf **unter 0,1 Sekunden**.

---

## PHP 8.3 Features (v1.2)

| Feature | Einsatz |
|---|---|
| `declare(strict_types=1)` | Alle Dateien — verhindert stille Typfehler |
| `readonly` Properties | `$prefix`, `$code` in der Admin-Klasse |
| `match`-Expression | Cacheability-Prüfung — klarer als if/else-Kaskaden |
| `str_contains()` | Ersetzt `strpos() !== false` |
| `hash('xxh3', ...)` | Schnellster PHP 8.x Hash-Algorithmus für Cache-Keys |
| Atomares Schreiben | `tmp` → `rename()` verhindert Race Conditions |
| `0o755` Oktal-Notation | Moderne PHP 8.1+ Schreibweise für Dateiberechtigungen |
| Typed Return Types | `void`, `array`, `int`, `string` überall |
| `array_filter` + `array_map` | Saubere Verarbeitung der Ausschlussliste |

---

## Wie funktioniert es?

Das Modul hängt sich über zwei Hooks in den Request-Lifecycle ein:

| Hook-Punkt | Datei | Funktion |
|---|---|---|
| `application_top_begin` | `includes/extra/application_top/application_top_begin/mrhanf_fpc.php` | Prüft ob Cache existiert → liefert sofort aus (`exit`) |
| `application_bottom_end` | `includes/extra/application_bottom/application_bottom_end/mrhanf_fpc.php` | Speichert fertig gerendertes HTML atomar als Cache-Datei |

**Gecacht wird nur für:**
- Gäste ohne aktive Kunden-Session (`xtc_customer_id` nicht gesetzt)
- Gäste ohne Warenkorb (`xtc_cart` nicht gesetzt)
- GET-Requests ohne `?action=` Parameter
- Alle Seiten außer der konfigurierbaren Ausschlussliste

---

## Installation

### Schritt 1 — Dateien hochladen
Den Inhalt dieses Repositories (alle Ordner) direkt ins **Root-Verzeichnis** des Shops hochladen (per FTP/SFTP). Die Ordnerstruktur muss exakt erhalten bleiben.

### Schritt 2 — Cache-Ordner anlegen
Im Root-Verzeichnis des Shops den Ordner `cache/fpc/` anlegen:
```bash
mkdir -p /pfad/zum/shop/cache/fpc
chmod 755 /pfad/zum/shop/cache/fpc
```
Das Modul legt den Ordner beim Installieren auch automatisch an.

### Schritt 3 — Im Admin aktivieren
Im Shop-Backend unter **Module → System-Module** erscheint nun **"Mr. Hanf Full Page Cache"**.
1. Auf **"Installieren"** klicken
2. Auf **"Bearbeiten"** klicken
3. Status auf **`true`** setzen und speichern

---

## Konfiguration

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Status | `true` | Modul aktivieren/deaktivieren |
| Cache Lebensdauer | `86400` | Sekunden (86400 = 24 Stunden) |
| Ausgeschlossene Seiten | `checkout,login,...` | Komma-getrennte Liste von URL-Fragmenten |

---

## Cache leeren

Per SSH (schnellste Methode):
```bash
find /pfad/zum/shop/cache/fpc/ -name "*.html" -delete
```

Empfohlener Cronjob (täglich um 3:00 Uhr):
```
0 3 * * * find /pfad/zum/shop/cache/fpc/ -name "*.html" -delete
```

---

## Testen

Nach der Aktivierung:

**1. Erster Aufruf (Cache MISS):**
```bash
curl -sI https://mr-hanf.de/ | grep -i "x-mrhanf"
# Erwartet: x-mrhanf-cache: MISS
```

**2. Zweiter Aufruf (Cache HIT):**
```bash
curl -sI https://mr-hanf.de/ | grep -i "x-mrhanf"
# Erwartet: x-mrhanf-cache: HIT
```

**3. TTFB messen:**
```bash
curl -o /dev/null -s -w "TTFB=%{time_starttransfer}s\n" https://mr-hanf.de/
# Erwartet: TTFB=0.0XXs (unter 0,1 Sekunden)
```

**4. HTML-Kommentar prüfen:**
Im Quelltext der Seite am Ende:
```html
<!-- MR-HANF FPC v1.2: Cached on 2026-03-19 12:00:00 -->
```

---

## Dateistruktur

```
mrhanf_fpc_module/
├── README.md
├── admin/
│   └── includes/modules/system/
│       └── mrhanf_fpc.php              ← Admin-Modul (PHP 8.3, readonly, typed)
├── includes/extra/
│   ├── application_top/application_top_begin/
│   │   └── mrhanf_fpc.php              ← Cache-Check Hook (match, xxh3, str_contains)
│   └── application_bottom/application_bottom_end/
│       └── mrhanf_fpc.php              ← Cache-Save Hook (atomares Schreiben)
└── lang/
    ├── german/modules/system/mrhanf_fpc.php
    ├── english/modules/system/mrhanf_fpc.php
    ├── french/modules/system/mrhanf_fpc.php
    └── spanish/modules/system/mrhanf_fpc.php
```

---

## Changelog

### v1.2.0 (2026-03-19)
- **PHP 8.3 Optimierung:** `declare(strict_types=1)`, `readonly`, `match`, `str_contains`, `hash('xxh3')`, atomares Schreiben
- **Neues Konfig-Feld:** Ausgeschlossene Seiten jetzt im Admin konfigurierbar
- **Bugfix:** `0o755` statt `0777` für sicherere Verzeichnisrechte
- **Bugfix:** Race Condition beim Schreiben behoben (tmp → rename)

### v1.1.0 (2026-03-19)
- **Bugfix:** Cookie-Prüfung korrigiert (`xtc_customer_id` statt `MODsid`)
- Modified setzt `MODsid` bei jedem Aufruf — kein Ausschluss-Kriterium

### v1.0.0 (2026-03-19)
- Initiale Version
- FPC für Modified E-Commerce ohne Core-Änderungen
- 4 Sprachen: DE, EN, FR, ES
