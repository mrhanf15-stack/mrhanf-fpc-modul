<?php
/**
 * Mr. Hanf Full Page Cache v10.4.0 - Cache Flush Script
 *
 * Leert den gesamten FPC-Cache oder einzelne Seiten.
 * Config-Dateien liegen in api/fpc/ und sind NICHT betroffen.
 *
 * WICHTIG: Ab v10.4.0 wird empfohlen NUR --expired zu verwenden!
 * Der Smart Refresh Modus (fpc_preloader.php --refresh) erneuert
 * abgelaufene Dateien inkrementell. Ein voller Flush loescht alle
 * 30.000+ Dateien und erzwingt einen kompletten Rebuild.
 *
 * Aufruf:
 *   php fpc_flush.php --expired         # EMPFOHLEN: Nur abgelaufene Dateien (> TTL)
 *   php fpc_flush.php --stale           # NEU: Nur uralte Dateien (> 2x TTL)
 *   php fpc_flush.php --url /samen-shop/  # Einzelne Seite invalidieren
 *   php fpc_flush.php --pattern /samen-*  # NEU: Seiten nach Muster invalidieren
 *   php fpc_flush.php                   # WARNUNG: Gesamten Cache leeren (vermeiden!)
 *   php fpc_flush.php --force           # Gesamten Cache leeren ohne Warnung
 *
 * @version   10.4.0
 * @date      2026-03-29
 *
 * CHANGELOG v10.4.0:
 *   - NEU: --stale Modus (loescht nur Dateien > 2x TTL)
 *   - NEU: --pattern Modus (loescht Cache-Dateien nach URL-Muster)
 *   - NEU: Sicherheitswarnung bei vollem Flush (empfiehlt --expired)
 *   - NEU: --force Flag um Warnung zu ueberspringen
 *   - NEU: Statistik-Ausgabe (wie viele Dateien pro Altersgruppe)
 *   - VERBESSERT: Geschuetzte Verzeichnisse erweitert (logs/)
 *
 * CHANGELOG v8.2.0:
 *   - Config-Dateien in separatem Ordner api/fpc/ (nie vom Flush betroffen)
 *   - Flush loescht nur noch HTML-Cache-Dateien und leere Verzeichnisse
 *   - SEO-Daten (seo/, gsc/, ga4/) bleiben erhalten
 */

$cache_dir = __DIR__ . '/cache/fpc/';

if (!is_dir($cache_dir)) {
    echo '[FPC-Flush] Cache-Verzeichnis existiert nicht.' . "\n";
    exit(0);
}

// v10.4.0: Geschuetzte Unterordner (enthalten keine Cache-Dateien)
$protected_dirs = array('seo', 'gsc', 'ga4', 'sistrix', 'tracker', 'logs');

// Argumente parsen
$mode = 'all';
$target_url = '';
$target_pattern = '';
$force = false;

// --force kann ueberall stehen
foreach ($argv as $arg) {
    if ($arg === '--force') $force = true;
}

if (isset($argv[1])) {
    if ($argv[1] === '--expired') {
        $mode = 'expired';
    } elseif ($argv[1] === '--stale') {
        $mode = 'stale';
    } elseif ($argv[1] === '--url' && isset($argv[2])) {
        $mode = 'single';
        $target_url = $argv[2];
    } elseif ($argv[1] === '--pattern' && isset($argv[2])) {
        $mode = 'pattern';
        $target_pattern = $argv[2];
    } elseif ($argv[1] === '--force') {
        $mode = 'all';
        $force = true;
    } elseif ($argv[1] === '--stats') {
        $mode = 'stats';
    } elseif ($argv[1] === '--help') {
        echo "Mr. Hanf FPC v10.4.0 - Cache Flush\n\n";
        echo "Verwendung:\n";
        echo "  php fpc_flush.php --expired         EMPFOHLEN: Nur abgelaufene Dateien loeschen\n";
        echo "  php fpc_flush.php --stale           Nur uralte Dateien (> 2x TTL) loeschen\n";
        echo "  php fpc_flush.php --url /pfad/      Einzelne Seite aus Cache entfernen\n";
        echo "  php fpc_flush.php --pattern /samen-* Seiten nach Muster entfernen\n";
        echo "  php fpc_flush.php --stats           Cache-Statistik anzeigen (ohne Loeschen)\n";
        echo "  php fpc_flush.php --force           Gesamten Cache leeren (WARNUNG!)\n";
        echo "\nHinweis: Config-Dateien in api/fpc/ sind NICHT betroffen.\n";
        echo "Empfehlung: Verwende --expired + fpc_preloader.php --refresh statt vollem Flush.\n";
        exit(0);
    }
}

// TTL aus DB laden
$ttl = 86400; // Default: 24h
define('_VALID_XTC', true);
if (is_file(__DIR__ . '/includes/configure.php')) {
    require_once(__DIR__ . '/includes/configure.php');
    $db = @new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
    if (!$db->connect_error) {
        $r = $db->query("SELECT configuration_value FROM configuration WHERE configuration_key = 'MODULE_MRHANF_FPC_CACHE_TIME' LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            $ttl = (int) $row['configuration_value'];
        }
        $db->close();
    }
}

$deleted = 0;
$skipped = 0;

// ============================================================
// v10.4.0: STATISTIK-MODUS
// ============================================================
if ($mode === 'stats') {
    $fresh = 0; $expired = 0; $stale = 0; $ancient = 0;
    $total_size = 0;
    $now = time();

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'html') continue;
        $relative = str_replace($cache_dir, '', $file->getRealPath());
        $top_dir = explode('/', $relative)[0];
        if (in_array($top_dir, $protected_dirs)) continue;

        $age = $now - $file->getMTime();
        $total_size += $file->getSize();

        if ($age <= $ttl) {
            $fresh++;
        } elseif ($age <= $ttl * 2) {
            $expired++;
        } elseif ($age <= 604800) {
            $stale++;
        } else {
            $ancient++;
        }
    }

    $total = $fresh + $expired + $stale + $ancient;
    echo "[FPC-Flush] Cache-Statistik:\n";
    echo "  Gesamt:              " . $total . " Dateien (" . round($total_size / 1024 / 1024, 1) . " MB)\n";
    echo "  Frisch (< TTL):     " . $fresh . " (" . ($total > 0 ? round($fresh / $total * 100) : 0) . "%)\n";
    echo "  Abgelaufen (> TTL): " . $expired . " (" . ($total > 0 ? round($expired / $total * 100) : 0) . "%)\n";
    echo "  Stale (> 2x TTL):   " . $stale . " (" . ($total > 0 ? round($stale / $total * 100) : 0) . "%)\n";
    echo "  Uralt (> 7 Tage):   " . $ancient . " (" . ($total > 0 ? round($ancient / $total * 100) : 0) . "%)\n";
    echo "  TTL: " . $ttl . "s (" . round($ttl / 3600) . "h)\n";
    exit(0);
}

// ============================================================
// EINZELNE SEITE
// ============================================================
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
}

// ============================================================
// v10.4.0: PATTERN-MODUS
// ============================================================
elseif ($mode === 'pattern') {
    $clean_pattern = trim($target_pattern, '/');
    echo '[FPC-Flush] Pattern-Flush: ' . $clean_pattern . "\n";

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'html') continue;
        $relative = str_replace($cache_dir, '', $file->getRealPath());
        $top_dir = explode('/', $relative)[0];
        if (in_array($top_dir, $protected_dirs)) continue;

        // Pattern-Match (einfaches Wildcard)
        if (fnmatch($clean_pattern . '*', $relative) || fnmatch('*/' . $clean_pattern . '*', $relative)) {
            unlink($file->getRealPath());
            $deleted++;
        }
    }
    echo '[FPC-Flush] ' . $deleted . ' Dateien geloescht (Pattern: ' . $clean_pattern . ')' . "\n";
}

// ============================================================
// ABGELAUFENE DATEIEN (> TTL)
// ============================================================
elseif ($mode === 'expired') {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->isFile() && $file->getExtension() === 'html') {
            $relative = str_replace($cache_dir, '', $file->getRealPath());
            $top_dir = explode('/', $relative)[0];
            if (in_array($top_dir, $protected_dirs)) continue;

            if ((time() - $file->getMTime()) > $ttl) {
                unlink($file->getRealPath());
                $deleted++;
            }
        }
    }
    echo '[FPC-Flush] ' . $deleted . ' abgelaufene Dateien geloescht (> ' . $ttl . 's / ' . round($ttl / 3600) . 'h).' . "\n";
}

// ============================================================
// v10.4.0: STALE-MODUS (> 2x TTL)
// ============================================================
elseif ($mode === 'stale') {
    $stale_threshold = $ttl * 2;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if ($file->isFile() && $file->getExtension() === 'html') {
            $relative = str_replace($cache_dir, '', $file->getRealPath());
            $top_dir = explode('/', $relative)[0];
            if (in_array($top_dir, $protected_dirs)) continue;

            if ((time() - $file->getMTime()) > $stale_threshold) {
                unlink($file->getRealPath());
                $deleted++;
            }
        }
    }
    echo '[FPC-Flush] ' . $deleted . ' stale Dateien geloescht (> ' . $stale_threshold . 's / ' . round($stale_threshold / 3600) . 'h).' . "\n";
}

// ============================================================
// VOLLSTAENDIGER FLUSH (mit Warnung)
// ============================================================
else {
    // v10.4.0: Sicherheitswarnung
    if (!$force) {
        echo "==========================================================\n";
        echo "  WARNUNG: Voller Cache-Flush!\n";
        echo "==========================================================\n";
        echo "  Dies loescht ALLE gecachten Seiten (30.000+).\n";
        echo "  Der Cache muss danach komplett neu aufgebaut werden,\n";
        echo "  was mehrere Stunden dauert und den Server belastet.\n";
        echo "\n";
        echo "  EMPFEHLUNG: Verwende stattdessen:\n";
        echo "    php fpc_flush.php --expired    (nur abgelaufene Dateien)\n";
        echo "    php fpc_flush.php --stale      (nur uralte Dateien)\n";
        echo "    php fpc_flush.php --url /pfad/ (einzelne Seite)\n";
        echo "\n";
        echo "  Um den vollen Flush trotzdem auszufuehren:\n";
        echo "    php fpc_flush.php --force\n";
        echo "==========================================================\n";
        exit(1);
    }

    echo '[FPC-Flush] VOLLER FLUSH gestartet...' . "\n";

    // Loescht nur HTML-Cache-Dateien und leere Verzeichnisse.
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
            continue;
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
    echo '[FPC-Flush] HINWEIS: Starte fpc_preloader.php um den Cache neu aufzubauen.' . "\n";
}

// Verzeichnis-Schutz
if (!is_dir($cache_dir)) {
    @mkdir($cache_dir, 0777, true);
    echo '[FPC-Flush] Verzeichnis cache/fpc/ neu erstellt.' . "\n";
}
if (!is_file($cache_dir . '.gitkeep')) {
    @file_put_contents($cache_dir . '.gitkeep', '');
}
