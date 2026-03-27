<?php
/**
 * Mr. Hanf Full Page Cache v8.0.8 - Deutsche Sprachdatei
 */

// Modul-Grundeinstellungen
define('MODULE_MRHANF_FPC_TITLE', 'Mr. Hanf Full Page Cache');
define('MODULE_MRHANF_FPC_DESC', 'Cron-basiertes Preloading-System. Apache liefert gecachte Seiten direkt als statische HTML-Dateien aus - ohne PHP-Worker.');
define('MODULE_MRHANF_FPC_STATUS_TITLE', 'Modul aktivieren');
define('MODULE_MRHANF_FPC_STATUS_DESC', 'Soll der Full Page Cache aktiviert werden?');
define('MODULE_MRHANF_FPC_CACHE_TIME_TITLE', 'Cache Lebensdauer (Sekunden)');
define('MODULE_MRHANF_FPC_CACHE_TIME_DESC', 'Wie lange soll eine Seite im Cache bleiben? Standard: 86400 (24 Stunden)');
define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_TITLE', 'Ausgeschlossene Seiten');
define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_DESC', 'Kommagetrennte Liste von URL-Teilen, die NICHT gecacht werden sollen.');
define('MODULE_MRHANF_FPC_PRELOAD_LIMIT_TITLE', 'Max. Seiten pro Cron-Lauf');
define('MODULE_MRHANF_FPC_PRELOAD_LIMIT_DESC', 'Maximale Anzahl Seiten die pro Cron-Durchlauf gecacht werden. Standard: 500');
define('MODULE_MRHANF_FPC_SORT_ORDER_TITLE', 'Sortierreihenfolge');
define('MODULE_MRHANF_FPC_SORT_ORDER_DESC', 'Reihenfolge der Anzeige in der Modulliste.');

// Cache-Status Anzeige
define('MODULE_MRHANF_FPC_CACHED_PAGES', 'Gecachte Seiten:');
define('MODULE_MRHANF_FPC_CACHE_SIZE', 'Cache-Groesse:');
define('MODULE_MRHANF_FPC_LAST_RUN', 'Letzter Cron-Lauf:');
define('MODULE_MRHANF_FPC_NEVER', 'Noch nie');
define('MODULE_MRHANF_FPC_REBUILD_STATUS', 'Rebuild-Status:');
define('MODULE_MRHANF_FPC_REBUILD_RUNNING', 'Preloader laeuft...');

// Buttons
define('MODULE_MRHANF_FPC_BTN_REBUILD', 'Cache neu aufbauen');
define('MODULE_MRHANF_FPC_BTN_FLUSH', 'Cache leeren');
define('MODULE_MRHANF_FPC_BTN_STOP', 'Rebuild stoppen');

// Bestaetigungsdialoge
define('MODULE_MRHANF_FPC_REBUILD_CONFIRM', 'Cache jetzt neu aufbauen? Der Preloader wird im Hintergrund gestartet.');
define('MODULE_MRHANF_FPC_FLUSH_CONFIRM', 'Cache wirklich leeren? Alle gecachten Seiten werden geloescht.');
define('MODULE_MRHANF_FPC_STOP_CONFIRM', 'Laufenden Rebuild wirklich stoppen?');

// Erfolgsmeldungen
define('MODULE_MRHANF_FPC_REBUILD_STARTED', 'Cache-Rebuild wurde gestartet! Der Preloader laeuft im Hintergrund. Die Seite kann geschlossen werden.');
define('MODULE_MRHANF_FPC_FLUSH_SUCCESS', 'Cache wurde erfolgreich geleert!');
define('MODULE_MRHANF_FPC_REBUILD_STOPPED', 'Rebuild-Prozess wurde gestoppt.');

// Fehlermeldungen
define('MODULE_MRHANF_FPC_ERR_NO_PRELOADER', 'Fehler: fpc_preloader.php nicht im Shop-Root gefunden.');
define('MODULE_MRHANF_FPC_ERR_ALREADY_RUNNING', 'Ein Rebuild laeuft bereits! Bitte warten Sie bis der aktuelle Durchlauf abgeschlossen ist.');
define('MODULE_MRHANF_FPC_ERR_START_FAILED', 'Fehler: Konnte den Preloader-Prozess nicht starten. Bitte Serverrechte pruefen.');
