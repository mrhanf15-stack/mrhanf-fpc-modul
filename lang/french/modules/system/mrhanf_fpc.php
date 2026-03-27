<?php
/**
 * Mr. Hanf Full Page Cache v8.0.8 - Fichier de langue francais
 */

// Parametres du module
define('MODULE_MRHANF_FPC_TITLE', 'Mr. Hanf Full Page Cache');
define('MODULE_MRHANF_FPC_DESC', 'Systeme de prechargement par cron. Apache livre les pages en cache directement comme fichiers HTML statiques - sans worker PHP.');
define('MODULE_MRHANF_FPC_STATUS_TITLE', 'Activer le module');
define('MODULE_MRHANF_FPC_STATUS_DESC', 'Le cache de page complete doit-il etre active?');
define('MODULE_MRHANF_FPC_CACHE_TIME_TITLE', 'Duree de vie du cache (secondes)');
define('MODULE_MRHANF_FPC_CACHE_TIME_DESC', 'Combien de temps une page doit-elle rester en cache? Par defaut: 86400 (24 heures)');
define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_TITLE', 'Pages exclues');
define('MODULE_MRHANF_FPC_EXCLUDED_PAGES_DESC', 'Liste separee par des virgules des parties d URL qui ne doivent PAS etre mises en cache.');
define('MODULE_MRHANF_FPC_PRELOAD_LIMIT_TITLE', 'Max. pages par execution cron');
define('MODULE_MRHANF_FPC_PRELOAD_LIMIT_DESC', 'Nombre maximum de pages a mettre en cache par execution cron. Par defaut: 500');
define('MODULE_MRHANF_FPC_SORT_ORDER_TITLE', 'Ordre de tri');
define('MODULE_MRHANF_FPC_SORT_ORDER_DESC', 'Ordre d affichage dans la liste des modules.');

// Affichage du statut du cache
define('MODULE_MRHANF_FPC_CACHED_PAGES', 'Pages en cache:');
define('MODULE_MRHANF_FPC_CACHE_SIZE', 'Taille du cache:');
define('MODULE_MRHANF_FPC_LAST_RUN', 'Derniere execution cron:');
define('MODULE_MRHANF_FPC_NEVER', 'Jamais');
define('MODULE_MRHANF_FPC_REBUILD_STATUS', 'Statut de reconstruction:');
define('MODULE_MRHANF_FPC_REBUILD_RUNNING', 'Preloader en cours...');

// Boutons
define('MODULE_MRHANF_FPC_BTN_REBUILD', 'Reconstruire le cache');
define('MODULE_MRHANF_FPC_BTN_FLUSH', 'Vider le cache');
define('MODULE_MRHANF_FPC_BTN_STOP', 'Arreter la reconstruction');

// Dialogues de confirmation
define('MODULE_MRHANF_FPC_REBUILD_CONFIRM', 'Reconstruire le cache maintenant? Le preloader sera lance en arriere-plan.');
define('MODULE_MRHANF_FPC_FLUSH_CONFIRM', 'Vraiment vider le cache? Toutes les pages en cache seront supprimees.');
define('MODULE_MRHANF_FPC_STOP_CONFIRM', 'Vraiment arreter la reconstruction en cours?');

// Messages de succes
define('MODULE_MRHANF_FPC_REBUILD_STARTED', 'Reconstruction du cache lancee! Le preloader tourne en arriere-plan. Vous pouvez fermer cette page.');
define('MODULE_MRHANF_FPC_FLUSH_SUCCESS', 'Cache vide avec succes!');
define('MODULE_MRHANF_FPC_REBUILD_STOPPED', 'Processus de reconstruction arrete.');

// Messages d erreur
define('MODULE_MRHANF_FPC_ERR_NO_PRELOADER', 'Erreur: fpc_preloader.php introuvable dans la racine du shop.');
define('MODULE_MRHANF_FPC_ERR_ALREADY_RUNNING', 'Une reconstruction est deja en cours! Veuillez attendre la fin de l execution actuelle.');
define('MODULE_MRHANF_FPC_ERR_START_FAILED', 'Erreur: Impossible de demarrer le processus preloader. Verifiez les permissions du serveur.');
