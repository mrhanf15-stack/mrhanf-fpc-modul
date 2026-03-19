# Mr. Hanf Full Page Cache (FPC) v5.0.0

System-Modul fuer **modified eCommerce Shopsoftware** (v2.0.7.x+, PHP 8.3).

Aktiviert einen extrem schnellen HTML-Cache fuer Gaeste. Reduziert den TTFB von 2-3 Sekunden auf unter 0,1 Sekunden.

## Dateien

| Datei | Beschreibung |
|---|---|
| `admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php` | Modul-Klasse (Admin) |
| `lang/german/extra/admin/mrhanf_fpc.php` | Sprachdatei Deutsch |
| `lang/english/extra/admin/mrhanf_fpc.php` | Sprachdatei Englisch |
| `lang/french/extra/admin/mrhanf_fpc.php` | Sprachdatei Franzoesisch |
| `lang/spanish/extra/admin/mrhanf_fpc.php` | Sprachdatei Spanisch |
| `includes/extra/application_top/application_top_begin/mrhanf_fpc.php` | Cache-Check Hook |
| `includes/extra/application_bottom/application_bottom_end/mrhanf_fpc.php` | Cache-Save Hook |

## Installation

1. Alle Dateien per FTP/SFTP oder SSH in den Shop-Root hochladen
2. Im Admin: **Erweiterungen > Module > System Module**
3. **Mr. Hanf Full Page Cache** auswaehlen und **Installieren** klicken
4. Einstellungen konfigurieren und speichern

### Installation per SSH (von GitHub)

```bash
cd /tmp && rm -rf mrhanf-fpc-modul && git clone https://github.com/mrhanf15-stack/mrhanf-fpc-modul.git

# Dateien kopieren (SHOP_ROOT anpassen!)
SHOP=/home/www/doc/28856/dcp288560004/mr-hanf.de/www

cp /tmp/mrhanf-fpc-modul/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php $SHOP/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php
cp /tmp/mrhanf-fpc-modul/lang/german/extra/admin/mrhanf_fpc.php $SHOP/lang/german/extra/admin/mrhanf_fpc.php
cp /tmp/mrhanf-fpc-modul/lang/english/extra/admin/mrhanf_fpc.php $SHOP/lang/english/extra/admin/mrhanf_fpc.php
cp /tmp/mrhanf-fpc-modul/lang/french/extra/admin/mrhanf_fpc.php $SHOP/lang/french/extra/admin/mrhanf_fpc.php
cp /tmp/mrhanf-fpc-modul/lang/spanish/extra/admin/mrhanf_fpc.php $SHOP/lang/spanish/extra/admin/mrhanf_fpc.php
cp /tmp/mrhanf-fpc-modul/includes/extra/application_top/application_top_begin/mrhanf_fpc.php $SHOP/includes/extra/application_top/application_top_begin/mrhanf_fpc.php
cp /tmp/mrhanf-fpc-modul/includes/extra/application_bottom/application_bottom_end/mrhanf_fpc.php $SHOP/includes/extra/application_bottom/application_bottom_end/mrhanf_fpc.php

rm -rf /tmp/mrhanf-fpc-modul
```

## Deinstallation

1. Im Admin: **Erweiterungen > Module > System Module**
2. **Mr. Hanf Full Page Cache** auswaehlen und **Deinstallieren** klicken
3. Dateien vom Server loeschen

### Manuelle Deinstallation (SQL)

```sql
DELETE FROM configuration WHERE configuration_key LIKE 'MODULE_MRHANF_FPC_%';
```

## Konfiguration

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Modul aktivieren | True | Cache ein-/ausschalten (True/False Dropdown) |
| Cache Lebensdauer | 86400 | Sekunden bis ein Cache-Eintrag erneuert wird (24h) |
| Ausgeschlossene Seiten | checkout,login,... | Kommagetrennte URL-Teile die nicht gecacht werden |
| Sortierreihenfolge | 0 | Reihenfolge im Admin |

## Funktionsweise

**Cache-HIT** (Seite ist im Cache):
- Datei wird direkt aus `cache/fpc/` ausgeliefert
- HTTP-Header: `X-MrHanf-Cache: HIT`
- TTFB: unter 0,1 Sekunden

**Cache-MISS** (Seite ist nicht im Cache):
- Seite wird normal von modified generiert
- Output wird in `cache/fpc/` gespeichert
- HTTP-Header: `X-MrHanf-Cache: MISS`

**Nicht gecacht werden:**
- Eingeloggte Benutzer (Cookie `xtc_customer_id`)
- POST-Requests
- Seiten mit `?action=` Parameter
- Seiten die in "Ausgeschlossene Seiten" konfiguriert sind

### Cache pruefen

```bash
# Erster Aufruf = MISS, zweiter = HIT
curl -sI https://mr-hanf.de/ | grep X-MrHanf-Cache
```

## Bekannter Bug: xtc_db_query() und set_function

### Problem

Die `install()` Methode von modified System-Modulen nutzt normalerweise `xtc_db_query()` fuer die INSERT-Statements. Auf **modified v2.0.7.2 rev 14622** mit **PHP 8.3** auf **Artfiles Shared Hosting** fuehrt `xtc_db_query()` die INSERTs fuer Konfigurationseintraege mit `set_function` **nicht** aus:

```php
// Dieser INSERT wird von xtc_db_query() STILL IGNORIERT:
INSERT INTO configuration (..., set_function) VALUES (..., 'xtc_cfg_select_option(array(\'True\', \'False\'),')

// Dieser INSERT funktioniert (ohne set_function):
INSERT INTO configuration (...) VALUES (...)
```

**Symptom:** Nach dem Klick auf "Installieren" werden nur die Eintraege OHNE `set_function` in die DB geschrieben. Der STATUS-Eintrag (mit `set_function` fuer das True/False-Dropdown) fehlt. Da `check()` den STATUS-Eintrag prueft, gilt das Modul als "nicht installiert".

**Kein Fehler im Log.** Kein PHP-Error, kein SQL-Error — der INSERT wird einfach still uebergangen.

### Ursache (Vermutung)

Modified's `xtc_db_query()` Wrapper (in `includes/functions/database.php`) fuehrt intern ein zusaetzliches Escaping durch. Die Single-Quotes in `xtc_cfg_select_option(array('True', 'False'),` werden von `xtc_db_input()` zu `\'True\', \'False\'` escaped. Wenn `xtc_db_query()` dann nochmals escaped, entsteht ein doppeltes Escaping das den SQL-Befehl ungueltig macht. Der Error-Handler von modified unterdrueckt den resultierenden SQL-Fehler.

### Beweis

Derselbe INSERT funktioniert einwandfrei ueber `mysqli->query()`:

```bash
# Direkt per mysqli — funktioniert:
php -r "$db = new mysqli('host','user','pass','db'); $db->query(\"INSERT INTO configuration (..., set_function) VALUES (..., 'xtc_cfg_select_option(array(\\'True\\', \\'False\\'),')\"); echo $db->affected_rows;"
# Ergebnis: 1 (eingefuegt)
```

### Loesung in v5.0.0

Die `install()` Methode nutzt jetzt eine **direkte mysqli-Verbindung** statt `xtc_db_query()`. Die DB-Zugangsdaten werden aus den modified-Konstanten `DB_SERVER`, `DB_SERVER_USERNAME`, `DB_SERVER_PASSWORD` und `DB_DATABASE` gelesen. Falls diese nicht verfuegbar sind, wird `xtc_db_query()` als Fallback verwendet.

## Changelog

### v5.0.0 (2026-03-19)
- **BUGFIX:** install() nutzt direkte mysqli-Verbindung statt xtc_db_query()
- **BUGFIX:** Alle 4 Konfigurationseintraege werden zuverlaessig geschrieben
- Fallback auf xtc_db_query() wenn DB-Konstanten nicht verfuegbar
- Ausfuehrliche Dokumentation des xtc_db_query() Bugs

### v4.0.0 (2026-03-19)
- Komplett neu geschrieben nach modified Auto-Include Standard
- 1:1 Struktur nach uptain-connect Modul (bewaehrt auf mr-hanf.de)
- Sprachdateien unter `lang/*/extra/admin/` (Auto-Include Hookpoint)
- PHP 8.3 kompatibel

### v2.0.0 (Original)
- Erste Version mit PHP 8.3 Features (declare strict_types, readonly, match)
- Nicht kompatibel mit modified Admin-Framework
