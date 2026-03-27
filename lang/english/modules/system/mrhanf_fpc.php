<?php
/**
 * Mr. Hanf Full Page Cache v8.0.8 - English Language File
 */

// Module settings
define('MODULE_MRHANF_FPC_TITLE', 'Mr. Hanf Full Page Cache');
define('MODULE_MRHANF_FPC_DESC', 'Cron-based preloading system. Apache serves cached pages directly as static HTML files - no PHP worker needed.');
define('MODULE_MRHANF_FPC_STATUS_TITLE', 'Enable Module');
define('MODULE_MRHANF_FPC_STATUS_DESC', 'Should the Full Page Cache be enabled?');
define('MODULE_MRHANF_FPC_CACHE_TIME_TITLE', 'Cache Lifetime (seconds)');
define('MODULE_MRHANF_FPC_CACHE_TIME_DESC', 'How long should a page stay in cache? Default: 86400 (24 hours)');
define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_TITLE', 'Excluded Pages');
define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_DESC', 'Comma-separated list of URL parts that should NOT be cached.');
define('MODULE_MRHANF_FPC_PRELOAD_LIMIT_TITLE', 'Max. Pages per Cron Run');
define('MODULE_MRHANF_FPC_PRELOAD_LIMIT_DESC', 'Maximum number of pages to cache per cron run. Default: 500');
define('MODULE_MRHANF_FPC_SORT_ORDER_TITLE', 'Sort Order');
define('MODULE_MRHANF_FPC_SORT_ORDER_DESC', 'Display order in the module list.');

// Cache status display
define('MODULE_MRHANF_FPC_CACHED_PAGES', 'Cached Pages:');
define('MODULE_MRHANF_FPC_CACHE_SIZE', 'Cache Size:');
define('MODULE_MRHANF_FPC_LAST_RUN', 'Last Cron Run:');
define('MODULE_MRHANF_FPC_NEVER', 'Never');
define('MODULE_MRHANF_FPC_REBUILD_STATUS', 'Rebuild Status:');
define('MODULE_MRHANF_FPC_REBUILD_RUNNING', 'Preloader is running...');

// Buttons
define('MODULE_MRHANF_FPC_BTN_REBUILD', 'Rebuild Cache');
define('MODULE_MRHANF_FPC_BTN_FLUSH', 'Clear Cache');
define('MODULE_MRHANF_FPC_BTN_STOP', 'Stop Rebuild');

// Confirmation dialogs
define('MODULE_MRHANF_FPC_REBUILD_CONFIRM', 'Rebuild cache now? The preloader will run in the background.');
define('MODULE_MRHANF_FPC_FLUSH_CONFIRM', 'Really clear the cache? All cached pages will be deleted.');
define('MODULE_MRHANF_FPC_STOP_CONFIRM', 'Really stop the running rebuild?');

// Success messages
define('MODULE_MRHANF_FPC_REBUILD_STARTED', 'Cache rebuild started! The preloader is running in the background. You can close this page.');
define('MODULE_MRHANF_FPC_FLUSH_SUCCESS', 'Cache cleared successfully!');
define('MODULE_MRHANF_FPC_REBUILD_STOPPED', 'Rebuild process has been stopped.');

// Error messages
define('MODULE_MRHANF_FPC_ERR_NO_PRELOADER', 'Error: fpc_preloader.php not found in shop root.');
define('MODULE_MRHANF_FPC_ERR_ALREADY_RUNNING', 'A rebuild is already running! Please wait until the current run is finished.');
define('MODULE_MRHANF_FPC_ERR_START_FAILED', 'Error: Could not start the preloader process. Please check server permissions.');
