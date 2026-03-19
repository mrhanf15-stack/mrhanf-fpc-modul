# Mr. Hanf Full Page Cache — Modified E-Commerce Modul

**Version:** 1.0.0  
**Kompatibilität:** Modified E-Commerce 2.x / 3.x  
**PHP:** 7.4+  
**Autor:** Manus AI für Mr. Hanf (mr-hanf.de)

---

## Was macht dieses Modul?

Dieses Modul implementiert einen **Full-Page-Cache (FPC)** für Modified E-Commerce Shops ohne eine einzige Core-Datei zu verändern. Es nutzt ausschließlich das offizielle **Auto-Include / Extra-Hook-System** von Modified.

**Wirkung:** Der TTFB (Time to First Byte) sinkt für Gäste von ~3 Sekunden auf **unter 0,1 Sekunden**.

---

## Wie funktioniert es?

Das Modul hängt sich über zwei Hooks in den Request-Lifecycle ein:

| Hook-Punkt | Datei | Funktion |
|---|---|---|
| `application_top_begin` | `includes/extra/application_top/application_top_begin/mrhanf_fpc.php` | Prüft ob eine gecachte Version existiert → liefert sie sofort aus (PHP `exit`) |
| `application_bottom_end` | `includes/extra/application_bottom/application_bottom_end/mrhanf_fpc.php` | Speichert das fertig gerenderte HTML als Cache-Datei |

**Gecacht wird nur für:**
- Gäste ohne aktive Session (`MODsid` Cookie nicht gesetzt)
- GET-Requests (keine Formulare)
- Alle Seiten außer: Checkout, Login, Account, Warenkorb, Admin

---

## Installation

### Schritt 1 — Dateien hochladen
Den Inhalt dieses Repositories (alle Ordner) direkt ins **Root-Verzeichnis** des Shops hochladen (per FTP/SFTP). Die Ordnerstruktur muss exakt erhalten bleiben.

### Schritt 2 — Cache-Ordner anlegen
Im Root-Verzeichnis des Shops den Ordner `cache/fpc/` anlegen und die Rechte auf `777` setzen:
```bash
mkdir -p /pfad/zum/shop/cache/fpc
chmod 777 /pfad/zum/shop/cache/fpc
```

### Schritt 3 — Im Admin aktivieren
Im Shop-Backend unter **Module → System-Module** erscheint nun **"Mr. Hanf Full Page Cache"**.
1. Auf **"Installieren"** klicken
2. Auf **"Bearbeiten"** klicken
3. Status auf **`true`** setzen
4. Speichern

---

## Konfiguration

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Status | `true` | Modul aktivieren/deaktivieren |
| Cache Lebensdauer | `86400` | Sekunden (86400 = 24 Stunden) |

---

## Cache leeren

Den Cache leert man am schnellsten per SSH:
```bash
rm -f /pfad/zum/shop/cache/fpc/*.html
```

Oder per Cronjob (empfohlen, täglich um 3:00 Uhr):
```
0 3 * * * find /pfad/zum/shop/cache/fpc/ -name "*.html" -delete
```

---

## Testen

Nach der Aktivierung im Browser-Quelltext der Startseite nach folgendem Kommentar suchen:
```html
<!-- MR-HANF FPC: Cached on 2026-03-19 12:00:00 -->
```

Beim nächsten Aufruf im HTTP-Header prüfen (Browser DevTools → Network → Response Headers):
```
X-MrHanf-Cache: HIT
```

---

## Unterstützte Sprachen

- Deutsch (`lang/german/`)
- Englisch (`lang/english/`)
- Französisch (`lang/french/`)
- Spanisch (`lang/spanish/`)

---

## Dateistruktur

```
mrhanf_fpc_module/
├── README.md
├── admin/
│   └── includes/
│       └── modules/
│           └── system/
│               └── mrhanf_fpc.php          ← Admin-Modul-Klasse
├── includes/
│   └── extra/
│       ├── application_top/
│       │   └── application_top_begin/
│       │       └── mrhanf_fpc.php          ← Cache-Check Hook (Anfang)
│       └── application_bottom/
│           └── application_bottom_end/
│               └── mrhanf_fpc.php          ← Cache-Save Hook (Ende)
└── lang/
    ├── german/modules/system/mrhanf_fpc.php
    ├── english/modules/system/mrhanf_fpc.php
    ├── french/modules/system/mrhanf_fpc.php
    └── spanish/modules/system/mrhanf_fpc.php
```

---

## Changelog

### v1.0.0 (2026-03-19)
- Initiale Version
- FPC für Modified E-Commerce ohne Core-Änderungen
- 4 Sprachen: DE, EN, FR, ES
- Admin-Modul mit konfigurierbarer Cache-Lebensdauer
- Automatische Ausnahme für eingeloggte Kunden und Checkout
