#!/usr/bin/env bash
# MyPresence — rilis tanpa downtime (dijalankan sebagai user aplikasi, BUKAN root):
#
#   sudo -u mypresence bash deploy/scripts/deploy.sh /home/mypresence/mypresence-release.zip
#   sudo -u mypresence bash deploy/scripts/deploy.sh --rollback      # kembali ke rilis sebelumnya
#
# Struktur:  /var/www/mypresence/current -> releases/20261103-210000   (Nginx root = current/public)
#            /var/www/mypresence/shared/.env, shared/storage, shared/composer.lock
# Urutan: ekstrak → tautkan .env & storage → composer → migrate → cache → ganti symlink atomik
#         → reload PHP-FPM (OPcache) → restart queue → cek /up. Simpan 5 rilis terakhir.
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www/mypresence}"
KEEP="${KEEP:-5}"
cd "$APP_DIR"
[ "$(id -u)" -ne 0 ] || { echo "Jangan jalankan sebagai root: sudo -u mypresence bash $0 ..."; exit 1; }
log() { printf '\n==> %s\n' "$*"; }
reload_fpm() { sudo -n /usr/bin/systemctl reload php8.3-fpm 2>/dev/null || echo "   (lewati reload php-fpm: tambahkan izin sudoers, lihat DEPLOY.md)"; }

if [ "${1:-}" = "--rollback" ]; then
  prev="$(ls -1d releases/*/ | sort | tail -n 2 | head -n 1)"; prev="${prev%/}"
  [ -n "$prev" ] && [ "$(readlink current)" != "$APP_DIR/$prev" ] || { echo "Tidak ada rilis sebelumnya."; exit 1; }
  ln -sfn "$APP_DIR/$prev" current.tmp && mv -Tf current.tmp current
  reload_fpm; php current/artisan queue:restart
  echo "Rollback ke $prev. (Migrasi database TIDAK dibatalkan otomatis.)"; exit 0
fi

SRC="${1:?Pakai: deploy.sh /path/mypresence-release.zip   atau   deploy.sh --rollback}"
[ -f shared/.env ] || { echo "shared/.env belum ada. Salin deploy/env.production.example lalu isi."; exit 1; }
grep -q '^APP_KEY=base64:' shared/.env || { echo "APP_KEY kosong. Jalankan: php -r \"echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;\" lalu isi di shared/.env"; exit 1; }

REL="releases/$(date +%Y%m%d-%H%M%S)"
log "Ekstrak $SRC → $REL"
mkdir -p "$REL"
unzip -q "$SRC" -d "$REL.tmp"
inner="$(find "$REL.tmp" -mindepth 1 -maxdepth 1)"; [ "$(echo "$inner" | wc -l)" -eq 1 ] && [ -d "$inner" ] && [ -f "$inner/artisan" ] || inner="$REL.tmp"
cp -a "$inner"/. "$REL"/ && rm -rf "$REL.tmp"
[ -f "$REL/artisan" ] || { echo "Arsip tidak berisi aplikasi Laravel (artisan tidak ditemukan)."; rm -rf "$REL"; exit 1; }

log "Tautkan .env & storage bersama"
rm -rf "$REL/storage" "$REL/.env"
ln -s "$APP_DIR/shared/storage" "$REL/storage"
ln -s "$APP_DIR/shared/.env" "$REL/.env"
VERSION="$(basename "$REL")"
sed -i "s/^APP_VERSION=.*/APP_VERSION=$VERSION/" shared/.env; grep -q '^APP_VERSION=' shared/.env || echo "APP_VERSION=$VERSION" >> shared/.env

log "Composer (tanpa paket dev)"
# composer.lock dipakai bersama antar rilis agar versi paket tidak berubah diam-diam.
[ -f "$REL/composer.lock" ] || { [ -f shared/composer.lock ] && cp shared/composer.lock "$REL/"; } || true
( cd "$REL" && COMPOSER_ALLOW_SUPERUSER=0 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress 2>&1 | tail -3 )
cp "$REL/composer.lock" shared/composer.lock

cd "$REL"
log "Mode pemeliharaan singkat + migrasi database"
# storage/ dipakai bersama, jadi mode pemeliharaan berlaku juga untuk rilis yang sedang aktif (±beberapa detik).
php artisan down --retry=15 >/dev/null 2>&1 || true
php artisan migrate --force || { php artisan up >/dev/null 2>&1; echo "!! Migrasi gagal; rilis lama tetap aktif."; exit 1; }
log "Cache konfigurasi, rute, tampilan, event"
php artisan optimize
php artisan view:cache >/dev/null
cd "$APP_DIR"

log "Aktifkan rilis (symlink atomik)"
ln -sfn "$APP_DIR/$REL" current.tmp && mv -Tf current.tmp current
reload_fpm
php current/artisan up >/dev/null 2>&1 || true
php current/artisan queue:restart

log "Cek kesehatan"
DOMAIN="$(grep -E '^APP_DOMAIN=' shared/.env | cut -d= -f2 | tr -d '"')"
HEALTH_URL="${HEALTH_URL:-https://$DOMAIN/up}"
if curl -fsS --max-time 10 --resolve "$DOMAIN:443:127.0.0.1" "$HEALTH_URL" >/dev/null; then
  echo "   $HEALTH_URL → OK"
else
  echo "!! /up gagal. Periksa: tail -50 shared/storage/logs/laravel-*.log   Rollback: bash $0 --rollback"; exit 1
fi

log "Bersihkan rilis lama (simpan $KEEP)"
ls -1d releases/*/ | sort | head -n -"$KEEP" | xargs -r rm -rf
echo "Rilis $VERSION aktif."
