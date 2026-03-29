# Deployment v10.4.0 - Smart Cache Refresh

## Was ist neu?

Version 10.4.0 loest das groesste Problem des FPC-Systems: Der naechtliche Full Flush, der alle 30.000+ gecachten Seiten loescht und einen kompletten Rebuild erzwingt. Stattdessen werden abgelaufene Seiten jetzt **intelligent im Hintergrund erneuert**, waehrend Besucher dank **Stale-While-Revalidate** immer eine schnelle gecachte Seite sehen.

## Deployment-Befehle

```bash
cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www/

# 1. Preloader (Smart Refresh + Priority Modus)
curl -sL "https://raw.githubusercontent.com/mrhanf15-stack/mrhanf-fpc-modul/master/fpc_preloader.php" -o fpc_preloader.php

# 2. Serve (Stale-While-Revalidate)
curl -sL "https://raw.githubusercontent.com/mrhanf15-stack/mrhanf-fpc-modul/master/fpc_serve.php" -o fpc_serve.php

# 3. Flush (Sicherheitswarnung + neue Modi)
curl -sL "https://raw.githubusercontent.com/mrhanf15-stack/mrhanf-fpc-modul/master/fpc_flush.php" -o fpc_flush.php

# 4. OPcache leeren
curl -s "https://mr-hanf.de/opcache_reset.php?token=MrHanf2024Reset"
```

## Cron-Jobs aktualisieren

Die bisherigen Cron-Jobs sollten durch die neue Smart Refresh Strategie ersetzt werden:

**ALT (NICHT MEHR EMPFOHLEN):**
```bash
# Voller Flush jede Nacht -> loescht 30.000+ Seiten!
0 3 * * * cd /pfad/zum/shop && php fpc_flush.php
```

**NEU (EMPFOHLEN):**
```bash
# 1. Smart Refresh alle 2 Stunden - erneuert NUR abgelaufene Seiten
0 */2 * * * cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www && /usr/local/bin/php fpc_preloader.php --refresh >> cache/fpc/preloader.log 2>&1

# 2. Sitemap-Scan taeglich 2:00 Uhr - findet neue URLs
0 2 * * * cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www && /usr/local/bin/php fpc_preloader.php >> cache/fpc/preloader.log 2>&1

# 3. Stale-Cleanup taeglich 3:00 Uhr - loescht nur Dateien > 2x TTL
0 3 * * * cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www && /usr/local/bin/php fpc_flush.php --stale >> cache/fpc/flush.log 2>&1
```

## Testen nach Deployment

```bash
# 1. Cache-Statistik anzeigen
cd /home/www/doc/28856/dcp288560004/mr-hanf.de/www
php fpc_flush.php --stats

# 2. Smart Refresh testen (zeigt wie viele Dateien abgelaufen sind)
php fpc_preloader.php --refresh

# 3. Stale-While-Revalidate pruefen (Header checken)
curl -sI "https://mr-hanf.de/" | grep -i "X-FPC"
# Erwartet: X-FPC-Cache: HIT oder X-FPC-Cache: STALE
```

## Offene Punkte (User-Aktion erforderlich)

1. **Google Service Account JSON** - Muss neu heruntergeladen und hochgeladen werden:
   - Gehe zu: https://console.cloud.google.com → IAM & Admin → Service Accounts → Keys → Create JSON
   - Upload nach: `api/fpc/mrhanf-fpc-e5d274a51a0e.json`

2. **Sistrix API Key** - Muss erneuert werden:
   - Gehe zu: https://app.sistrix.com/account/api
   - Neuen Key im Dashboard unter Settings > API Credentials eintragen
