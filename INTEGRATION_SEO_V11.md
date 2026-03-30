# SEO Tab v11.0 — Integrations-Anleitung

## Übersicht

Der SEO Tab v11.0 erweitert das FPC Dashboard um ein vollständiges SEO Control Center mit 4 gruppierten Sub-Tab-Kategorien und 17 Sub-Tabs.

## Neue Dateien

| Datei | Typ | Beschreibung |
|-------|-----|-------------|
| `fpc_seo_schema.php` | Backend | Schema.org Scanner und Audit |
| `fpc_seo_cwv.php` | Backend | Core Web Vitals (PageSpeed Insights API) |
| `fpc_seo_indexing.php` | Backend | Google Indexing API Manager |
| `fpc_seo_llms.php` | Backend | llms.txt Generator und AI-Crawler Manager |
| `fpc_seo_extended.php` | Backend | Erweiterungen: hreflang, Content Audit, Internal Links, Meta-Tags, robots.txt, Sitemap |
| `fpc_seo_tab.php` | Frontend | SEO Tab UI mit Sub-Tab Navigation und allen Panels |
| `fpc_seo_ajax.php` | Router | AJAX-Handler der alle SEO-Aktionen routet |
| `fpc_settings_seo_fields.php` | Frontend | Neue Einstellungsfelder im Settings Tab |
| `fpc_seo_cron.php` | Cronjob | Automatische SEO-Scans (Schema, CWV, hreflang, llms.txt, Keywords) |

## Geänderte Dateien

| Datei | Änderung |
|-------|----------|
| `fpc_sistrix.php` | Erweitert um Keywords, Competitor, URL-Analyse (v1.2 → v2.0) |

## Integration in fpc_dashboard.php

### 1. AJAX-Handler einbinden

Am Anfang von `fpc_dashboard.php` (nach den bestehenden includes):

```php
// SEO Tab v11.0 AJAX Handler
require_once __DIR__ . '/fpc_seo_ajax.php';

// AJAX Routing: Wenn action mit 'seo_' beginnt, an SEO Handler delegieren
if (isset($_POST['action']) && strpos($_POST['action'], 'seo_') === 0) {
    handle_seo_ajax($_POST['action'], $settings, $_POST);
    // handle_seo_ajax ruft exit() auf
}
```

### 2. SEO Tab im Tab-Menü

Im HTML-Bereich wo die Haupt-Tabs definiert sind:

```html
<!-- Bestehender SEO Tab Button anpassen -->
<button class="tab-btn" data-tab="seo" onclick="switchTab('seo')">
    <span class="fa fa-search"></span> SEO v11.0
</button>
```

### 3. SEO Tab Content einbinden

Im Tab-Content-Bereich:

```html
<!-- SEO Tab v11.0 -->
<div class="tab-content" id="tab-seo" style="display:none;">
    <?php include __DIR__ . '/fpc_seo_tab.php'; ?>
</div>
```

### 4. Settings Tab erweitern

Im Settings-Bereich (vor dem Speichern-Button):

```html
<?php include __DIR__ . '/fpc_settings_seo_fields.php'; ?>
```

### 5. Settings speichern erweitern

In der Settings-Speichern-Funktion die neuen Felder hinzufügen:

```php
// SEO v11.0 Settings
$new_fields = [
    'google_pagespeed_api_key',
    'indexing_daily_limit',
    'db_host', 'db_name', 'db_user', 'db_pass',
    'webroot',
    'cron_schema_interval', 'cron_cwv_interval',
    'cron_hreflang_interval', 'cron_llms_interval',
    'competitors',
];

foreach ($new_fields as $field) {
    if (isset($_POST[$field])) {
        $settings[$field] = $_POST[$field];
    }
}
```

### 6. DB-Verbindungstest Handler

```php
// In der AJAX-Routing Sektion:
if ($_POST['action'] === 'test_db_connection') {
    header('Content-Type: application/json');
    try {
        $db = new mysqli(
            $_POST['db_host'] ?? 'localhost',
            $_POST['db_user'] ?? '',
            $_POST['db_pass'] ?? '',
            $_POST['db_name'] ?? ''
        );
        if ($db->connect_error) {
            echo json_encode(['ok' => false, 'msg' => $db->connect_error]);
        } else {
            $db->close();
            echo json_encode(['ok' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
```

## Cronjob einrichten

```bash
# Täglicher SEO-Scan um 03:00 Uhr
0 3 * * * php /pfad/zu/fpc_live/fpc_seo_cron.php >> /pfad/zu/logs/seo_cron.log 2>&1
```

Der Cronjob prüft intern die konfigurierten Intervalle (Standard: 14 Tage) und führt nur fällige Scans aus. Der Keyword Monitor wird täglich aktualisiert.

## Verzeichnisstruktur (Cache/Daten)

Folgende Verzeichnisse werden automatisch erstellt:

```
data/fpc/
    seo_cron_state.json          ← Cronjob-Status
cache/fpc/
    schema/                       ← Schema.org Scan-Ergebnisse
    cwv/                          ← Core Web Vitals Ergebnisse
    indexing/                     ← Indexing API Log
    llms/                         ← llms.txt Backups
    seo_extended/                 ← hreflang, Content Audit, Internal Links
```

## Neue Einstellungsfelder

| Feld | Typ | Standard | Beschreibung |
|------|-----|----------|-------------|
| `google_pagespeed_api_key` | String | leer | Google PageSpeed Insights API Key (optional) |
| `indexing_daily_limit` | Integer | 200 | Tägliches Limit für Indexing API |
| `db_host` | String | localhost | Shop-Datenbank Host |
| `db_name` | String | leer | Shop-Datenbank Name |
| `db_user` | String | leer | Shop-Datenbank Benutzer |
| `db_pass` | String | leer | Shop-Datenbank Passwort |
| `webroot` | String | /var/www/html/ | Pfad zum Webroot |
| `cron_schema_interval` | Integer | 14 | Schema.org Scan Intervall (Tage) |
| `cron_cwv_interval` | Integer | 14 | CWV Test Intervall (Tage) |
| `cron_hreflang_interval` | Integer | 14 | hreflang Audit Intervall (Tage) |
| `cron_llms_interval` | Integer | 14 | llms.txt Update Intervall (Tage) |
| `competitors` | Text | linda-seeds.com... | Wettbewerber-Domains (eine pro Zeile) |

## Feature-Übersicht

### Gruppe A — Technisches SEO
1. **Redirects** — Bestehende Implementierung (integriert)
2. **Canonicals** — Bestehende Implementierung (integriert)
3. **hreflang Validator** — Prüft 4 Sprachen (DE, EN, FR, ES), Reziprozität, x-default
4. **robots.txt Editor** — Live-Editor mit Validierung
5. **Sitemap Validator** — XML-Prüfung, tote Links, Duplikate
6. **Schema.org Scanner** — JSON-LD Audit mit Score, Cronjob alle 14 Tage

### Gruppe B — Monitoring & APIs
1. **404 Monitor** — Bestehende Implementierung (integriert)
2. **Auto-Scan** — Bestehende Implementierung (integriert)
3. **Core Web Vitals** — PageSpeed Insights API, Ampel-System, Batch-Test
4. **Indexing API** — URL-Einreichung (einzeln/bulk), Quota, Log
5. **Keyword Monitor** — GSC-basiert, tägliches Tracking

### Gruppe C — KI & Zukunft
1. **KI-Analyse** — Bestehende Implementierung (integriert)
2. **llms.txt Manager** — Generator, Editor, Auto-Update
3. **AI-Crawler Steuerung** — 12 bekannte Bots, Toggle, Empfohlene Konfiguration

### Gruppe D — Content SEO
1. **Meta-Tag Audit** — DB-basiert, SERP Preview, Längen-Prüfung
2. **Content Audit** — Thin Content, Freshness, Wortanzahl-Statistiken
3. **Internal Links** — Verwaiste Seiten, Link-Verteilung, Broken Links
