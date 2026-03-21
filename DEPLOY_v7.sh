#!/bin/bash
# ============================================================
# Mr. Hanf FPC v7.0.1 — Deployment Script
# Ausfuehren auf dem Server per SSH:
#   bash DEPLOY_v7.sh
# ============================================================

SHOPROOT="/home/www/doc/28856/dcp288560004/mr-hanf.de/www"
REPO="https://raw.githubusercontent.com/mrhanf15-stack/mrhanf-fpc-modul/master"

echo "=== Mr. Hanf FPC v7.0.1 Deployment ==="
echo ""

# 1. Backup der alten Dateien
echo "[1/6] Backup der alten Dateien..."
mkdir -p "${SHOPROOT}/cache/fpc_backup_v6"
cp -v "${SHOPROOT}/fpc_serve.php" "${SHOPROOT}/cache/fpc_backup_v6/fpc_serve.php.bak" 2>/dev/null
cp -v "${SHOPROOT}/fpc_preloader.php" "${SHOPROOT}/cache/fpc_backup_v6/fpc_preloader.php.bak" 2>/dev/null
cp -v "${SHOPROOT}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" "${SHOPROOT}/cache/fpc_backup_v6/mrhanf_fpc_admin.php.bak" 2>/dev/null
echo ""

# 2. v7.0.1 Dateien herunterladen
echo "[2/6] Lade v7.0.1 Dateien von GitHub..."
wget -q -O "${SHOPROOT}/fpc_serve.php" "${REPO}/fpc_serve.php" && echo "  fpc_serve.php OK" || echo "  fpc_serve.php FEHLER!"
wget -q -O "${SHOPROOT}/fpc_preloader.php" "${REPO}/fpc_preloader.php" && echo "  fpc_preloader.php OK" || echo "  fpc_preloader.php FEHLER!"
wget -q -O "${SHOPROOT}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" "${REPO}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" && echo "  mrhanf_fpc.php (Admin) OK" || echo "  mrhanf_fpc.php (Admin) FEHLER!"
echo ""

# 3. Sprachdateien herunterladen (korrekter Pfad: lang/{sprache}/modules/system/)
echo "[3/6] Lade Sprachdateien..."
for LANG in german english french spanish; do
  mkdir -p "${SHOPROOT}/lang/${LANG}/modules/system"
  wget -q -O "${SHOPROOT}/lang/${LANG}/modules/system/mrhanf_fpc.php" "${REPO}/lang/${LANG}/modules/system/mrhanf_fpc.php" && echo "  ${LANG} OK" || echo "  ${LANG} FEHLER!"
done
echo ""

# 4. Alten Cache leeren (alte Dateien haben keinen FPC-VALID Marker)
echo "[4/6] Loesche alten Cache (ohne FPC-VALID Marker)..."
find "${SHOPROOT}/cache/fpc/" -name "*.html" -delete 2>/dev/null
echo "Cache geleert."
echo ""

# 5. Version pruefen
echo "[5/6] Versionspruefung..."
head -3 "${SHOPROOT}/fpc_serve.php"
head -3 "${SHOPROOT}/fpc_preloader.php"
grep "process" "${SHOPROOT}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" | head -2
echo ""

# 6. Preloader testen
echo "[6/6] Teste Preloader (erste 20 Zeilen)..."
cd "${SHOPROOT}" && /usr/local/bin/php fpc_preloader.php 2>&1 | head -20

echo ""
echo "=== Deployment abgeschlossen! ==="
echo "Der naechste Cron-Lauf wird den Cache mit v7.0.1 Validierung neu aufbauen."
echo "Backup der alten Dateien liegt unter: ${SHOPROOT}/cache/fpc_backup_v6/"
