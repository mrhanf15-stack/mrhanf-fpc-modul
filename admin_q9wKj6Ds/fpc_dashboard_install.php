<?php
/**
 * FPC Schaltzentrale - Menueeintrag Installation
 *
 * Fuegt den Menueeintrag "FPC Schaltzentrale" unter "Statistiken" im
 * modified eCommerce Admin-Backend hinzu.
 *
 * Ausfuehren: Einmal im Browser aufrufen, danach kann die Datei geloescht werden.
 *
 * @version 1.0.0
 */
define('_VALID_XTC', true);
require('includes/application_top.php');

echo '<h2>FPC Schaltzentrale - Installation</h2>';

// ============================================================
// 1. Menueeintrag in admin_access hinzufuegen
// ============================================================
$check = xtc_db_query("SELECT * FROM admin_access WHERE customers_id = 1 LIMIT 1");
$row = xtc_db_fetch_array($check);

// Pruefen ob Spalte fpc_dashboard existiert
$col_check = xtc_db_query("SHOW COLUMNS FROM admin_access LIKE 'fpc_dashboard'");
if (xtc_db_num_rows($col_check) == 0) {
    xtc_db_query("ALTER TABLE admin_access ADD COLUMN fpc_dashboard INT(1) NOT NULL DEFAULT 0");
    echo '<p style="color:green;">&#10003; Spalte "fpc_dashboard" in admin_access angelegt.</p>';
} else {
    echo '<p style="color:blue;">&#8226; Spalte "fpc_dashboard" existiert bereits.</p>';
}

// Admin (ID 1) Zugriff geben
xtc_db_query("UPDATE admin_access SET fpc_dashboard = 1 WHERE customers_id = 1");
echo '<p style="color:green;">&#10003; Admin-Zugriff fuer FPC Dashboard aktiviert.</p>';

// Alle Gruppen-Admins auch berechtigen (groups)
$groups_check = xtc_db_query("SHOW COLUMNS FROM admin_access LIKE 'customers_id'");
if (xtc_db_num_rows($groups_check) > 0) {
    xtc_db_query("UPDATE admin_access SET fpc_dashboard = 1 WHERE customers_id IN (SELECT customers_id FROM " . TABLE_ADMIN . " WHERE admin_groups_id = 1)");
}

// ============================================================
// 2. Menueeintrag in der Datei admin/includes/column_left.php
//    oder ueber die Datenbank (je nach modified-Version)
// ============================================================

// Fuer modified eCommerce: Menueeintrag als SQL in admin_navigation
// Pruefen ob Tabelle admin_navigation existiert
$nav_check = xtc_db_query("SHOW TABLES LIKE 'admin_navigation'");
if (xtc_db_num_rows($nav_check) > 0) {
    // Pruefen ob Eintrag existiert
    $entry_check = xtc_db_query("SELECT * FROM admin_navigation WHERE nav_key = 'fpc_dashboard'");
    if (xtc_db_num_rows($entry_check) == 0) {
        xtc_db_query("INSERT INTO admin_navigation (nav_key, nav_parent, nav_sort, nav_icon, nav_link) VALUES ('fpc_dashboard', 'BOX_HEADING_STATISTICS', 50, 'fa-tachometer', 'fpc_dashboard.php')");
        echo '<p style="color:green;">&#10003; Menueeintrag in admin_navigation angelegt.</p>';
    } else {
        echo '<p style="color:blue;">&#8226; Menueeintrag existiert bereits.</p>';
    }
} else {
    echo '<p style="color:orange;">&#9888; Tabelle admin_navigation nicht gefunden. Menueeintrag muss manuell in column_left.php eingefuegt werden.</p>';
    echo '<p>Folgenden Code in <code>admin/includes/column_left.php</code> unter dem Statistiken-Block einfuegen:</p>';
    echo '<pre style="background:#f5f5f5; padding:10px; border:1px solid #ccc;">';
    echo htmlspecialchars("// FPC Schaltzentrale\n");
    echo htmlspecialchars("if ((\$admin_access['fpc_dashboard'] == '1') || (\$customers_id == '1' && ADMIN_AREA_ALLOW_MASTER == 'true')) {\n");
    echo htmlspecialchars("  echo '&lt;li&gt;&lt;a href=\"' . xtc_href_link('fpc_dashboard.php') . '\"&gt;FPC Schaltzentrale&lt;/a&gt;&lt;/li&gt;';\n");
    echo htmlspecialchars("}\n");
    echo '</pre>';
}

echo '<br /><p style="color:green; font-weight:bold;">&#10003; Installation abgeschlossen!</p>';
echo '<p><a href="fpc_dashboard.php">&#8594; Zur FPC Schaltzentrale</a></p>';

require(DIR_WS_INCLUDES . 'application_bottom.php');
?>
