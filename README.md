# Mr. Hanf Full Page Cache (FPC) v4.0.0

System-Modul fuer **modified eCommerce Shopsoftware** (v2.0.7.x+).

Aktiviert einen extrem schnellen HTML-Cache fuer Gaeste. Reduziert den TTFB von 2-3 Sekunden auf unter 0,1 Sekunden.

## Dateien

```
admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php
lang/german/extra/admin/mrhanf_fpc.php
lang/english/extra/admin/mrhanf_fpc.php
lang/french/extra/admin/mrhanf_fpc.php
lang/spanish/extra/admin/mrhanf_fpc.php
includes/extra/application_top/application_top_begin/mrhanf_fpc.php
includes/extra/application_bottom/application_bottom_end/mrhanf_fpc.php
```

## Installation

1. Alle Dateien per FTP/SFTP in den Shop-Root hochladen
2. Im Admin: Erweiterungen > Module > System Module
3. Mr. Hanf Full Page Cache auswaehlen und Installieren klicken

## Changelog

### v4.0.0 (2026-03-19)
- Komplett neu geschrieben nach modified Auto-Include Standard
- 1:1 Struktur nach uptain-connect Modul
- Sprachdateien unter lang/*/extra/admin/ (Auto-Include Hookpoint)
- PHP 8.3 kompatibel
