<?php
/**
 * Mr. Hanf Full Page Cache v8.2.0 - Cache Flush Script
 *
 * Leert den gesamten FPC-Cache oder einzelne Seiten.
 * Config-Dateien liegen in api/fpc/ und sind NICHT betroffen.
 *
 * Aufruf:
 *   /usr/local/bin/php fpc_flush.php              # Gesamten Cache leeren
 *   /usr/local/bin/php fpc_flush.php --url /samen-shop/  # Einzelne Seite
 *   /usr/local/bin/php fpc_flush.php --expired     # Nur abgelaufene Dateien
 *
 * @version   8.2.0
 * @date      2026-03-29
 *
 * CHANGELOG v8.2.0:
 *   - Config-Dateien in separatem Ordner api/fpc/ (nie vom Flush betroffen)
 *   - Flush loescht nur noch HTML-Cache-Dateien und leere Verzeichnisse
 *   - SEO-Daten (seo/, gsc/, ga4/) bleiben erhalten
 *
 * CHANGELOG v8.1.0:
 *   - FIX: Config-Dateien werden beim Flush NICHT mehr geloescht
 *
 * CHANGELOG v8.0.5:
 *   - FIX: Verzeichnis-Schutz - cache/fpc/ wird nach Flush automatisch
 *     neu erstellt (verhindert dass der Cronjob still fehlschlaegt)
 */

$cache_dir = __DIR__ . '/cache/fpc/';

if (!is_dir($cache_dir)) {
    echo '[FPC-Flush] Cache-Verzeichnis existiert nicht.' . "\n";
    exit(0);
}

// v8.2.0: Geschuetzte Unterordner (enthalten keine Cache-Dateien)
$protected_dirs = array('seo', 'gsc', 'ga4', 'sistrix', 'tracker');

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
        echo "Mr. Hanf FPC v8.2.0 - Cache Flush\n";
        echo "Verwendung:\n";
        echo "  php fpc_flush.php              Gesamten HTML-Cache leeren\n";
        echo "  php fpc_flush.php --expired    Nur abgelaufene Dateien loeschen\n";
        echo "  php fpc_flush.php --url /pfad/ Einzelne Seite aus Cache entfernen\n";
        echo "\nConfig-Dateien in api/fpc/ sind NICHT betroffen.\n";
        exit(0);
    }
}

$deleted = 0;
$skipped = 0;

if ($mode === 'single') {
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
    // === VOLLSTAENDIGER FLUSH ===
    // Loescht nur HTML-Cache-Dateien und leere Verzeichnisse.
    // Config liegt in api/fpc/ und ist nicht betroffen.
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $relative = str_replace($cache_dir, '', $item->getRealPath());
        $top_dir = explode('/', $relative)[0];

        // Geschuetzte Unterordner komplett ueberspringen
        if (in_array($top_dir, $protected_dirs)) {
            continue;
        }

        if ($item->isDir()) {
            @rmdir($item->getRealPath());
        } elseif ($item->getFilename() === '.gitkeep') {
            continue; // .gitkeep beibehalten
        } elseif ($item->getExtension() === 'html') {
            unlink($item->getRealPath());
            $deleted++;
        } else {
            // Sonstige Dateien (z.B. .json, .log, .pid) im cache/fpc/ loeschen
            // Config-Dateien liegen jetzt in api/fpc/
            unlink($item->getRealPath());
        }
    }
    echo '[FPC-Flush] Cache geleert. ' . $deleted . ' HTML-Dateien geloescht.' . "\n";
}

// Verzeichnis-Schutz
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0777, true);
    echo '[FPC-Flush] Verzeichnis cache/fpc/ neu erstellt.' . "\n";
}
if (!is_file($cache_dir . '.gitkeep')) {
    @file_put_contents($cache_dir . '.gitkeep', '');
}
