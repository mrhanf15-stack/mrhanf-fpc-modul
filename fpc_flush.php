<?php
/**
 * Mr. Hanf Full Page Cache v6.0.0 - Cache Flush Script
 *
 * Leert den gesamten FPC-Cache oder einzelne Seiten.
 *
 * Aufruf:
 *   /usr/local/bin/php fpc_flush.php              # Gesamten Cache leeren
 *   /usr/local/bin/php fpc_flush.php --url /samen-shop/  # Einzelne Seite
 *   /usr/local/bin/php fpc_flush.php --expired     # Nur abgelaufene Dateien
 */

$cache_dir = __DIR__ . '/cache/fpc/';

if (!is_dir($cache_dir)) {
    echo '[FPC-Flush] Cache-Verzeichnis existiert nicht.' . "\n";
    exit(0);
}

// Argumente parsen
$mode = 'all';
$target_url = '';

if (isset($argv[1])) {
    if ($argv[1] === '--expired') {
        $mode = 'expired';
    } elseif ($argv[1] === '--url' && isset($argv[2])) {
        $mode = 'single';
        $target_url = $argv[2];
    } elseif ($argv[1] === '--help') {
        echo "Verwendung:\n";
        echo "  php fpc_flush.php              Gesamten Cache leeren\n";
        echo "  php fpc_flush.php --expired    Nur abgelaufene Dateien loeschen\n";
        echo "  php fpc_flush.php --url /pfad/ Einzelne Seite aus Cache entfernen\n";
        exit(0);
    }
}

$deleted = 0;

if ($mode === 'single') {
    // Einzelne Seite loeschen
    $clean = trim($target_url, '/');
    if ($clean === '') {
        $file = $cache_dir . 'index.html';
    } else {
        $file = $cache_dir . $clean . '/index.html';
    }
    if (is_file($file)) {
        unlink($file);
        $deleted++;
        echo '[FPC-Flush] Geloescht: ' . $file . "\n";
    } else {
        echo '[FPC-Flush] Datei nicht gefunden: ' . $file . "\n";
    }
} elseif ($mode === 'expired') {
    // Cache-TTL aus DB laden
    define('_VALID_XTC', true);
    if (is_file(__DIR__ . '/includes/configure.php')) {
        require_once(__DIR__ . '/includes/configure.php');
        $db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
        if (!$db->connect_error) {
            $r = $db->query("SELECT configuration_value FROM configuration WHERE configuration_key = 'MODULE_MRHANF_FPC_CACHE_TIME' LIMIT 1");
            $ttl = ($r && $row = $r->fetch_assoc()) ? (int) $row['configuration_value'] : 86400;
            $db->close();
        } else {
            $ttl = 86400;
        }
    } else {
        $ttl = 86400;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->isFile() && $file->getExtension() === 'html') {
            if ((time() - $file->getMTime()) > $ttl) {
                unlink($file->getRealPath());
                $deleted++;
            }
        }
    }
    echo '[FPC-Flush] ' . $deleted . ' abgelaufene Dateien geloescht.' . "\n";
} else {
    // Alles loeschen
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        if ($item->isDir()) {
            @rmdir($item->getRealPath());
        } elseif ($item->getFilename() !== '.gitkeep') {
            unlink($item->getRealPath());
            if ($item->getExtension() === 'html') {
                $deleted++;
            }
        }
    }
    echo '[FPC-Flush] Gesamter Cache geleert. ' . $deleted . ' Dateien geloescht.' . "\n";
}
