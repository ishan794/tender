#!/usr/bin/env bash
# ==============================================================================
# TenderHub Automated Daily Database Backup Script
# Retention: 30 days daily backups
# ==============================================================================

set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/tenderhub}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=30

mkdir -p "${BACKUP_DIR}"

DB_NAME="${DB_NAME:-tenderhub_prod}"
DB_USER="${DB_USER:-tenderhub_user}"
DB_HOST="${DB_HOST:-127.0.0.1}"

# The password is REQUIRED and never defaulted — a committed default is a
# published credential. Fail loudly if it is not supplied.
: "${DB_PASS:?DB_PASS must be set (do not hardcode a default)}"

BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.sql.gz"

# Pass credentials via a temp defaults-file rather than -p on the command line,
# so the password is not visible in `ps`/process listings. Cleaned up on exit.
MYSQL_CNF="$(mktemp)"
trap 'rm -f "${MYSQL_CNF}"' EXIT
cat > "${MYSQL_CNF}" <<EOF
[client]
user=${DB_USER}
password=${DB_PASS}
host=${DB_HOST}
EOF

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting MySQL 8 backup for database: ${DB_NAME}..."

# Execute mysqldump with single transaction to avoid locking tables
mysqldump --defaults-extra-file="${MYSQL_CNF}" \
    --single-transaction \
    --no-tablespaces \
    --quick \
    --routines \
    --triggers \
    "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup successfully created: ${BACKUP_FILE} ($(du -h "${BACKUP_FILE}" | cut -f1))"

# Prune backups older than 30 days
find "${BACKUP_DIR}" -name "${DB_NAME}_*.sql.gz" -mtime +"${RETENTION_DAYS}" -delete
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Cleaned up backups older than ${RETENTION_DAYS} days."
