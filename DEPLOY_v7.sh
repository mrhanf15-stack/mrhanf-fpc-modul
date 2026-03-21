#!/bin/bash
# ============================================================
# Mr. Hanf FPC v7.0.0 — Deployment Script
# Ausfuehren auf dem Server per SSH:
#   bash DEPLOY_v7.sh
# ============================================================

SHOPROOT="/home/www/doc/28856/dcp288560004/mr-hanf.de/www"
REPO="https://raw.githubusercontent.com/mrhanf15-stack/mrhanf-fpc-modul/master"

echo "=== Mr. Hanf FPC v7.0.0 Deployment ==="
echo ""

# 1. Backup der alten Dateien
echo "[1/6] Backup der alten v6.x Dateien..."
mkdir -p "${SHOPROOT}/cache/fpc_backup_v6"
cp -v "${SHOPROOT}/fpc_serve.php" "${SHOPROOT}/cache/fpc_backup_v6/fpc_serve.php.bak" 2>/dev/null
cp -v "${SHOPROOT}/fpc_preloader.php" "${SHOPROOT}/cache/fpc_backup_v6/fpc_preloader.php.bak" 2>/dev/null
cp -v "${SHOPROOT}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" "${SHOPROOT}/cache/fpc_backup_v6/mrhanf_fpc_admin.php.bak" 2>/dev/null
echo ""

# 2. v7.0 Dateien herunterladen
echo "[2/6] Lade v7.0 Dateien von GitHub..."
wget -O "${SHOPROOT}/fpc_serve.php" "${REPO}/fpc_serve.php"
wget -O "${SHOPROOT}/fpc_preloader.php" "${REPO}/fpc_preloader.php"
wget -O "${SHOPROOT}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php" "${REPO}/admin_q9wKj6Ds/includes/modules/system/mrhanf_fpc.php"
echo ""

# 3. Sprachdateien herunterladen
echo "[3/6] Lade Sprachdateien..."
mkdir -p "${SHOPROOT}/lang/german/extra/admin"
mkdir -p "${SHOPROOT}/lang/english/extra/admin"
mkdir -p "${SHOPROOT}/lang/french/extra/admin"
mkdir -p "${SHOPROOT}/lang/spanish/extra/admin"
wget -O "${SHOPROOT}/lang/german/extra/admin/mrhanf_fpc.php" "${REPO}/lang/german/extra/admin/mrhanf_fpc.php"
wget -O "${SHOPROOT}/lang/english/extra/admin/mrhanf_fpc.php" "${REPO}/lang/english/extra/admin/mrhanf_fpc.php"
wget -O "${SHOPROOT}/lang/french/extra/admin/mrhanf_fpc.php" "${REPO}/lang/french/extra/admin/mrhanf_fpc.php"
wget -O "${SHOPROOT}/lang/spanish/extra/admin/mrhanf_fpc.php" "${REPO}/lang/spanish/extra/admin/mrhanf_fpc.php"
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
echo "[6/6] Teste Preloader (erste 5 URLs)..."
cd "${SHOPROOT}" && /usr/local/bin/php fpc_preloader.php 2>&1 | head -20

echo ""
echo "=== Deployment abgeschlossen! ==="
echo "Der naechste Cron-Lauf wird den Cache mit v7.0 Validierung neu aufbauen."
echo "Backup der alten Dateien liegt unter: ${SHOPROOT}/cache/fpc_backup_v6/"
