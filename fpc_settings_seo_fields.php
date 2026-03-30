<?php
/**
 * Mr. Hanf FPC - Settings Tab Erweiterung fuer SEO Tab v11.0
 *
 * Neue Einstellungsfelder die im Settings Tab angezeigt werden:
 * - Google PageSpeed Insights API Key (optional)
 * - Google Indexing API Status
 * - Datenbank-Zugangsdaten (fuer Content Audit)
 * - Webroot-Pfad
 * - Cronjob-Intervalle
 *
 * Wird als Include im Settings-Bereich des Dashboards eingebunden.
 *
 * @version   11.0.0
 * @date      2026-03-30
 */
?>

<!-- SEO v11.0 Einstellungen -->
<div class="settings-section" id="settingsSeoV11">
    <div class="settings-section-header" onclick="toggleSettingsSection('seoV11')">
        <h3><span class="fa fa-search-plus"></span> SEO Tab v11.0 — Erweiterte Einstellungen</h3>
        <span class="toggle-icon" id="seoV11Toggle">▼</span>
    </div>

    <div class="settings-section-body" id="seoV11Body" style="display:none;">

        <!-- Google PageSpeed Insights API Key -->
        <div class="settings-group">
            <h4><span class="fa fa-tachometer"></span> Google PageSpeed Insights API</h4>
            <p class="settings-desc">
                Für Core Web Vitals Tests. Der API Key ist kostenlos erstellbar über die
                <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>.
                Aktivieren Sie die "PageSpeed Insights API" im Projekt.
            </p>
            <div class="settings-field">
                <label for="google_pagespeed_api_key">API Key (optional):</label>
                <input type="text" id="google_pagespeed_api_key" name="google_pagespeed_api_key"
                       value="<?php echo htmlspecialchars($settings['google_pagespeed_api_key'] ?? ''); ?>"
                       placeholder="AIzaSy..." class="settings-input" />
                <span class="settings-hint">Ohne API Key werden nur GSC/CrUX-Daten angezeigt.</span>
            </div>
        </div>

        <!-- Google Indexing API -->
        <div class="settings-group">
            <h4><span class="fa fa-upload"></span> Google Indexing API</h4>
            <p class="settings-desc">
                Nutzt denselben Service Account wie GSC. Der Scope
                <code>https://indexing.googleapis.com/</code> muss aktiviert sein.
            </p>
            <div class="settings-field">
                <label>Status:</label>
                <div id="indexingApiStatus">
                    <?php
                    $sa_path = $settings['gsc_service_account_path'] ?? '';
                    if (!empty($sa_path) && file_exists($sa_path)) {
                        echo '<span class="badge badge-success">Service Account vorhanden</span>';
                        echo ' <span class="settings-hint">Pfad: ' . htmlspecialchars($sa_path) . '</span>';
                    } else {
                        echo '<span class="badge badge-warning">Service Account nicht konfiguriert</span>';
                    }
                    ?>
                </div>
            </div>
            <div class="settings-field">
                <label for="indexing_daily_limit">Tägliches Limit:</label>
                <input type="number" id="indexing_daily_limit" name="indexing_daily_limit"
                       value="<?php echo intval($settings['indexing_daily_limit'] ?? 200); ?>"
                       min="1" max="10000" class="settings-input settings-input-sm" />
                <span class="settings-hint">Standard: 200 URLs/Tag</span>
            </div>
        </div>

        <!-- Datenbank-Zugangsdaten -->
        <div class="settings-group">
            <h4><span class="fa fa-database"></span> Shop-Datenbank (für Content Audit & Meta-Tags)</h4>
            <p class="settings-desc">
                Wird für direkte DB-Abfragen bei Content Audit, Meta-Tag Audit und llms.txt Generierung benötigt.
                Falls leer, wird versucht die Zugangsdaten aus <code>includes/configure.php</code> zu lesen.
            </p>
            <div class="settings-field-row">
                <div class="settings-field">
                    <label for="db_host">DB Host:</label>
                    <input type="text" id="db_host" name="db_host"
                           value="<?php echo htmlspecialchars($settings['db_host'] ?? 'localhost'); ?>"
                           placeholder="localhost" class="settings-input" />
                </div>
                <div class="settings-field">
                    <label for="db_name">DB Name:</label>
                    <input type="text" id="db_name" name="db_name"
                           value="<?php echo htmlspecialchars($settings['db_name'] ?? ''); ?>"
                           placeholder="shop_db" class="settings-input" />
                </div>
            </div>
            <div class="settings-field-row">
                <div class="settings-field">
                    <label for="db_user">DB Benutzer:</label>
                    <input type="text" id="db_user" name="db_user"
                           value="<?php echo htmlspecialchars($settings['db_user'] ?? ''); ?>"
                           placeholder="root" class="settings-input" />
                </div>
                <div class="settings-field">
                    <label for="db_pass">DB Passwort:</label>
                    <input type="password" id="db_pass" name="db_pass"
                           value="<?php echo htmlspecialchars($settings['db_pass'] ?? ''); ?>"
                           placeholder="••••••••" class="settings-input" />
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="testDbConnection()">
                <span class="fa fa-plug"></span> Verbindung testen
            </button>
            <span id="dbTestResult"></span>
        </div>

        <!-- Webroot Pfad -->
        <div class="settings-group">
            <h4><span class="fa fa-folder-open"></span> Webroot-Pfad</h4>
            <p class="settings-desc">
                Pfad zum Webroot-Verzeichnis des Shops. Wird für robots.txt, llms.txt und Sitemap-Zugriff benötigt.
            </p>
            <div class="settings-field">
                <label for="webroot">Webroot:</label>
                <input type="text" id="webroot" name="webroot"
                       value="<?php echo htmlspecialchars($settings['webroot'] ?? '/var/www/html/'); ?>"
                       placeholder="/var/www/html/" class="settings-input" />
            </div>
        </div>

        <!-- Cronjob-Konfiguration -->
        <div class="settings-group">
            <h4><span class="fa fa-clock-o"></span> Cronjob-Intervalle (SEO Scans)</h4>
            <p class="settings-desc">
                Automatische Scans laufen als Cronjob. Empfohlen: alle 14 Tage.
            </p>
            <div class="settings-field-row">
                <div class="settings-field">
                    <label for="cron_schema_interval">Schema.org Scan:</label>
                    <select id="cron_schema_interval" name="cron_schema_interval" class="settings-select">
                        <option value="7" <?php echo ($settings['cron_schema_interval'] ?? 14) == 7 ? 'selected' : ''; ?>>Alle 7 Tage</option>
                        <option value="14" <?php echo ($settings['cron_schema_interval'] ?? 14) == 14 ? 'selected' : ''; ?>>Alle 14 Tage</option>
                        <option value="30" <?php echo ($settings['cron_schema_interval'] ?? 14) == 30 ? 'selected' : ''; ?>>Alle 30 Tage</option>
                    </select>
                </div>
                <div class="settings-field">
                    <label for="cron_cwv_interval">Core Web Vitals:</label>
                    <select id="cron_cwv_interval" name="cron_cwv_interval" class="settings-select">
                        <option value="7" <?php echo ($settings['cron_cwv_interval'] ?? 14) == 7 ? 'selected' : ''; ?>>Alle 7 Tage</option>
                        <option value="14" <?php echo ($settings['cron_cwv_interval'] ?? 14) == 14 ? 'selected' : ''; ?>>Alle 14 Tage</option>
                        <option value="30" <?php echo ($settings['cron_cwv_interval'] ?? 14) == 30 ? 'selected' : ''; ?>>Alle 30 Tage</option>
                    </select>
                </div>
            </div>
            <div class="settings-field-row">
                <div class="settings-field">
                    <label for="cron_hreflang_interval">hreflang Audit:</label>
                    <select id="cron_hreflang_interval" name="cron_hreflang_interval" class="settings-select">
                        <option value="7" <?php echo ($settings['cron_hreflang_interval'] ?? 14) == 7 ? 'selected' : ''; ?>>Alle 7 Tage</option>
                        <option value="14" <?php echo ($settings['cron_hreflang_interval'] ?? 14) == 14 ? 'selected' : ''; ?>>Alle 14 Tage</option>
                        <option value="30" <?php echo ($settings['cron_hreflang_interval'] ?? 14) == 30 ? 'selected' : ''; ?>>Alle 30 Tage</option>
                    </select>
                </div>
                <div class="settings-field">
                    <label for="cron_llms_interval">llms.txt Update:</label>
                    <select id="cron_llms_interval" name="cron_llms_interval" class="settings-select">
                        <option value="7" <?php echo ($settings['cron_llms_interval'] ?? 14) == 7 ? 'selected' : ''; ?>>Alle 7 Tage</option>
                        <option value="14" <?php echo ($settings['cron_llms_interval'] ?? 14) == 14 ? 'selected' : ''; ?>>Alle 14 Tage</option>
                        <option value="30" <?php echo ($settings['cron_llms_interval'] ?? 14) == 30 ? 'selected' : ''; ?>>Alle 30 Tage</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Wettbewerber -->
        <div class="settings-group">
            <h4><span class="fa fa-users"></span> Wettbewerber (für Sistrix Vergleich)</h4>
            <p class="settings-desc">
                Domains der Wettbewerber für den Sichtbarkeits-Vergleich. Eine Domain pro Zeile.
            </p>
            <div class="settings-field">
                <label for="competitors">Wettbewerber-Domains:</label>
                <textarea id="competitors" name="competitors" class="settings-textarea" rows="4"
                          placeholder="linda-seeds.com&#10;sensiseeds.com&#10;royalqueenseeds.de&#10;zamnesia.com"><?php
                    echo htmlspecialchars($settings['competitors'] ?? "linda-seeds.com\nsensiseeds.com\nroyalqueenseeds.de\nzamnesia.com");
                ?></textarea>
            </div>
        </div>

    </div>
</div>

<style>
/* Settings Erweiterung Styles */
.settings-section {
    margin-bottom: 16px;
    border: 1px solid var(--fpc-border, #2d3139);
    border-radius: 10px;
    overflow: hidden;
}

.settings-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: var(--fpc-bg-secondary, #1a1d23);
    cursor: pointer;
    user-select: none;
}

.settings-section-header h3 {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--fpc-text-primary, #e4e6ea);
    display: flex;
    align-items: center;
    gap: 8px;
}

.settings-section-body {
    padding: 16px;
}

.settings-group {
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--fpc-border-light, rgba(45,49,57,0.5));
}

.settings-group:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.settings-group h4 {
    margin: 0 0 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--fpc-text-primary, #e4e6ea);
    display: flex;
    align-items: center;
    gap: 6px;
}

.settings-desc {
    font-size: 12px;
    color: var(--fpc-text-secondary, #8b8f98);
    margin: 0 0 12px;
    line-height: 1.5;
}

.settings-desc a {
    color: var(--fpc-accent, #3b82f6);
}

.settings-desc code {
    background: var(--fpc-bg-tertiary, #2d3139);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 11px;
}

.settings-field {
    margin-bottom: 10px;
}

.settings-field label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--fpc-text-secondary, #8b8f98);
    margin-bottom: 4px;
}

.settings-input,
.settings-select,
.settings-textarea {
    width: 100%;
    padding: 8px 12px;
    background: var(--fpc-bg-primary, #12141a);
    border: 1px solid var(--fpc-border, #2d3139);
    border-radius: 6px;
    color: var(--fpc-text-primary, #e4e6ea);
    font-size: 13px;
    font-family: inherit;
}

.settings-input:focus,
.settings-select:focus,
.settings-textarea:focus {
    outline: none;
    border-color: var(--fpc-accent, #3b82f6);
}

.settings-input-sm {
    max-width: 120px;
}

.settings-hint {
    display: block;
    font-size: 11px;
    color: var(--fpc-text-tertiary, #6b7080);
    margin-top: 3px;
}

.settings-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.badge-success {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.badge-warning {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
}

.btn-outline {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border: 1px solid var(--fpc-border, #2d3139);
    border-radius: 6px;
    background: transparent;
    color: var(--fpc-text-primary, #e4e6ea);
    font-size: 12px;
    cursor: pointer;
    transition: all 0.15s;
}

.btn-outline:hover {
    background: var(--fpc-bg-hover, #252830);
    border-color: var(--fpc-border-hover, #3d4149);
}
</style>

<script>
function toggleSettingsSection(id) {
    const body = document.getElementById(id + 'Body');
    const toggle = document.getElementById(id + 'Toggle');
    if (body.style.display === 'none') {
        body.style.display = 'block';
        toggle.textContent = '▲';
    } else {
        body.style.display = 'none';
        toggle.textContent = '▼';
    }
}

function testDbConnection() {
    const host = document.getElementById('db_host').value;
    const user = document.getElementById('db_user').value;
    const pass = document.getElementById('db_pass').value;
    const name = document.getElementById('db_name').value;
    const result = document.getElementById('dbTestResult');

    result.innerHTML = '<span style="color:var(--fpc-text-secondary);">Teste...</span>';

    const formData = new FormData();
    formData.append('action', 'test_db_connection');
    formData.append('db_host', host);
    formData.append('db_user', user);
    formData.append('db_pass', pass);
    formData.append('db_name', name);

    fetch(window.FPC_AJAX_URL || 'fpc_dashboard.php', {
        method: 'POST',
        body: formData,
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            result.innerHTML = '<span class="badge badge-success">✅ Verbindung erfolgreich</span>';
        } else {
            result.innerHTML = '<span class="badge badge-warning">❌ ' + (data.msg || 'Fehler') + '</span>';
        }
    })
    .catch(() => {
        result.innerHTML = '<span class="badge badge-warning">❌ Verbindungstest fehlgeschlagen</span>';
    });
}
</script>
