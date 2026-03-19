# Mr. Hanf Full Page Cache (FPC) Modul v3.0.0

Extrem schneller HTML-Cache fuer Gaeste im modified eCommerce Shopsystem.
Reduziert den TTFB (Time to First Byte) von 2-3 Sekunden auf unter 0,1 Sekunden.

## Funktionsweise

Das Modul greift in die Hook-Punkte des modified-Systems ein:

- **application_top_begin**: Prueft ob ein gueltiger Cache existiert. Bei Cache-HIT wird die HTML-Datei sofort ausgeliefert und der PHP-Prozess beendet. Bei Cache-MISS wird ob_start() gestartet.
- **application_bottom_end**: Faengt den HTML-Output ab, speichert ihn atomar als Cache-Datei in /cache/fpc/ und liefert ihn an den Besucher aus.

## Sicherheitsmechanismen

- Nur GET-Requests werden gecacht
- Eingeloggte Benutzer sehen immer die Live-Seite
- URLs mit ?action=... werden nicht gecacht
- Konfigurierbare Ausschlussliste fuer sensible Seiten

## Dateistruktur

```
admin/includes/modules/system/mrhanf_fpc.php
lang/german/modules/system/mrhanf_fpc.php
lang/english/modules/system/mrhanf_fpc.php
includes/extra/application_top/application_top_begin/mrhanf_fpc.php
includes/extra/application_bottom/application_bottom_end/mrhanf_fpc.php
```

## Installation

1. Alle Dateien per FTP/SFTP in den Shop-Root hochladen
2. Im Admin unter Erweiterungen > Module > System Module das Modul installieren
3. Einstellungen konfigurieren

## Deinstallation

Falls das Modul nicht ueber den Admin deinstalliert werden kann:

```sql
DELETE FROM configuration WHERE configuration_key LIKE 'MODULE_MRHANF_FPC_%';
```

## HTTP-Header zur Diagnose

- X-MrHanf-Cache: HIT — Seite aus Cache
- X-MrHanf-Cache: MISS — Seite neu generiert
- X-MrHanf-Cache-Age: [Sekunden]s — Alter der Cache-Datei

## Changelog

### v3.0.0 (2026-03-19)
- Komplett neu geschrieben fuer maximale Kompatibilitaet
- Sprachdatei wird vom Modul selbst geladen (dreifacher Fallback)
- PHP 7.4+ kompatibel (keine PHP 8.x-only Features)
- Klassenstruktur nach modified-Standard (wie admin_log.php)
