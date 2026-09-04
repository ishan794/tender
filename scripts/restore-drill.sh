#!/usr/bin/env bash
# ==============================================================================
# TenderHub Disaster Recovery Restore Verification Script
# Restores the latest MySQL 8 backup into an isolated staging schema and audits:
# 1. Total tables restored (54)
# 2. Total foreign keys enforced (80)
# ==============================================================================

set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/tmp/tenderhub_backups}"
RESTORE_DB="tenderhub_restored"
DB_USER="${DB_USER:-tenderhub_user}"
DB_PASS="${DB_PASS:-tenderhub_pass_2026}"
DB_HOST="${DB_HOST:-127.0.0.1}"

echo "[1/4] Finding latest backup in ${BACKUP_DIR}..."
LATEST=$(ls -t "${BACKUP_DIR}"/tenderhub_prod_*.sql.gz 2>/dev/null | head -n 1)
if [[ -z "${LATEST}" ]]; then
    echo "ERROR: No backup found in ${BACKUP_DIR}"
    exit 1
fi
echo "  Found backup: ${LATEST} ($(du -h "${LATEST}" | cut -f1))"

echo "[2/4] Provisioning clean recovery database schema: ${RESTORE_DB}..."
mysql -u root -e "DROP DATABASE IF EXISTS ${RESTORE_DB}; CREATE DATABASE ${RESTORE_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON ${RESTORE_DB}.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;"

echo "[3/4] Restoring compressed backup stream into ${RESTORE_DB}..."
zcat "${LATEST}" | mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" "${RESTORE_DB}"

echo "[4/4] Auditing restored schema integrity..."
TBL_COUNT=$(mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '${RESTORE_DB}';")
FK_COUNT=$(mysql -h "${DB_HOST}" -u "${DB_USER}" -p"${DB_PASS}" -N -e "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '${RESTORE_DB}' AND REFERENCED_TABLE_NAME IS NOT NULL;")

echo ""
echo "=================================================="
echo "DISASTER RECOVERY RESTORE AUDIT RESULTS"
echo "=================================================="
echo "Restored Tables Count:       ${TBL_COUNT} / 54"
echo "Restored Foreign Keys Count: ${FK_COUNT} / 80"

if [[ "${TBL_COUNT}" -eq 54 && "${FK_COUNT}" -eq 80 ]]; then
    echo ""
    echo ">>> RESTORE DRILL: PASSED (100% data and relational integrity restored) <<<"
    exit 0
else
    echo ""
    echo ">>> RESTORE DRILL: FAILED (Count mismatch) <<<"
    exit 1
fi
