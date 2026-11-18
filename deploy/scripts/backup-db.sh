#!/usr/bin/env bash
# Backup database penuh harian (melengkapi backup per sekolah `school:backup`).
# Dipasang provision.sh ke /usr/local/bin/mypresence-backup-db dan dijalankan cron 00:45.
# Opsional salinan luar server: isi OFFSITE_CMD, mis. OFFSITE_CMD='rclone copy {} r2:mypresence-backup/db/'
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/mypresence}"
KEEP_DAYS="${KEEP_DAYS:-14}"
OUT="$APP_DIR/backups"
. <(grep -E '^(DB_DATABASE|DB_USERNAME|DB_PASSWORD|BACKUP_PASSWORD|OFFSITE_CMD)=' "$APP_DIR/shared/.env" | sed 's/^/export /')
TS="$(date +%Y%m%d-%H%M)"
FILE="$OUT/db-$TS.sql.gz"
umask 077
MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --quick --routines --no-tablespaces -u "$DB_USERNAME" "$DB_DATABASE" | gzip -9 > "$FILE"
# Enkripsi AES-256 bila BACKUP_PASSWORD diisi (sama dengan backup per sekolah).
if [ -n "${BACKUP_PASSWORD:-}" ]; then
  openssl enc -aes-256-cbc -pbkdf2 -salt -in "$FILE" -out "$FILE.enc" -pass env:BACKUP_PASSWORD && rm -f "$FILE" && FILE="$FILE.enc"
fi
find "$OUT" -name 'db-*.sql.gz*' -mtime +"$KEEP_DAYS" -delete
if [ -n "${OFFSITE_CMD:-}" ]; then eval "${OFFSITE_CMD//\{\}/$FILE}"; fi
echo "$(date -Is) backup OK: $FILE ($(du -h "$FILE" | cut -f1))"
# Pulihkan: gunzip -c db-....sql.gz | mysql -u root mypresence
#   (terenkripsi: openssl enc -d -aes-256-cbc -pbkdf2 -in db-....sql.gz.enc -pass pass:'...' | gunzip | mysql -u root mypresence)
