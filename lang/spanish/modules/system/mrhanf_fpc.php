<?php
/**
 * Mr. Hanf Full Page Cache v8.0.8 - Archivo de idioma espanol
 */

// Configuracion del modulo
define('MODULE_MRHANF_FPC_TITLE', 'Mr. Hanf Full Page Cache');
define('MODULE_MRHANF_FPC_DESC', 'Sistema de precarga basado en cron. Apache sirve las paginas en cache directamente como archivos HTML estaticos - sin worker PHP.');
define('MODULE_MRHANF_FPC_STATUS_TITLE', 'Activar modulo');
define('MODULE_MRHANF_FPC_STATUS_DESC', 'Debe activarse el cache de pagina completa?');
define('MODULE_MRHANF_FPC_CACHE_TIME_TITLE', 'Tiempo de vida del cache (segundos)');
define('MODULE_MRHANF_FPC_CACHE_TIME_DESC', 'Cuanto tiempo debe permanecer una pagina en cache? Por defecto: 86400 (24 horas)');
define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_TITLE', 'Paginas excluidas');
define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_DESC', 'Lista separada por comas de partes de URL que NO deben almacenarse en cache.');
define('MODULE_MRHANF_FPC_PRELOAD_LIMIT_TITLE', 'Max. paginas por ejecucion cron');
define('MODULE_MRHANF_FPC_PRELOAD_LIMIT_DESC', 'Numero maximo de paginas a cachear por ejecucion cron. Por defecto: 500');
define('MODULE_MRHANF_FPC_SORT_ORDER_TITLE', 'Orden de clasificacion');
define('MODULE_MRHANF_FPC_SORT_ORDER_DESC', 'Orden de visualizacion en la lista de modulos.');

// Visualizacion del estado del cache
define('MODULE_MRHANF_FPC_CACHED_PAGES', 'Paginas en cache:');
define('MODULE_MRHANF_FPC_CACHE_SIZE', 'Tamano del cache:');
define('MODULE_MRHANF_FPC_LAST_RUN', 'Ultima ejecucion cron:');
define('MODULE_MRHANF_FPC_NEVER', 'Nunca');
define('MODULE_MRHANF_FPC_REBUILD_STATUS', 'Estado de reconstruccion:');
define('MODULE_MRHANF_FPC_REBUILD_RUNNING', 'Preloader en ejecucion...');

// Botones
define('MODULE_MRHANF_FPC_BTN_REBUILD', 'Reconstruir cache');
define('MODULE_MRHANF_FPC_BTN_FLUSH', 'Vaciar cache');
define('MODULE_MRHANF_FPC_BTN_STOP', 'Detener reconstruccion');

// Dialogos de confirmacion
define('MODULE_MRHANF_FPC_REBUILD_CONFIRM', 'Reconstruir el cache ahora? El preloader se ejecutara en segundo plano.');
define('MODULE_MRHANF_FPC_FLUSH_CONFIRM', 'Realmente vaciar el cache? Todas las paginas en cache seran eliminadas.');
define('MODULE_MRHANF_FPC_STOP_CONFIRM', 'Realmente detener la reconstruccion en curso?');

// Mensajes de exito
define('MODULE_MRHANF_FPC_REBUILD_STARTED', 'Reconstruccion del cache iniciada! El preloader se ejecuta en segundo plano. Puede cerrar esta pagina.');
define('MODULE_MRHANF_FPC_FLUSH_SUCCESS', 'Cache vaciado con exito!');
define('MODULE_MRHANF_FPC_REBUILD_STOPPED', 'Proceso de reconstruccion detenido.');

// Mensajes de error
define('MODULE_MRHANF_FPC_ERR_NO_PRELOADER', 'Error: fpc_preloader.php no encontrado en la raiz del shop.');
define('MODULE_MRHANF_FPC_ERR_ALREADY_RUNNING', 'Ya hay una reconstruccion en curso! Por favor espere a que termine la ejecucion actual.');
define('MODULE_MRHANF_FPC_ERR_START_FAILED', 'Error: No se pudo iniciar el proceso preloader. Verifique los permisos del servidor.');
