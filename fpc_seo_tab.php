<?php
/**
 * Mr. Hanf FPC - SEO Tab v11.0 Frontend
 *
 * Vollstaendiges SEO Control Center mit 4 gruppierten Sub-Tab-Kategorien:
 *   A) Technisches SEO: Redirects, Canonicals, hreflang, robots.txt, Sitemap, Schema.org
 *   B) Monitoring & APIs: 404 Monitor, Auto-Scan, Core Web Vitals, Indexing API, Keyword Monitor
 *   C) KI & Zukunft: KI-Analyse, llms.txt, AI-Crawler
 *   D) Content SEO: Meta-Tags, Content Audit, Internal Links
 *
 * Wird als Include in fpc_dashboard.php eingebunden.
 *
 * @version   11.0.0
 * @date      2026-03-30
 */
?>

<!-- SEO Tab v11.0 Styles -->
<style>
/* ============================================================
   SEO TAB v11.0 — Sub-Tab Navigation
   ============================================================ */
.seo-tab-container {
    padding: 0;
}

/* Gruppen-Navigation */
.seo-group-nav {
    display: flex;
    gap: 4px;
    padding: 12px 16px;
    background: var(--fpc-bg-secondary, #1a1d23);
    border-bottom: 1px solid var(--fpc-border, #2d3139);
    flex-wrap: wrap;
}

.seo-group-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1px solid var(--fpc-border, #2d3139);
    border-radius: 8px;
    background: transparent;
    color: var(--fpc-text-secondary, #8b8f98);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.seo-group-btn:hover {
    background: var(--fpc-bg-hover, #252830);
    color: var(--fpc-text-primary, #e4e6ea);
    border-color: var(--fpc-border-hover, #3d4149);
}

.seo-group-btn.active {
    background: var(--fpc-accent-bg, rgba(59,130,246,0.12));
    color: var(--fpc-accent, #3b82f6);
    border-color: var(--fpc-accent, #3b82f6);
}

.seo-group-btn .group-icon {
    font-size: 14px;
}

.seo-group-btn .group-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 9px;
    background: var(--fpc-bg-tertiary, #2d3139);
    color: var(--fpc-text-tertiary, #6b7080);
    font-size: 10px;
    font-weight: 600;
}

.seo-group-btn.active .group-badge {
    background: var(--fpc-accent, #3b82f6);
    color: #fff;
}

/* Sub-Tab Navigation innerhalb einer Gruppe */
.seo-subtab-nav {
    display: flex;
    gap: 2px;
    padding: 8px 16px;
    background: var(--fpc-bg-primary, #12141a);
    border-bottom: 1px solid var(--fpc-border, #2d3139);
    overflow-x: auto;
}

.seo-subtab-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--fpc-text-secondary, #8b8f98);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
}

.seo-subtab-btn:hover {
    background: var(--fpc-bg-hover, #252830);
    color: var(--fpc-text-primary, #e4e6ea);
}

.seo-subtab-btn.active {
    background: var(--fpc-bg-hover, #252830);
    color: var(--fpc-accent, #3b82f6);
}

.seo-subtab-btn .subtab-icon {
    font-size: 12px;
    opacity: 0.7;
}

.seo-subtab-btn.active .subtab-icon {
    opacity: 1;
}

/* Content-Bereich */
.seo-content {
    padding: 16px;
}

.seo-panel {
    display: none;
}

.seo-panel.active {
    display: block;
}

/* ============================================================
   SEO DASHBOARD OVERVIEW (Startseite)
   ============================================================ */
.seo-overview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.seo-kpi-card {
    background: var(--fpc-bg-secondary, #1a1d23);
    border: 1px solid var(--fpc-border, #2d3139);
    border-radius: 10px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.seo-kpi-card .kpi-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.seo-kpi-card .kpi-label {
    font-size: 12px;
    color: var(--fpc-text-secondary, #8b8f98);
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.seo-kpi-card .kpi-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.seo-kpi-card .kpi-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--fpc-text-primary, #e4e6ea);
    line-height: 1;
}

.seo-kpi-card .kpi-trend {
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.kpi-trend.up { color: #22c55e; }
.kpi-trend.down { color: #ef4444; }
.kpi-trend.neutral { color: var(--fpc-text-secondary, #8b8f98); }

/* Score-Ring */
.seo-score-ring {
    width: 80px;
    height: 80px;
    position: relative;
}

.seo-score-ring svg {
    width: 100%;
    height: 100%;
    transform: rotate(-90deg);
}

.seo-score-ring .ring-bg {
    fill: none;
    stroke: var(--fpc-bg-tertiary, #2d3139);
    stroke-width: 6;
}

.seo-score-ring .ring-fill {
    fill: none;
    stroke-width: 6;
    stroke-linecap: round;
    transition: stroke-dashoffset 1s ease;
}

.seo-score-ring .ring-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 18px;
    font-weight: 700;
    color: var(--fpc-text-primary, #e4e6ea);
}

/* Ampel-System fuer CWV */
.cwv-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.cwv-badge.good {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.cwv-badge.needs-improvement {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
}

.cwv-badge.poor {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

/* Tabellen im SEO Tab */
.seo-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.seo-table thead th {
    padding: 8px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--fpc-text-secondary, #8b8f98);
    border-bottom: 1px solid var(--fpc-border, #2d3139);
    background: var(--fpc-bg-secondary, #1a1d23);
}

.seo-table tbody td {
    padding: 8px 12px;
    border-bottom: 1px solid var(--fpc-border-light, rgba(45,49,57,0.5));
    color: var(--fpc-text-primary, #e4e6ea);
}

.seo-table tbody tr:hover {
    background: var(--fpc-bg-hover, #252830);
}

/* Progress Bars */
.seo-progress {
    height: 6px;
    background: var(--fpc-bg-tertiary, #2d3139);
    border-radius: 3px;
    overflow: hidden;
}

.seo-progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.6s ease;
}

.seo-progress-fill.green { background: #22c55e; }
.seo-progress-fill.yellow { background: #f59e0b; }
.seo-progress-fill.red { background: #ef4444; }
.seo-progress-fill.blue { background: #3b82f6; }

/* Sektion-Header */
.seo-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--fpc-border, #2d3139);
}

.seo-section-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--fpc-text-primary, #e4e6ea);
    display: flex;
    align-items: center;
    gap: 8px;
}

.seo-section-title .section-icon {
    font-size: 18px;
    opacity: 0.7;
}

/* Action Buttons */
.seo-action-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 6px;
    border: 1px solid var(--fpc-border, #2d3139);
    background: var(--fpc-bg-secondary, #1a1d23);
    color: var(--fpc-text-primary, #e4e6ea);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
}

.seo-action-btn:hover {
    background: var(--fpc-bg-hover, #252830);
    border-color: var(--fpc-border-hover, #3d4149);
}

.seo-action-btn.primary {
    background: var(--fpc-accent, #3b82f6);
    border-color: var(--fpc-accent, #3b82f6);
    color: #fff;
}

.seo-action-btn.primary:hover {
    background: #2563eb;
}

.seo-action-btn.danger {
    color: #ef4444;
    border-color: rgba(239,68,68,0.3);
}

.seo-action-btn.success {
    color: #22c55e;
    border-color: rgba(34,197,94,0.3);
}

/* Info-Box */
.seo-info-box {
    padding: 12px 16px;
    border-radius: 8px;
    border: 1px solid;
    margin-bottom: 16px;
    font-size: 13px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.seo-info-box.info {
    background: rgba(59,130,246,0.08);
    border-color: rgba(59,130,246,0.2);
    color: #93c5fd;
}

.seo-info-box.warning {
    background: rgba(245,158,11,0.08);
    border-color: rgba(245,158,11,0.2);
    color: #fcd34d;
}

.seo-info-box.success {
    background: rgba(34,197,94,0.08);
    border-color: rgba(34,197,94,0.2);
    color: #86efac;
}

.seo-info-box.error {
    background: rgba(239,68,68,0.08);
    border-color: rgba(239,68,68,0.2);
    color: #fca5a5;
}

/* Editor (fuer robots.txt, llms.txt) */
.seo-editor {
    width: 100%;
    min-height: 300px;
    padding: 12px;
    background: var(--fpc-bg-primary, #12141a);
    border: 1px solid var(--fpc-border, #2d3139);
    border-radius: 8px;
    color: var(--fpc-text-primary, #e4e6ea);
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: 13px;
    line-height: 1.6;
    resize: vertical;
}

.seo-editor:focus {
    outline: none;
    border-color: var(--fpc-accent, #3b82f6);
}

/* SERP Preview */
.serp-preview {
    background: #fff;
    border-radius: 8px;
    padding: 16px;
    max-width: 600px;
    font-family: Arial, sans-serif;
}

.serp-preview .serp-title {
    font-size: 20px;
    color: #1a0dab;
    line-height: 1.3;
    margin-bottom: 4px;
    cursor: pointer;
}

.serp-preview .serp-url {
    font-size: 14px;
    color: #006621;
    margin-bottom: 4px;
}

.serp-preview .serp-desc {
    font-size: 14px;
    color: #545454;
    line-height: 1.5;
}

.serp-preview .serp-char-count {
    font-size: 11px;
    margin-top: 8px;
}

.serp-char-count .ok { color: #22c55e; }
.serp-char-count .warn { color: #f59e0b; }
.serp-char-count .bad { color: #ef4444; }

/* Donut Chart Container */
.seo-chart-container {
    background: var(--fpc-bg-secondary, #1a1d23);
    border: 1px solid var(--fpc-border, #2d3139);
    border-radius: 10px;
    padding: 16px;
}

/* Responsive */
@media (max-width: 768px) {
    .seo-group-nav {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    .seo-overview-grid {
        grid-template-columns: 1fr 1fr;
    }
}

/* Loading Spinner */
.seo-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    color: var(--fpc-text-secondary, #8b8f98);
    font-size: 13px;
    gap: 8px;
}

.seo-loading .spinner {
    width: 20px;
    height: 20px;
    border: 2px solid var(--fpc-border, #2d3139);
    border-top-color: var(--fpc-accent, #3b82f6);
    border-radius: 50%;
    animation: seo-spin 0.6s linear infinite;
}

@keyframes seo-spin {
    to { transform: rotate(360deg); }
}

/* Crawler Status Grid */
.crawler-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 10px;
}

.crawler-card {
    background: var(--fpc-bg-secondary, #1a1d23);
    border: 1px solid var(--fpc-border, #2d3139);
    border-radius: 8px;
    padding: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.crawler-card .crawler-info {
    flex: 1;
}

.crawler-card .crawler-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--fpc-text-primary, #e4e6ea);
}

.crawler-card .crawler-desc {
    font-size: 11px;
    color: var(--fpc-text-secondary, #8b8f98);
    margin-top: 2px;
}

.crawler-card .crawler-impact {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 3px;
    margin-top: 4px;
    display: inline-block;
}

.crawler-impact.hoch, .crawler-impact.sehr-hoch {
    background: rgba(239,68,68,0.12);
    color: #ef4444;
}

.crawler-impact.mittel {
    background: rgba(245,158,11,0.12);
    color: #f59e0b;
}

.crawler-impact.niedrig {
    background: rgba(107,114,128,0.12);
    color: #6b7280;
}

/* Toggle Switch */
.seo-toggle {
    position: relative;
    width: 40px;
    height: 22px;
    flex-shrink: 0;
}

.seo-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.seo-toggle .toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: var(--fpc-bg-tertiary, #2d3139);
    border-radius: 11px;
    transition: 0.2s;
}

.seo-toggle .toggle-slider:before {
    content: '';
    position: absolute;
    height: 16px;
    width: 16px;
    left: 3px;
    bottom: 3px;
    background: #fff;
    border-radius: 50%;
    transition: 0.2s;
}

.seo-toggle input:checked + .toggle-slider {
    background: #22c55e;
}

.seo-toggle input:checked + .toggle-slider:before {
    transform: translateX(18px);
}
</style>

<!-- SEO Tab v11.0 HTML -->
<div class="seo-tab-container" id="seoTabContainer">

    <!-- Gruppen-Navigation -->
    <div class="seo-group-nav">
        <button class="seo-group-btn active" data-group="overview" onclick="seoSwitchGroup('overview')">
            <span class="group-icon">📊</span>
            SEO Dashboard
        </button>
        <button class="seo-group-btn" data-group="technical" onclick="seoSwitchGroup('technical')">
            <span class="group-icon">⚙️</span>
            Technisches SEO
            <span class="group-badge">6</span>
        </button>
        <button class="seo-group-btn" data-group="monitoring" onclick="seoSwitchGroup('monitoring')">
            <span class="group-icon">📡</span>
            Monitoring & APIs
            <span class="group-badge">5</span>
        </button>
        <button class="seo-group-btn" data-group="ai" onclick="seoSwitchGroup('ai')">
            <span class="group-icon">🤖</span>
            KI & Zukunft
            <span class="group-badge">3</span>
        </button>
        <button class="seo-group-btn" data-group="content" onclick="seoSwitchGroup('content')">
            <span class="group-icon">📝</span>
            Content SEO
            <span class="group-badge">3</span>
        </button>
    </div>

    <!-- Sub-Tab Navigation (dynamisch pro Gruppe) -->
    <div class="seo-subtab-nav" id="seoSubtabNav">
        <!-- Wird per JS gefuellt -->
    </div>

    <!-- Content-Bereich -->
    <div class="seo-content" id="seoContent">
        <!-- Wird per JS gefuellt -->
    </div>
</div>

<!-- SEO Tab v11.0 JavaScript -->
<script>
(function() {
    'use strict';

    // ============================================================
    // KONFIGURATION: Gruppen und Sub-Tabs
    // ============================================================
    const SEO_GROUPS = {
        overview: {
            label: 'SEO Dashboard',
            icon: '📊',
            subtabs: [
                { id: 'dashboard', label: 'Übersicht', icon: '📊' }
            ]
        },
        technical: {
            label: 'Technisches SEO',
            icon: '⚙️',
            subtabs: [
                { id: 'redirects', label: 'Redirects', icon: '↪️' },
                { id: 'canonicals', label: 'Canonicals', icon: '🔗' },
                { id: 'hreflang', label: 'hreflang', icon: '🌐' },
                { id: 'robotstxt', label: 'robots.txt', icon: '🤖' },
                { id: 'sitemap', label: 'Sitemap', icon: '🗺️' },
                { id: 'schema', label: 'Schema.org', icon: '📋' },
            ]
        },
        monitoring: {
            label: 'Monitoring & APIs',
            icon: '📡',
            subtabs: [
                { id: 'monitor404', label: '404 Monitor', icon: '🚫' },
                { id: 'autoscan', label: 'Auto-Scan', icon: '🔍' },
                { id: 'cwv', label: 'Core Web Vitals', icon: '⚡' },
                { id: 'indexing', label: 'Indexing API', icon: '📤' },
                { id: 'keywords', label: 'Keyword Monitor', icon: '🔑' },
            ]
        },
        ai: {
            label: 'KI & Zukunft',
            icon: '🤖',
            subtabs: [
                { id: 'aianalysis', label: 'KI-Analyse', icon: '🧠' },
                { id: 'llmstxt', label: 'llms.txt', icon: '📄' },
                { id: 'aicrawler', label: 'AI-Crawler', icon: '🕷️' },
            ]
        },
        content: {
            label: 'Content SEO',
            icon: '📝',
            subtabs: [
                { id: 'metatags', label: 'Meta-Tags', icon: '🏷️' },
                { id: 'contentaudit', label: 'Content Audit', icon: '📊' },
                { id: 'internallinks', label: 'Internal Links', icon: '🔗' },
            ]
        }
    };

    let currentGroup = 'overview';
    let currentSubtab = 'dashboard';

    // ============================================================
    // NAVIGATION
    // ============================================================
    window.seoSwitchGroup = function(group) {
        currentGroup = group;
        const firstSubtab = SEO_GROUPS[group].subtabs[0].id;
        currentSubtab = firstSubtab;

        // Gruppen-Buttons aktualisieren
        document.querySelectorAll('.seo-group-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.group === group);
        });

        // Sub-Tabs rendern
        renderSubtabs(group);

        // Content laden
        loadSubtabContent(firstSubtab);
    };

    window.seoSwitchSubtab = function(subtab) {
        currentSubtab = subtab;

        // Sub-Tab Buttons aktualisieren
        document.querySelectorAll('.seo-subtab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.subtab === subtab);
        });

        // Content laden
        loadSubtabContent(subtab);
    };

    function renderSubtabs(group) {
        const nav = document.getElementById('seoSubtabNav');
        const subtabs = SEO_GROUPS[group].subtabs;

        if (group === 'overview') {
            nav.style.display = 'none';
            return;
        }

        nav.style.display = 'flex';
        nav.innerHTML = subtabs.map(st =>
            `<button class="seo-subtab-btn ${st.id === currentSubtab ? 'active' : ''}"
                     data-subtab="${st.id}"
                     onclick="seoSwitchSubtab('${st.id}')">
                <span class="subtab-icon">${st.icon}</span>
                ${st.label}
            </button>`
        ).join('');
    }

    // ============================================================
    // CONTENT LOADER
    // ============================================================
    function loadSubtabContent(subtab) {
        const container = document.getElementById('seoContent');

        switch(subtab) {
            case 'dashboard':
                container.innerHTML = renderDashboard();
                loadDashboardData();
                break;
            case 'redirects':
                container.innerHTML = '<div id="seoRedirectsPanel"></div>';
                if (typeof loadSeoRedirects === 'function') loadSeoRedirects();
                else container.innerHTML = renderExistingPanel('redirects');
                break;
            case 'canonicals':
                container.innerHTML = '<div id="seoCanonicalsPanel"></div>';
                if (typeof loadSeoCanonicalsUI === 'function') loadSeoCanonicalsUI();
                else container.innerHTML = renderExistingPanel('canonicals');
                break;
            case 'hreflang':
                container.innerHTML = renderHreflangPanel();
                break;
            case 'robotstxt':
                container.innerHTML = renderRobotsTxtPanel();
                loadRobotsTxt();
                break;
            case 'sitemap':
                container.innerHTML = renderSitemapPanel();
                break;
            case 'schema':
                container.innerHTML = renderSchemaPanel();
                loadSchemaStats();
                break;
            case 'monitor404':
                container.innerHTML = '<div id="seo404Panel"></div>';
                if (typeof loadSeo404Log === 'function') loadSeo404Log();
                else container.innerHTML = renderExistingPanel('404monitor');
                break;
            case 'autoscan':
                container.innerHTML = '<div id="seoAutoScanPanel"></div>';
                if (typeof loadSeoAutoScan === 'function') loadSeoAutoScan();
                else container.innerHTML = renderExistingPanel('autoscan');
                break;
            case 'cwv':
                container.innerHTML = renderCwvPanel();
                loadCwvData();
                break;
            case 'indexing':
                container.innerHTML = renderIndexingPanel();
                loadIndexingData();
                break;
            case 'keywords':
                container.innerHTML = renderKeywordPanel();
                loadKeywordData();
                break;
            case 'aianalysis':
                container.innerHTML = '<div id="seoAiPanel"></div>';
                if (typeof loadSeoAiAnalysis === 'function') loadSeoAiAnalysis();
                else container.innerHTML = renderExistingPanel('ai');
                break;
            case 'llmstxt':
                container.innerHTML = renderLlmsTxtPanel();
                loadLlmsTxt();
                break;
            case 'aicrawler':
                container.innerHTML = renderAiCrawlerPanel();
                loadAiCrawlerStatus();
                break;
            case 'metatags':
                container.innerHTML = renderMetaTagsPanel();
                loadMetaTagAudit();
                break;
            case 'contentaudit':
                container.innerHTML = renderContentAuditPanel();
                loadContentAudit();
                break;
            case 'internallinks':
                container.innerHTML = renderInternalLinksPanel();
                loadInternalLinks();
                break;
            default:
                container.innerHTML = `<div class="seo-info-box info">Dieser Bereich wird geladen...</div>`;
        }
    }

    // Platzhalter fuer bestehende Panels (Redirects, Canonicals, 404, Auto-Scan, KI)
    function renderExistingPanel(type) {
        return `<div class="seo-info-box info">
            <span>ℹ️</span>
            <div>Dieser Bereich nutzt die bestehende ${type}-Implementierung. Die Funktionen werden beim Laden automatisch integriert.</div>
        </div>`;
    }

    // ============================================================
    // SEO DASHBOARD OVERVIEW
    // ============================================================
    function renderDashboard() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title">
                <span class="section-icon">📊</span>
                SEO Dashboard — Gesamtübersicht
            </div>
            <div>
                <button class="seo-action-btn" onclick="seoRefreshDashboard()">
                    🔄 Aktualisieren
                </button>
            </div>
        </div>

        <div class="seo-overview-grid" id="seoDashboardKpis">
            <div class="seo-loading"><div class="spinner"></div> Dashboard wird geladen...</div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-top:16px;">
            <div class="seo-chart-container">
                <h4 style="margin:0 0 12px 0; font-size:14px; color:var(--fpc-text-primary);">Health Score Verlauf</h4>
                <canvas id="seoHealthChart" height="200"></canvas>
            </div>
            <div class="seo-chart-container">
                <h4 style="margin:0 0 12px 0; font-size:14px; color:var(--fpc-text-primary);">Schnell-Aktionen</h4>
                <div id="seoQuickActions" style="display:flex; flex-direction:column; gap:8px;">
                    <button class="seo-action-btn" onclick="seoSwitchGroup('technical'); seoSwitchSubtab('schema');">📋 Schema.org Scan starten</button>
                    <button class="seo-action-btn" onclick="seoSwitchGroup('monitoring'); seoSwitchSubtab('cwv');">⚡ Core Web Vitals testen</button>
                    <button class="seo-action-btn" onclick="seoSwitchGroup('ai'); seoSwitchSubtab('llmstxt');">📄 llms.txt generieren</button>
                    <button class="seo-action-btn" onclick="seoSwitchGroup('content'); seoSwitchSubtab('metatags');">🏷️ Meta-Tag Audit starten</button>
                    <button class="seo-action-btn" onclick="seoSwitchGroup('technical'); seoSwitchSubtab('hreflang');">🌐 hreflang Audit starten</button>
                    <button class="seo-action-btn" onclick="seoSwitchGroup('monitoring'); seoSwitchSubtab('indexing');">📤 URL zur Indexierung einreichen</button>
                </div>
            </div>
        </div>`;
    }

    function loadDashboardData() {
        seoAjax('seo_dashboard_overview', {}, function(data) {
            if (!data) return;
            const kpis = document.getElementById('seoDashboardKpis');
            if (!kpis) return;

            const healthScore = data.health_score || 0;
            const healthColor = healthScore >= 80 ? '#22c55e' : healthScore >= 50 ? '#f59e0b' : '#ef4444';
            const redirectCount = data.redirect_count || 0;
            const error404Count = data.error_404_count || 0;
            const scanIssues = data.scan_issues || 0;
            const visibility = data.sistrix_visibility || '—';

            kpis.innerHTML = `
                <div class="seo-kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-label">Health Score</span>
                        <div class="kpi-icon" style="background:${healthColor}20; color:${healthColor};">❤️</div>
                    </div>
                    <div class="kpi-value" style="color:${healthColor};">${healthScore}%</div>
                    <div class="seo-progress"><div class="seo-progress-fill" style="width:${healthScore}%; background:${healthColor};"></div></div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-label">Sistrix SI</span>
                        <div class="kpi-icon" style="background:rgba(59,130,246,0.12); color:#3b82f6;">📈</div>
                    </div>
                    <div class="kpi-value">${visibility}</div>
                    <div class="kpi-trend ${data.si_trend || 'neutral'}">${data.si_trend === 'up' ? '↑' : data.si_trend === 'down' ? '↓' : '→'} ${data.si_change || ''}</div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-label">Aktive Redirects</span>
                        <div class="kpi-icon" style="background:rgba(168,85,247,0.12); color:#a855f7;">↪️</div>
                    </div>
                    <div class="kpi-value">${redirectCount}</div>
                    <div class="kpi-trend neutral">${data.redirect_hits_today || 0} Hits heute</div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-label">404-Fehler</span>
                        <div class="kpi-icon" style="background:rgba(239,68,68,0.12); color:#ef4444;">🚫</div>
                    </div>
                    <div class="kpi-value">${error404Count}</div>
                    <div class="kpi-trend ${error404Count > 50 ? 'down' : 'neutral'}">${data.new_404_today || 0} neue heute</div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-label">Scan-Probleme</span>
                        <div class="kpi-icon" style="background:rgba(245,158,11,0.12); color:#f59e0b;">⚠️</div>
                    </div>
                    <div class="kpi-value">${scanIssues}</div>
                    <div class="kpi-trend neutral">Letzter Scan: ${data.last_scan || '—'}</div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-header">
                        <span class="kpi-label">Schema.org</span>
                        <div class="kpi-icon" style="background:rgba(34,197,94,0.12); color:#22c55e;">📋</div>
                    </div>
                    <div class="kpi-value">${data.schema_coverage || '—'}%</div>
                    <div class="kpi-trend neutral">Abdeckung</div>
                </div>
            `;
        });
    }

    window.seoRefreshDashboard = function() {
        loadDashboardData();
    };

    // ============================================================
    // HREFLANG PANEL
    // ============================================================
    function renderHreflangPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">🌐</span> hreflang Validator</div>
            <div style="display:flex; gap:8px;">
                <button class="seo-action-btn primary" onclick="seoRunHreflangAudit()">🔍 Audit starten</button>
                <button class="seo-action-btn" onclick="seoTestSingleHreflang()">🔗 Einzelne URL testen</button>
            </div>
        </div>

        <div class="seo-info-box info">
            <span>🌐</span>
            <div>Prüft alle Seiten auf korrekte hreflang-Tags für die 4 aktiven Sprachen: <strong>DE, EN, FR, ES</strong>. Der Audit scannt die Sitemap und validiert reziproke Verweise, x-default und Self-Referencing.</div>
        </div>

        <div id="seoHreflangResults">
            <div class="seo-loading"><div class="spinner"></div> Lade hreflang-Daten...</div>
        </div>`;
    }

    window.seoRunHreflangAudit = function() {
        const el = document.getElementById('seoHreflangResults');
        el.innerHTML = '<div class="seo-loading"><div class="spinner"></div> hreflang Audit läuft... (kann einige Minuten dauern)</div>';
        seoAjax('seo_hreflang_audit', {}, function(data) {
            if (data && data.stats) renderHreflangResults(data.stats);
            else el.innerHTML = '<div class="seo-info-box error"><span>❌</span><div>Audit fehlgeschlagen</div></div>';
        });
    };

    window.seoTestSingleHreflang = function() {
        const url = prompt('URL eingeben (z.B. /samen-shop/autoflowering-samen/):');
        if (!url) return;
        seoAjax('seo_hreflang_scan', { url: url }, function(data) {
            if (data) {
                let html = `<h4>hreflang Ergebnis für: ${data.url}</h4>`;
                html += `<p>Score: <strong>${data.score}/100</strong></p>`;
                if (data.hreflang_tags && Object.keys(data.hreflang_tags).length > 0) {
                    html += '<table class="seo-table"><thead><tr><th>Sprache</th><th>URL</th></tr></thead><tbody>';
                    for (const [lang, href] of Object.entries(data.hreflang_tags)) {
                        html += `<tr><td><strong>${lang}</strong></td><td style="font-size:12px;">${href}</td></tr>`;
                    }
                    html += '</tbody></table>';
                }
                if (data.issues && data.issues.length > 0) {
                    html += '<div class="seo-info-box warning" style="margin-top:12px;"><span>⚠️</span><div>';
                    data.issues.forEach(i => { html += `<div>• ${i}</div>`; });
                    html += '</div></div>';
                }
                document.getElementById('seoHreflangResults').innerHTML = html;
            }
        });
    };

    function renderHreflangResults(stats) {
        const el = document.getElementById('seoHreflangResults');
        let html = `
        <div class="seo-overview-grid">
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Gescannt</span></div>
                <div class="kpi-value">${stats.total_scanned}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Mit hreflang</span></div>
                <div class="kpi-value" style="color:#22c55e;">${stats.with_hreflang}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Ohne hreflang</span></div>
                <div class="kpi-value" style="color:#ef4444;">${stats.without_hreflang}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Ø Score</span></div>
                <div class="kpi-value">${stats.avg_score}</div>
            </div>
        </div>`;

        // Sprach-Abdeckung
        if (stats.lang_coverage) {
            html += '<h4 style="margin:16px 0 8px;">Sprach-Abdeckung</h4>';
            html += '<div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:8px;">';
            for (const [lang, data] of Object.entries(stats.lang_coverage)) {
                const pct = data.percentage || 0;
                const color = pct >= 90 ? '#22c55e' : pct >= 50 ? '#f59e0b' : '#ef4444';
                html += `<div class="seo-kpi-card" style="text-align:center;">
                    <div class="kpi-label">${lang.toUpperCase()}</div>
                    <div class="kpi-value" style="color:${color}; font-size:22px;">${pct}%</div>
                    <div class="seo-progress"><div class="seo-progress-fill" style="width:${pct}%; background:${color};"></div></div>
                </div>`;
            }
            html += '</div>';
        }

        // Häufige Issues
        if (stats.common_issues && Object.keys(stats.common_issues).length > 0) {
            html += '<h4 style="margin:16px 0 8px;">Häufigste Probleme</h4>';
            html += '<table class="seo-table"><thead><tr><th>Problem</th><th>Anzahl</th></tr></thead><tbody>';
            for (const [issue, count] of Object.entries(stats.common_issues)) {
                html += `<tr><td>${issue}</td><td><strong>${count}</strong></td></tr>`;
            }
            html += '</tbody></table>';
        }

        el.innerHTML = html;
    }

    // ============================================================
    // ROBOTS.TXT PANEL
    // ============================================================
    function renderRobotsTxtPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">🤖</span> robots.txt Editor</div>
            <div style="display:flex; gap:8px;">
                <button class="seo-action-btn primary" onclick="seoSaveRobotsTxt()">💾 Speichern</button>
                <button class="seo-action-btn" onclick="seoValidateRobotsTxt()">✅ Validieren</button>
            </div>
        </div>
        <div id="seoRobotsTxtValidation"></div>
        <textarea class="seo-editor" id="seoRobotsTxtEditor" placeholder="robots.txt wird geladen..."></textarea>
        <div style="margin-top:8px; font-size:11px; color:var(--fpc-text-secondary);" id="seoRobotsTxtInfo"></div>`;
    }

    function loadRobotsTxt() {
        seoAjax('seo_robotstxt_get', {}, function(data) {
            if (data && data.content !== undefined) {
                document.getElementById('seoRobotsTxtEditor').value = data.content;
                document.getElementById('seoRobotsTxtInfo').textContent =
                    data.exists ? `Letzte Änderung: ${data.modified} | Größe: ${data.size} Bytes` : 'robots.txt existiert noch nicht';
            }
        });
    }

    window.seoSaveRobotsTxt = function() {
        const content = document.getElementById('seoRobotsTxtEditor').value;
        seoAjax('seo_robotstxt_save', { content: content }, function(data) {
            if (data && data.ok) showSeoToast('robots.txt gespeichert', 'success');
            else showSeoToast('Fehler beim Speichern: ' + (data?.msg || ''), 'error');
        });
    };

    window.seoValidateRobotsTxt = function() {
        const content = document.getElementById('seoRobotsTxtEditor').value;
        seoAjax('seo_robotstxt_validate', { content: content }, function(data) {
            if (!data) return;
            let html = '';
            if (data.issues && data.issues.length > 0) {
                html += '<div class="seo-info-box error"><span>❌</span><div>';
                data.issues.forEach(i => { html += `<div>• ${i}</div>`; });
                html += '</div></div>';
            }
            if (data.warnings && data.warnings.length > 0) {
                html += '<div class="seo-info-box warning"><span>⚠️</span><div>';
                data.warnings.forEach(w => { html += `<div>• ${w}</div>`; });
                html += '</div></div>';
            }
            if (data.valid && (!data.warnings || data.warnings.length === 0)) {
                html += '<div class="seo-info-box success"><span>✅</span><div>robots.txt ist valide!</div></div>';
            }
            document.getElementById('seoRobotsTxtValidation').innerHTML = html;
        });
    };

    // ============================================================
    // SITEMAP PANEL
    // ============================================================
    function renderSitemapPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">🗺️</span> Sitemap Validator</div>
            <div>
                <button class="seo-action-btn primary" onclick="seoValidateSitemap()">🔍 Sitemap validieren</button>
            </div>
        </div>
        <div class="seo-info-box info"><span>🗺️</span><div>Validiert die Sitemap auf XML-Fehler, tote Links und Duplikate. Prüft auch Sub-Sitemaps bei Sitemap-Index.</div></div>
        <div id="seoSitemapResults"></div>`;
    }

    window.seoValidateSitemap = function() {
        const el = document.getElementById('seoSitemapResults');
        el.innerHTML = '<div class="seo-loading"><div class="spinner"></div> Sitemap wird validiert...</div>';
        seoAjax('seo_sitemap_validate', {}, function(data) {
            if (!data) { el.innerHTML = '<div class="seo-info-box error"><span>❌</span><div>Validierung fehlgeschlagen</div></div>'; return; }
            let html = `
            <div class="seo-overview-grid">
                <div class="seo-kpi-card">
                    <div class="kpi-label">Status</div>
                    <div class="kpi-value" style="font-size:20px; color:${data.valid ? '#22c55e' : '#ef4444'};">${data.valid ? '✅ Valide' : '❌ Fehler'}</div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-label">Typ</div>
                    <div class="kpi-value" style="font-size:20px;">${data.type === 'index' ? 'Sitemap Index' : 'URL-Set'}</div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-label">${data.type === 'index' ? 'Sub-Sitemaps' : 'URLs'}</div>
                    <div class="kpi-value">${data.total_urls}</div>
                </div>
            </div>`;

            if (data.warnings && data.warnings.length > 0) {
                html += '<div class="seo-info-box warning" style="margin-top:12px;"><span>⚠️</span><div>';
                data.warnings.forEach(w => { html += `<div>• ${w}</div>`; });
                html += '</div></div>';
            }

            if (data.sample_checks && data.sample_checks.length > 0) {
                html += '<h4 style="margin:16px 0 8px;">Stichproben-Check</h4>';
                html += '<table class="seo-table"><thead><tr><th>URL</th><th>Status</th></tr></thead><tbody>';
                data.sample_checks.forEach(c => {
                    const color = c.ok ? '#22c55e' : '#ef4444';
                    html += `<tr><td style="font-size:12px; max-width:500px; overflow:hidden; text-overflow:ellipsis;">${c.url}</td><td style="color:${color}; font-weight:600;">${c.status}</td></tr>`;
                });
                html += '</tbody></table>';
            }

            el.innerHTML = html;
        });
    };

    // ============================================================
    // SCHEMA.ORG PANEL
    // ============================================================
    function renderSchemaPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">📋</span> Schema.org Scanner</div>
            <div style="display:flex; gap:8px;">
                <button class="seo-action-btn primary" onclick="seoRunSchemaFullScan()">🔍 Voll-Scan starten</button>
                <button class="seo-action-btn" onclick="seoTestSingleSchema()">🔗 Einzelne URL testen</button>
            </div>
        </div>
        <div class="seo-info-box info"><span>📋</span><div>Scannt alle Seiten auf Schema.org Structured Data (JSON-LD). Prüft Product, BreadcrumbList, Organization, WebSite, FAQ und mehr. <strong>Cronjob: alle 14 Tage automatisch.</strong></div></div>
        <div id="seoSchemaResults">
            <div class="seo-loading"><div class="spinner"></div> Lade Schema.org Daten...</div>
        </div>`;
    }

    function loadSchemaStats() {
        seoAjax('seo_schema_stats', {}, function(data) {
            const el = document.getElementById('seoSchemaResults');
            if (!data || !data.has_data) {
                el.innerHTML = '<div class="seo-info-box warning"><span>⚠️</span><div>Noch kein Schema.org Scan durchgeführt. Klicken Sie auf "Voll-Scan starten" für den ersten Scan.</div></div>';
                return;
            }
            renderSchemaResults(data);
        });
    }

    function renderSchemaResults(data) {
        const el = document.getElementById('seoSchemaResults');
        const coverage = data.overall_coverage || 0;
        const coverageColor = coverage >= 80 ? '#22c55e' : coverage >= 50 ? '#f59e0b' : '#ef4444';

        let html = `
        <div class="seo-overview-grid">
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Abdeckung</span></div>
                <div class="kpi-value" style="color:${coverageColor};">${coverage}%</div>
                <div class="seo-progress"><div class="seo-progress-fill" style="width:${coverage}%; background:${coverageColor};"></div></div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Ø Score</span></div>
                <div class="kpi-value">${data.avg_score || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Mit JSON-LD</span></div>
                <div class="kpi-value" style="color:#22c55e;">${data.with_jsonld || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Ohne Schema</span></div>
                <div class="kpi-value" style="color:#ef4444;">${data.without_schema || 0}</div>
            </div>
        </div>`;

        // Schema-Typen Verteilung
        if (data.schema_types && Object.keys(data.schema_types).length > 0) {
            html += '<h4 style="margin:16px 0 8px;">Gefundene Schema-Typen</h4>';
            html += '<table class="seo-table"><thead><tr><th>Schema-Typ</th><th>Anzahl</th><th>Anteil</th></tr></thead><tbody>';
            const total = data.total_scanned || 1;
            for (const [type, count] of Object.entries(data.schema_types)) {
                const pct = Math.round((count / total) * 100);
                html += `<tr><td><strong>${type}</strong></td><td>${count}</td><td>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div class="seo-progress" style="flex:1;"><div class="seo-progress-fill blue" style="width:${pct}%;"></div></div>
                        <span style="font-size:12px;">${pct}%</span>
                    </div></td></tr>`;
            }
            html += '</tbody></table>';
        }

        if (data.last_scan) {
            html += `<div style="margin-top:12px; font-size:12px; color:var(--fpc-text-secondary);">Letzter Scan: ${data.last_scan}</div>`;
        }

        el.innerHTML = html;
    }

    window.seoRunSchemaFullScan = function() {
        const el = document.getElementById('seoSchemaResults');
        el.innerHTML = '<div class="seo-loading"><div class="spinner"></div> Schema.org Voll-Scan läuft... (kann 5-10 Minuten dauern)</div>';
        seoAjax('seo_schema_scan', { full: true }, function(data) {
            if (data && data.ok) {
                showSeoToast(data.msg, 'success');
                loadSchemaStats();
            } else {
                el.innerHTML = `<div class="seo-info-box error"><span>❌</span><div>${data?.msg || 'Scan fehlgeschlagen'}</div></div>`;
            }
        });
    };

    window.seoTestSingleSchema = function() {
        const url = prompt('URL eingeben:');
        if (!url) return;
        seoAjax('seo_schema_scan_url', { url: url }, function(data) {
            if (data) {
                const el = document.getElementById('seoSchemaResults');
                let html = `<h4>Schema.org Ergebnis für: ${data.url}</h4>`;
                html += `<p>Score: <strong>${data.score}/100</strong> | Seitentyp: <strong>${data.page_type}</strong> | JSON-LD: ${data.has_jsonld ? '✅' : '❌'}</p>`;
                if (data.schema_types && data.schema_types.length > 0) {
                    html += `<p>Gefundene Typen: <strong>${data.schema_types.join(', ')}</strong></p>`;
                }
                if (data.issues && data.issues.length > 0) {
                    html += '<div class="seo-info-box warning"><span>⚠️</span><div>';
                    data.issues.forEach(i => { html += `<div>• ${i}</div>`; });
                    html += '</div></div>';
                }
                if (data.recommendations && data.recommendations.length > 0) {
                    html += '<div class="seo-info-box info"><span>💡</span><div><strong>Empfehlungen:</strong>';
                    data.recommendations.forEach(r => { html += `<div>• ${r}</div>`; });
                    html += '</div></div>';
                }
                el.innerHTML = html;
            }
        });
    };

    // ============================================================
    // CORE WEB VITALS PANEL
    // ============================================================
    function renderCwvPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">⚡</span> Core Web Vitals</div>
            <div style="display:flex; gap:8px;">
                <button class="seo-action-btn primary" onclick="seoRunCwvBatch()">🔍 Batch-Test (Top 20)</button>
                <button class="seo-action-btn" onclick="seoTestSingleCwv()">🔗 Einzelne URL testen</button>
            </div>
        </div>
        <div class="seo-info-box info"><span>⚡</span><div>Testet Seiten über die Google PageSpeed Insights API. Zeigt LCP, INP, CLS und weitere Metriken mit Ampel-System. <strong>Benötigt Google API Key (optional in Einstellungen).</strong></div></div>
        <div id="seoCwvResults">
            <div class="seo-loading"><div class="spinner"></div> Lade CWV-Daten...</div>
        </div>`;
    }

    function loadCwvData() {
        seoAjax('seo_cwv_results', {}, function(data) {
            const el = document.getElementById('seoCwvResults');
            if (!data || !data.has_data) {
                el.innerHTML = '<div class="seo-info-box warning"><span>⚠️</span><div>Noch kein CWV-Test durchgeführt. Klicken Sie auf "Batch-Test" oder konfigurieren Sie den Google API Key in den Einstellungen.</div></div>';
                return;
            }
            renderCwvResults(data);
        });
    }

    function renderCwvResults(data) {
        const el = document.getElementById('seoCwvResults');
        const s = data.summary || {};

        let html = `
        <div class="seo-overview-grid">
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Ø Performance</span></div>
                <div class="kpi-value" style="color:${s.avg_performance >= 90 ? '#22c55e' : s.avg_performance >= 50 ? '#f59e0b' : '#ef4444'};">${s.avg_performance || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Ø LCP</span></div>
                <div class="kpi-value">${s.avg_lcp ? (s.avg_lcp / 1000).toFixed(1) + 's' : '—'}</div>
                <span class="cwv-badge ${getCwvRating('LCP', s.avg_lcp)}">${getCwvRating('LCP', s.avg_lcp)}</span>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Ø CLS</span></div>
                <div class="kpi-value">${s.avg_cls !== undefined ? s.avg_cls.toFixed(3) : '—'}</div>
                <span class="cwv-badge ${getCwvRating('CLS', s.avg_cls)}">${getCwvRating('CLS', s.avg_cls)}</span>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-header"><span class="kpi-label">Gut / Mittel / Schlecht</span></div>
                <div style="display:flex; gap:12px; margin-top:8px;">
                    <span style="color:#22c55e; font-weight:700;">${s.good || 0}</span>
                    <span style="color:#f59e0b; font-weight:700;">${s.needs_improvement || 0}</span>
                    <span style="color:#ef4444; font-weight:700;">${s.poor || 0}</span>
                </div>
            </div>
        </div>`;

        // Einzelergebnisse
        if (data.results && data.results.length > 0) {
            html += '<h4 style="margin:16px 0 8px;">Einzelergebnisse</h4>';
            html += '<table class="seo-table"><thead><tr><th>URL</th><th>Performance</th><th>LCP</th><th>CLS</th><th>SEO</th></tr></thead><tbody>';
            data.results.forEach(r => {
                const perf = r.scores?.performance || 0;
                const perfColor = perf >= 90 ? '#22c55e' : perf >= 50 ? '#f59e0b' : '#ef4444';
                const lcp = r.lab_data?.LCP?.display || '—';
                const cls = r.lab_data?.CLS?.display || '—';
                const seo = r.scores?.seo || 0;
                const shortUrl = r.url.replace('https://mr-hanf.de', '').substring(0, 60);
                html += `<tr>
                    <td style="font-size:12px;" title="${r.url}">${shortUrl || '/'}</td>
                    <td style="color:${perfColor}; font-weight:700;">${perf}</td>
                    <td><span class="cwv-badge ${r.lab_data?.LCP?.rating || ''}">${lcp}</span></td>
                    <td><span class="cwv-badge ${r.lab_data?.CLS?.rating || ''}">${cls}</span></td>
                    <td>${seo}</td>
                </tr>`;
            });
            html += '</tbody></table>';
        }

        el.innerHTML = html;
    }

    function getCwvRating(metric, value) {
        if (value === null || value === undefined) return 'unknown';
        const thresholds = { LCP: { good: 2500, poor: 4000 }, INP: { good: 200, poor: 500 }, CLS: { good: 0.1, poor: 0.25 } };
        const t = thresholds[metric];
        if (!t) return 'unknown';
        if (value <= t.good) return 'good';
        if (value <= t.poor) return 'needs-improvement';
        return 'poor';
    }

    window.seoRunCwvBatch = function() {
        const el = document.getElementById('seoCwvResults');
        el.innerHTML = '<div class="seo-loading"><div class="spinner"></div> CWV Batch-Test läuft... (ca. 2-3 Minuten)</div>';
        seoAjax('seo_cwv_test', { batch: true, limit: 20 }, function(data) {
            if (data && data.ok) {
                showSeoToast('CWV-Test abgeschlossen', 'success');
                loadCwvData();
            } else {
                el.innerHTML = `<div class="seo-info-box error"><span>❌</span><div>${data?.msg || 'Test fehlgeschlagen'}</div></div>`;
            }
        });
    };

    window.seoTestSingleCwv = function() {
        const url = prompt('URL eingeben:');
        if (!url) return;
        seoAjax('seo_cwv_test', { url: url }, function(data) {
            if (data && data.ok) {
                const el = document.getElementById('seoCwvResults');
                let html = `<h4>CWV Ergebnis für: ${data.url}</h4>`;
                html += `<p>Performance: <strong style="color:${data.scores?.performance >= 90 ? '#22c55e' : '#f59e0b'};">${data.scores?.performance || 0}/100</strong></p>`;
                if (data.lab_data) {
                    html += '<table class="seo-table"><thead><tr><th>Metrik</th><th>Wert</th><th>Bewertung</th></tr></thead><tbody>';
                    for (const [metric, info] of Object.entries(data.lab_data)) {
                        html += `<tr><td><strong>${metric}</strong></td><td>${info.display || '—'}</td><td><span class="cwv-badge ${info.rating || ''}">${info.rating || '—'}</span></td></tr>`;
                    }
                    html += '</tbody></table>';
                }
                el.innerHTML = html;
            }
        });
    };

    // ============================================================
    // INDEXING API PANEL
    // ============================================================
    function renderIndexingPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">📤</span> Google Indexing API</div>
            <div style="display:flex; gap:8px;">
                <button class="seo-action-btn primary" onclick="seoSubmitUrl()">📤 URL einreichen</button>
                <button class="seo-action-btn" onclick="seoSubmitBulk()">📦 Bulk einreichen</button>
            </div>
        </div>
        <div id="seoIndexingQuota"></div>
        <div id="seoIndexingLog">
            <div class="seo-loading"><div class="spinner"></div> Lade Indexing-Daten...</div>
        </div>`;
    }

    function loadIndexingData() {
        seoAjax('seo_indexing_quota', {}, function(data) {
            if (data) {
                const el = document.getElementById('seoIndexingQuota');
                const pct = data.percentage_used || 0;
                el.innerHTML = `
                <div class="seo-overview-grid" style="margin-bottom:16px;">
                    <div class="seo-kpi-card">
                        <div class="kpi-label">Heute verbraucht</div>
                        <div class="kpi-value">${data.used_today || 0} / ${data.daily_limit || 200}</div>
                        <div class="seo-progress"><div class="seo-progress-fill ${pct > 80 ? 'red' : pct > 50 ? 'yellow' : 'green'}" style="width:${pct}%;"></div></div>
                    </div>
                    <div class="seo-kpi-card">
                        <div class="kpi-label">Verbleibend</div>
                        <div class="kpi-value" style="color:#22c55e;">${data.remaining || 200}</div>
                    </div>
                </div>`;
            }
        });

        seoAjax('seo_indexing_log', { limit: 20 }, function(data) {
            const el = document.getElementById('seoIndexingLog');
            if (!data || data.length === 0) {
                el.innerHTML = '<div class="seo-info-box info"><span>ℹ️</span><div>Noch keine URLs eingereicht. Nutzen Sie "URL einreichen" um Google über neue oder geänderte Seiten zu informieren.</div></div>';
                return;
            }
            let html = '<h4 style="margin:0 0 8px;">Letzte Einreichungen</h4>';
            html += '<table class="seo-table"><thead><tr><th>URL</th><th>Typ</th><th>Status</th><th>Zeitpunkt</th></tr></thead><tbody>';
            data.forEach(entry => {
                const statusColor = entry.success ? '#22c55e' : '#ef4444';
                const shortUrl = entry.url.replace('https://mr-hanf.de', '').substring(0, 50);
                html += `<tr>
                    <td style="font-size:12px;" title="${entry.url}">${shortUrl}</td>
                    <td>${entry.type === 'URL_UPDATED' ? '🔄 Update' : '🗑️ Löschen'}</td>
                    <td style="color:${statusColor}; font-weight:600;">${entry.success ? '✅ OK' : '❌ Fehler'}</td>
                    <td style="font-size:12px;">${entry.timestamp}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            el.innerHTML = html;
        });
    }

    window.seoSubmitUrl = function() {
        const url = prompt('URL zur Indexierung einreichen:');
        if (!url) return;
        seoAjax('seo_indexing_submit', { url: url, type: 'URL_UPDATED' }, function(data) {
            if (data && data.ok) {
                showSeoToast(data.msg, 'success');
                loadIndexingData();
            } else {
                showSeoToast(data?.msg || 'Fehler', 'error');
            }
        });
    };

    window.seoSubmitBulk = function() {
        const urls = prompt('URLs eingeben (eine pro Zeile, komma-getrennt oder mit Zeilenumbruch):');
        if (!urls) return;
        const urlList = urls.split(/[,\n]/).map(u => u.trim()).filter(u => u);
        seoAjax('seo_indexing_submit_batch', { urls: urlList, type: 'URL_UPDATED' }, function(data) {
            if (data && data.ok) {
                showSeoToast(data.msg, 'success');
                loadIndexingData();
            } else {
                showSeoToast(data?.msg || 'Fehler', 'error');
            }
        });
    };

    // ============================================================
    // KEYWORD MONITOR PANEL
    // ============================================================
    function renderKeywordPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">🔑</span> Keyword Monitor</div>
            <div><button class="seo-action-btn" onclick="seoRefreshKeywords()">🔄 Aktualisieren (GSC)</button></div>
        </div>
        <div class="seo-info-box info"><span>🔑</span><div>Zeigt Keyword-Rankings basierend auf Google Search Console Daten. Tracking von Positionsveränderungen über Zeit.</div></div>
        <div id="seoKeywordResults">
            <div class="seo-loading"><div class="spinner"></div> Lade Keyword-Daten...</div>
        </div>`;
    }

    function loadKeywordData() {
        seoAjax('seo_keyword_monitor', {}, function(data) {
            const el = document.getElementById('seoKeywordResults');
            if (!data || !data.snapshots || data.snapshots.length === 0) {
                el.innerHTML = '<div class="seo-info-box warning"><span>⚠️</span><div>Noch keine Keyword-Daten. Klicken Sie auf "Aktualisieren" um die aktuellen GSC-Rankings zu laden.</div></div>';
                return;
            }
            const latest = data.snapshots[data.snapshots.length - 1];
            let html = `<p style="font-size:12px; color:var(--fpc-text-secondary); margin-bottom:12px;">Stand: ${latest.timestamp} | ${latest.keywords.length} Keywords</p>`;
            html += '<table class="seo-table"><thead><tr><th>Keyword</th><th>Position</th><th>Klicks</th><th>Impressionen</th><th>CTR</th></tr></thead><tbody>';
            latest.keywords.slice(0, 50).forEach(kw => {
                const posColor = kw.position <= 3 ? '#22c55e' : kw.position <= 10 ? '#3b82f6' : kw.position <= 20 ? '#f59e0b' : '#ef4444';
                html += `<tr>
                    <td><strong>${kw.query}</strong></td>
                    <td style="color:${posColor}; font-weight:700;">${kw.position}</td>
                    <td>${kw.clicks}</td>
                    <td>${kw.impressions}</td>
                    <td>${kw.ctr}%</td>
                </tr>`;
            });
            html += '</tbody></table>';
            el.innerHTML = html;
        });
    }

    window.seoRefreshKeywords = function() {
        document.getElementById('seoKeywordResults').innerHTML = '<div class="seo-loading"><div class="spinner"></div> Lade GSC-Daten...</div>';
        seoAjax('seo_keyword_refresh', {}, function(data) {
            if (data && data.ok) {
                showSeoToast(data.msg, 'success');
                loadKeywordData();
            } else {
                showSeoToast(data?.msg || 'Fehler', 'error');
            }
        });
    };

    // ============================================================
    // LLMS.TXT PANEL
    // ============================================================
    function renderLlmsTxtPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">📄</span> llms.txt Manager</div>
            <div style="display:flex; gap:8px;">
                <button class="seo-action-btn primary" onclick="seoSaveLlmsTxt()">💾 Speichern</button>
                <button class="seo-action-btn success" onclick="seoGenerateLlmsTxt()">🤖 Auto-Generieren</button>
            </div>
        </div>
        <div class="seo-info-box info"><span>📄</span><div>
            <strong>llms.txt</strong> ist ein neuer Standard für KI-Suchmaschinen (ChatGPT, Gemini, Perplexity). Die Datei beschreibt Ihren Shop für AI-Systeme und wird unter <code>https://mr-hanf.de/llms.txt</code> bereitgestellt.
        </div></div>
        <div id="seoLlmsTxtInfo" style="margin-bottom:8px;"></div>
        <textarea class="seo-editor" id="seoLlmsTxtEditor" style="min-height:400px;" placeholder="llms.txt wird geladen..."></textarea>`;
    }

    function loadLlmsTxt() {
        seoAjax('seo_llms_get', {}, function(data) {
            if (data) {
                document.getElementById('seoLlmsTxtEditor').value = data.content || '';
                const info = document.getElementById('seoLlmsTxtInfo');
                if (data.exists) {
                    info.innerHTML = `<span class="cwv-badge good">✅ llms.txt existiert</span> <span style="font-size:12px; color:var(--fpc-text-secondary); margin-left:8px;">Letzte Änderung: ${data.modified} | ${data.size} Bytes</span>`;
                } else {
                    info.innerHTML = '<span class="cwv-badge poor">❌ llms.txt existiert noch nicht</span> <span style="font-size:12px; color:var(--fpc-text-secondary); margin-left:8px;">Klicken Sie auf "Auto-Generieren" um die Datei zu erstellen.</span>';
                }
            }
        });
    }

    window.seoSaveLlmsTxt = function() {
        const content = document.getElementById('seoLlmsTxtEditor').value;
        seoAjax('seo_llms_save', { content: content }, function(data) {
            if (data && data.ok) showSeoToast('llms.txt gespeichert', 'success');
            else showSeoToast('Fehler: ' + (data?.msg || ''), 'error');
        });
    };

    window.seoGenerateLlmsTxt = function() {
        seoAjax('seo_llms_generate', {}, function(data) {
            if (data && data.content) {
                document.getElementById('seoLlmsTxtEditor').value = data.content;
                showSeoToast('llms.txt generiert — bitte prüfen und speichern', 'success');
            }
        });
    };

    // ============================================================
    // AI-CRAWLER PANEL
    // ============================================================
    function renderAiCrawlerPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">🕷️</span> AI-Crawler Steuerung</div>
            <div><button class="seo-action-btn success" onclick="seoApplyRecommendedCrawlers()">✅ Empfohlene Konfiguration anwenden</button></div>
        </div>
        <div class="seo-info-box info"><span>🕷️</span><div>Steuern Sie welche KI-Crawler (ChatGPT, Gemini, Perplexity etc.) Ihre Seite crawlen dürfen. Die Einstellungen werden in der robots.txt gespeichert.</div></div>
        <div id="seoAiCrawlerGrid">
            <div class="seo-loading"><div class="spinner"></div> Lade AI-Crawler Status...</div>
        </div>
        <div style="margin-top:20px;">
            <h4 style="margin-bottom:12px;">GEO-Optimierungs-Tipps</h4>
            <div id="seoGeoTips"></div>
        </div>`;
    }

    function loadAiCrawlerStatus() {
        seoAjax('seo_aicrawler_status', {}, function(data) {
            const el = document.getElementById('seoAiCrawlerGrid');
            if (!data) { el.innerHTML = '<div class="seo-info-box error"><span>❌</span><div>Fehler beim Laden</div></div>'; return; }

            let html = '<div class="crawler-grid">';
            for (const [key, crawler] of Object.entries(data)) {
                const isAllowed = crawler.current_rule === 'allow' || (crawler.current_rule === 'not_set' && crawler.default === 'allow');
                const impactClass = crawler.impact.replace(' ', '-');
                html += `
                <div class="crawler-card">
                    <div class="crawler-info">
                        <div class="crawler-name">${crawler.name}</div>
                        <div class="crawler-desc">${crawler.description}</div>
                        <span class="crawler-impact ${impactClass}">Impact: ${crawler.impact}</span>
                    </div>
                    <label class="seo-toggle">
                        <input type="checkbox" ${isAllowed ? 'checked' : ''} onchange="seoToggleCrawler('${key}', this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                </div>`;
            }
            html += '</div>';
            el.innerHTML = html;
        });

        // GEO-Tipps laden
        seoAjax('seo_geo_tips', {}, function(data) {
            const el = document.getElementById('seoGeoTips');
            if (!data || !Array.isArray(data)) return;
            let html = '';
            data.forEach(cat => {
                html += `<div class="seo-chart-container" style="margin-bottom:10px;">
                    <h5 style="margin:0 0 8px; display:flex; align-items:center; gap:6px;">
                        <span class="cwv-badge ${cat.priority === 'hoch' ? 'poor' : 'needs-improvement'}">${cat.priority}</span>
                        ${cat.category}
                    </h5>
                    <ul style="margin:0; padding-left:20px; font-size:13px; color:var(--fpc-text-secondary);">
                        ${cat.tips.map(t => `<li style="margin-bottom:4px;">${t}</li>`).join('')}
                    </ul>
                </div>`;
            });
            el.innerHTML = html;
        });
    }

    window.seoToggleCrawler = function(key, allowed) {
        seoAjax('seo_aicrawler_set', { bot: key, action: allowed ? 'allow' : 'disallow' }, function(data) {
            if (data && data.ok) showSeoToast(`${key}: ${allowed ? 'Erlaubt' : 'Blockiert'}`, 'success');
        });
    };

    window.seoApplyRecommendedCrawlers = function() {
        seoAjax('seo_aicrawler_recommended', {}, function(data) {
            if (data && data.ok) {
                showSeoToast('Empfohlene Konfiguration angewendet', 'success');
                loadAiCrawlerStatus();
            }
        });
    };

    // ============================================================
    // META-TAGS PANEL
    // ============================================================
    function renderMetaTagsPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">🏷️</span> Meta-Tag Audit & Editor</div>
            <div><button class="seo-action-btn primary" onclick="seoRunMetaAudit()">🔍 Audit starten</button></div>
        </div>
        <div class="seo-info-box info"><span>🏷️</span><div>Prüft alle Produkte und Kategorien auf Meta-Title und Meta-Description. Zeigt fehlende, zu kurze und zu lange Tags. <strong>Daten werden direkt aus der Datenbank gelesen.</strong></div></div>
        <div id="seoMetaResults">
            <div class="seo-loading"><div class="spinner"></div> Lade Meta-Tag Daten...</div>
        </div>`;
    }

    function loadMetaTagAudit() {
        seoAjax('seo_meta_audit', {}, function(data) {
            const el = document.getElementById('seoMetaResults');
            if (!data || data.ok === false) {
                el.innerHTML = `<div class="seo-info-box warning"><span>⚠️</span><div>${data?.msg || 'Noch kein Audit. Klicken Sie auf "Audit starten".'}</div></div>`;
                return;
            }
            renderMetaAuditResults(data);
        });
    }

    function renderMetaAuditResults(data) {
        const el = document.getElementById('seoMetaResults');
        const p = data.products || {};
        const c = data.categories || {};

        let html = `
        <h4 style="margin:0 0 12px;">Produkte (${p.total || 0})</h4>
        <div class="seo-overview-grid">
            <div class="seo-kpi-card">
                <div class="kpi-label">Mit Title</div>
                <div class="kpi-value" style="color:#22c55e;">${p.with_title || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Ohne Title</div>
                <div class="kpi-value" style="color:#ef4444;">${p.missing_title || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Title zu lang (>60)</div>
                <div class="kpi-value" style="color:#f59e0b;">${p.title_too_long || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Ohne Description</div>
                <div class="kpi-value" style="color:#ef4444;">${p.missing_desc || 0}</div>
            </div>
        </div>

        <h4 style="margin:16px 0 12px;">Kategorien (${c.total || 0})</h4>
        <div class="seo-overview-grid">
            <div class="seo-kpi-card">
                <div class="kpi-label">Mit Title</div>
                <div class="kpi-value" style="color:#22c55e;">${c.with_title || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Ohne Title</div>
                <div class="kpi-value" style="color:#ef4444;">${c.missing_title || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Title zu lang (>60)</div>
                <div class="kpi-value" style="color:#f59e0b;">${c.title_too_long || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Ohne Description</div>
                <div class="kpi-value" style="color:#ef4444;">${c.missing_desc || 0}</div>
            </div>
        </div>`;

        el.innerHTML = html;
    }

    window.seoRunMetaAudit = function() {
        document.getElementById('seoMetaResults').innerHTML = '<div class="seo-loading"><div class="spinner"></div> Meta-Tag Audit läuft...</div>';
        seoAjax('seo_meta_audit_run', {}, function(data) {
            if (data) {
                showSeoToast('Meta-Tag Audit abgeschlossen', 'success');
                renderMetaAuditResults(data);
            }
        });
    };

    // ============================================================
    // CONTENT AUDIT PANEL
    // ============================================================
    function renderContentAuditPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">📊</span> Content Audit</div>
            <div><button class="seo-action-btn primary" onclick="seoRunContentAudit()">🔍 Audit starten</button></div>
        </div>
        <div class="seo-info-box info"><span>📊</span><div>Analysiert Content-Qualität direkt aus der Datenbank: Thin Content (<100 Wörter), Content Freshness und Wortanzahl-Statistiken.</div></div>
        <div id="seoContentAuditResults">
            <div class="seo-loading"><div class="spinner"></div> Lade Content-Daten...</div>
        </div>`;
    }

    function loadContentAudit() {
        seoAjax('seo_content_audit', {}, function(data) {
            const el = document.getElementById('seoContentAuditResults');
            if (!data || data.ok === false) {
                el.innerHTML = `<div class="seo-info-box warning"><span>⚠️</span><div>${data?.msg || 'Noch kein Audit. Klicken Sie auf "Audit starten".'}</div></div>`;
                return;
            }
            renderContentAuditResults(data);
        });
    }

    function renderContentAuditResults(data) {
        const el = document.getElementById('seoContentAuditResults');
        const p = data.products || {};
        const c = data.categories || {};

        let html = `
        <h4 style="margin:0 0 12px;">Produkte — Content-Qualität</h4>
        <div class="seo-overview-grid">
            <div class="seo-kpi-card">
                <div class="kpi-label">Ø Wortanzahl</div>
                <div class="kpi-value">${p.avg_word_count || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Thin Content (<100)</div>
                <div class="kpi-value" style="color:#ef4444;">${p.thin_content || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Guter Content (300-1000)</div>
                <div class="kpi-value" style="color:#22c55e;">${p.good_content || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Rich Content (>1000)</div>
                <div class="kpi-value" style="color:#3b82f6;">${p.rich_content || 0}</div>
            </div>
        </div>`;

        // Freshness
        if (p.freshness) {
            html += `
            <h4 style="margin:16px 0 12px;">Content Freshness</h4>
            <div class="seo-overview-grid">
                <div class="seo-kpi-card">
                    <div class="kpi-label">Frisch (<90 Tage)</div>
                    <div class="kpi-value" style="color:#22c55e;">${p.freshness.fresh || 0}</div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-label">Alternd (90-365 Tage)</div>
                    <div class="kpi-value" style="color:#f59e0b;">${p.freshness.aging || 0}</div>
                </div>
                <div class="seo-kpi-card">
                    <div class="kpi-label">Veraltet (>365 Tage)</div>
                    <div class="kpi-value" style="color:#ef4444;">${p.freshness.stale || 0}</div>
                </div>
            </div>`;
        }

        // Thin Content Liste
        if (data.thin_content_list && data.thin_content_list.length > 0) {
            html += '<h4 style="margin:16px 0 8px;">Thin Content — Top Probleme</h4>';
            html += '<table class="seo-table"><thead><tr><th>Typ</th><th>ID</th><th>Name</th><th>Wörter</th></tr></thead><tbody>';
            data.thin_content_list.slice(0, 20).forEach(item => {
                html += `<tr><td>${item.type}</td><td>${item.id}</td><td>${item.name}</td><td style="color:#ef4444; font-weight:700;">${item.words}</td></tr>`;
            });
            html += '</tbody></table>';
        }

        el.innerHTML = html;
    }

    window.seoRunContentAudit = function() {
        document.getElementById('seoContentAuditResults').innerHTML = '<div class="seo-loading"><div class="spinner"></div> Content Audit läuft...</div>';
        seoAjax('seo_content_audit_run', {}, function(data) {
            if (data) {
                showSeoToast('Content Audit abgeschlossen', 'success');
                renderContentAuditResults(data);
            }
        });
    };

    // ============================================================
    // INTERNAL LINKS PANEL
    // ============================================================
    function renderInternalLinksPanel() {
        return `
        <div class="seo-section-header">
            <div class="seo-section-title"><span class="section-icon">🔗</span> Internal Links Analyse</div>
            <div><button class="seo-action-btn primary" onclick="seoRunInternalLinks()">🔍 Analyse starten</button></div>
        </div>
        <div class="seo-info-box info"><span>🔗</span><div>Crawlt die Seite und analysiert die interne Linkstruktur: Verwaiste Seiten, Link-Verteilung und durchschnittliche Links pro Seite.</div></div>
        <div id="seoInternalLinksResults">
            <div class="seo-loading"><div class="spinner"></div> Lade Internal Links Daten...</div>
        </div>`;
    }

    function loadInternalLinks() {
        seoAjax('seo_internal_links', {}, function(data) {
            const el = document.getElementById('seoInternalLinksResults');
            if (!data || data.has_data === false) {
                el.innerHTML = '<div class="seo-info-box warning"><span>⚠️</span><div>Noch keine Analyse. Klicken Sie auf "Analyse starten".</div></div>';
                return;
            }
            renderInternalLinksResults(data);
        });
    }

    function renderInternalLinksResults(data) {
        const el = document.getElementById('seoInternalLinksResults');
        let html = `
        <div class="seo-overview-grid">
            <div class="seo-kpi-card">
                <div class="kpi-label">URLs gecrawlt</div>
                <div class="kpi-value">${data.urls_crawled || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Interne Links gesamt</div>
                <div class="kpi-value">${data.total_internal_links || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Ø Links/Seite</div>
                <div class="kpi-value">${data.avg_links_per_page || 0}</div>
            </div>
            <div class="seo-kpi-card">
                <div class="kpi-label">Verwaiste Seiten</div>
                <div class="kpi-value" style="color:${(data.orphan_count || 0) > 0 ? '#ef4444' : '#22c55e'};">${data.orphan_count || 0}</div>
            </div>
        </div>`;

        // Verwaiste Seiten
        if (data.orphan_pages && data.orphan_pages.length > 0) {
            html += '<h4 style="margin:16px 0 8px;">Verwaiste Seiten (keine eingehenden Links)</h4>';
            html += '<table class="seo-table"><thead><tr><th>URL</th></tr></thead><tbody>';
            data.orphan_pages.slice(0, 20).forEach(url => {
                html += `<tr><td style="font-size:12px;">${url}</td></tr>`;
            });
            html += '</tbody></table>';
        }

        // Top verlinkte Seiten
        if (data.top_linked && Object.keys(data.top_linked).length > 0) {
            html += '<h4 style="margin:16px 0 8px;">Meistverlinkte Seiten</h4>';
            html += '<table class="seo-table"><thead><tr><th>URL</th><th>Eingehende Links</th></tr></thead><tbody>';
            for (const [url, count] of Object.entries(data.top_linked)) {
                const shortUrl = url.replace('https://mr-hanf.de', '').substring(0, 60);
                html += `<tr><td style="font-size:12px;" title="${url}">${shortUrl || '/'}</td><td style="font-weight:700;">${count}</td></tr>`;
            }
            html += '</tbody></table>';
        }

        el.innerHTML = html;
    }

    window.seoRunInternalLinks = function() {
        document.getElementById('seoInternalLinksResults').innerHTML = '<div class="seo-loading"><div class="spinner"></div> Internal Links Analyse läuft... (kann einige Minuten dauern)</div>';
        seoAjax('seo_internal_links_run', {}, function(data) {
            if (data) {
                showSeoToast('Internal Links Analyse abgeschlossen', 'success');
                renderInternalLinksResults(data);
            }
        });
    };

    // ============================================================
    // AJAX HELPER
    // ============================================================
    function seoAjax(action, params, callback) {
        const formData = new FormData();
        formData.append('action', action);
        for (const [key, value] of Object.entries(params)) {
            if (Array.isArray(value)) {
                formData.append(key, JSON.stringify(value));
            } else {
                formData.append(key, value);
            }
        }

        fetch(window.FPC_AJAX_URL || 'fpc_dashboard.php', {
            method: 'POST',
            body: formData,
        })
        .then(r => r.json())
        .then(data => callback(data))
        .catch(err => {
            console.error('SEO AJAX Error:', err);
            callback(null);
        });
    }

    // Toast-Benachrichtigung
    function showSeoToast(msg, type) {
        // Nutze bestehende FPC Toast-Funktion falls vorhanden
        if (typeof window.fpcToast === 'function') {
            window.fpcToast(msg, type);
            return;
        }
        // Fallback
        const toast = document.createElement('div');
        toast.style.cssText = `position:fixed; bottom:20px; right:20px; padding:12px 20px; border-radius:8px; color:#fff; font-size:13px; z-index:10000; animation:seo-fadeIn 0.3s ease;`;
        toast.style.background = type === 'success' ? '#22c55e' : type === 'error' ? '#ef4444' : '#3b82f6';
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    }

    // ============================================================
    // INIT
    // ============================================================
    // Initial: Dashboard anzeigen
    renderSubtabs('overview');
    loadSubtabContent('dashboard');

})();
</script>
