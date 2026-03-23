#!/bin/bash
# ============================================================
# Mr. Hanf FPC v8.0.1 — Deployment Script
# Ausfuehren auf dem Server per SSH:
#   bash DEPLOY_v8.sh
#
# WICHTIG: Nach dem Deployment muss die .htaccess manuell
# aktualisiert werden (siehe Schritt 7)!
#
# v8.0.1 Aenderungen:
#   - /kasse und /warenkorb zur Ausschlussliste hinzugefuegt
#   - Praeventive Absicherung fuer Payment/Kreditkarten-Seiten
# ============================================================

SHOPROOT="/home/www/doc/28856/dcp288560004/mr-hanf.de/www"
REPO="https://raw.githubusercontent.com/mrhanf15-stack/mrhanf-fpc-modul/master"

echo "=== Mr. Hanf FPC v8.0.1 Deployment ==="
echo ""

# 1. Backup der alten Dateien
echo "[1/8] Backup der alten Dateien..."
BACKUP_DIR="${SHOPROOT}/cache/fpc_backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "${BACKUP_DIR}"
cp -v "${SHOPROOT}/fpc_serve.php" "${BACKUP_DIR}/fpc_serve.php.bak" 2>/dev/null
cp -v "${SHOPROOT}/fpc_preloader.php" "${BACKUP_DIR}/fpc_preloader.php.bak" 2>/dev/null
cp -v "${SHOPROOT}/fpc_flush.php" "${BACKUP_DIR}/fpc_flush.php.bak" 2>/dev/null
cp -v "${SHOPROOT}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" "${BACKUP_DIR}/mrhanf_fpc_admin.php.bak" 2>/dev/null
cp -v "${SHOPROOT}/.htaccess" "${BACKUP_DIR}/htaccess.bak" 2>/dev/null
echo "Backup erstellt: ${BACKUP_DIR}/"
echo ""

# 2. v8.0.1 Dateien herunterladen
echo "[2/8] Lade v8.0.1 Dateien von GitHub..."
wget -q -O "${SHOPROOT}/fpc_serve.php" "${REPO}/fpc_serve.php" && echo "  fpc_serve.php OK" || echo "  fpc_serve.php FEHLER!"
wget -q -O "${SHOPROOT}/fpc_preloader.php" "${REPO}/fpc_preloader.php" && echo "  fpc_preloader.php OK" || echo "  fpc_preloader.php FEHLER!"
wget -q -O "${SHOPROOT}/fpc_flush.php" "${REPO}/fpc_flush.php" && echo "  fpc_flush.php OK" || echo "  fpc_flush.php FEHLER!"
wget -q -O "${SHOPROOT}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" "${REPO}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" && echo "  mrhanf_fpc.php (Admin) OK" || echo "  mrhanf_fpc.php (Admin) FEHLER!"
echo ""

# 3. Sprachdateien herunterladen
echo "[3/8] Lade Sprachdateien..."
for LANG in german english french spanish; do
  mkdir -p "${SHOPROOT}/lang/${LANG}/modules/system"
  wget -q -O "${SHOPROOT}/lang/${LANG}/modules/system/mrhanf_fpc.php" "${REPO}/lang/${LANG}/modules/system/mrhanf_fpc.php" && echo "  ${LANG} OK" || echo "  ${LANG} FEHLER!"
done
echo ""

# 4. Alten Cache leeren
echo "[4/8] Loesche alten Cache..."
find "${SHOPROOT}/cache/fpc/" -name "*.html" -delete 2>/dev/null
echo "Cache geleert."
echo ""

# 5. Debug-/Test-Dateien aufraeumen
echo "[5/8] Raeume Debug-Dateien auf..."
rm -f "${SHOPROOT}/fpc_profiler.php" 2>/dev/null && echo "  fpc_profiler.php entfernt" || true
rm -f "${SHOPROOT}/fpc_loadtest.sh" 2>/dev/null && echo "  fpc_loadtest.sh entfernt" || true
rm -f "${SHOPROOT}/fpc_spy.php" 2>/dev/null && echo "  fpc_spy.php entfernt" || true
rm -f "${SHOPROOT}/cache/fpc_spy.log" 2>/dev/null && echo "  fpc_spy.log entfernt" || true
rm -f "${SHOPROOT}/opcache_reset.php" 2>/dev/null && echo "  opcache_reset.php entfernt" || true
rm -f "${SHOPROOT}/index.php.bak" 2>/dev/null && echo "  index.php.bak entfernt" || true
echo ""

# 6. Version pruefen
echo "[6/8] Versionspruefung..."
head -3 "${SHOPROOT}/fpc_serve.php"
head -3 "${SHOPROOT}/fpc_preloader.php"
echo ""

# 7. .htaccess Hinweis
echo "============================================================"
echo "[7/8] WICHTIG: .htaccess MANUELL aktualisieren!"
echo "============================================================"
echo ""
echo "  Der Server hat aktuell v6.1.1 htaccess-Regeln."
echo "  Diese haben einen KRITISCHEN BUG: Die RewriteCond werden"
echo "  nicht fuer beide RewriteRules wiederholt!"
echo ""
echo "  SCHRITT 1: Alten FPC-Block entfernen"
echo "    Alles zwischen '# --- FPC START ---' und '# --- FPC ENDE ---'"
echo "    in der .htaccess loeschen."
echo ""
echo "  SCHRITT 2: Neuen v8.0.1 Block einfuegen"
echo "    Den Inhalt von htaccess_fpc_rules.txt am ANFANG der .htaccess"
echo "    einfuegen, VOR allen anderen RewriteRules."
echo ""
echo "  Die neuen Regeln finden Sie unter:"
echo "  ${REPO}/htaccess_fpc_rules.txt"
echo ""
echo "  AENDERUNGEN v8.0.1 gegenueber v6.1.1:"
echo "    - Apache liefert HTML DIREKT aus (kein PHP-Worker!)"
echo "    - Alle RewriteCond fuer BEIDE Rules wiederholt (Fix weisse Seiten)"
echo "    - /kasse und /warenkorb zur Ausschlussliste (Payment-Schutz)"
echo "    - Query-String Pruefung: ^$ statt !action= (strenger)"
echo "    - Cache-Control Header via mod_headers"
echo ""

# 8. Preloader testen (nur erste 10 Zeilen)
echo "[8/8] Teste Preloader (erste 10 Zeilen)..."
cd "${SHOPROOT}" && timeout 30 /usr/local/bin/php fpc_preloader.php 2>&1 | head -10

echo ""
echo "=== Deployment v8.0.1 abgeschlossen! ==="
echo ""
echo "Naechste Schritte:"
echo "  1. .htaccess FPC-Regeln aktualisieren (siehe Schritt 7)"
echo "  2. Shop im Browser testen (angemeldet + abgemeldet)"
echo "  3. Cron-Job pruefen: crontab -l"
echo "     Soll enthalten:"
echo "     0 */2 * * * cd ${SHOPROOT} && /usr/local/bin/php fpc_preloader.php >> cache/fpc/preloader.log 2>&1"
echo "     0 3 * * * cd ${SHOPROOT} && /usr/local/bin/php fpc_flush.php --expired >> cache/fpc/flush.log 2>&1"
echo ""
echo "Backup liegt unter: ${BACKUP_DIR}/"
