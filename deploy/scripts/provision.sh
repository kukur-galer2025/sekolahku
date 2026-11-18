#!/usr/bin/env bash
# MyPresence — siapkan VPS baru (Ubuntu 24.04 LTS, minimal 2 vCPU / 2 GB RAM / 40 GB SSD).
# Jalankan SEKALI sebagai root:
#
#   DOMAIN=mypresence.id bash deploy/scripts/provision.sh
#
# Yang dipasang: Nginx, PHP 8.3-FPM (+ekstensi), MySQL 8, Redis, Supervisor (queue worker),
# Composer, cron scheduler, backup database harian, firewall UFW, fail2ban, swap (bila RAM < 4 GB).
# Aman dijalankan ulang (idempoten).
set -euo pipefail

: "${DOMAIN:?Isi DOMAIN, mis. DOMAIN=mypresence.id}"
APP_USER="${APP_USER:-mypresence}"
APP_DIR="${APP_DIR:-/var/www/mypresence}"
DB_NAME="${DB_NAME:-mypresence}"
DB_USER="${DB_USER:-mypresence}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"   # folder deploy/

[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root (sudo -i)."; exit 1; }
. /etc/os-release
[ "${VERSION_ID:-}" = "24.04" ] || echo "!! Diuji untuk Ubuntu 24.04; terdeteksi ${PRETTY_NAME:-?}. Lanjut dengan hati-hati."

echo "==> [1/10] Paket sistem"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx mysql-server redis-server supervisor unzip git curl ufw fail2ban cron logrotate \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-sqlite3 php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-gd php8.3-intl php8.3-bcmath php8.3-redis php8.3-opcache composer
timedatectl set-timezone Asia/Jakarta || true

echo "==> [2/10] Swap (bila RAM < 4 GB)"
if [ "$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)" -lt 4000 ] && ! swapon --show | grep -q .; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  sysctl -q vm.swappiness=10; echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
fi

echo "==> [3/10] Pengguna aplikasi & folder rilis"
id "$APP_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$APP_USER"
usermod -aG www-data "$APP_USER"
install -d -o "$APP_USER" -g www-data -m 2775 "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared" "$APP_DIR/backups"
for d in app/private app/public framework/cache/data framework/sessions framework/views logs; do
  install -d -o "$APP_USER" -g www-data -m 2775 "$APP_DIR/shared/storage/$d"
done

# Deploy boleh me-reload PHP-FPM (membersihkan OPcache) tanpa akses root penuh.
echo "$APP_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm" > /etc/sudoers.d/mypresence
chmod 440 /etc/sudoers.d/mypresence && visudo -cf /etc/sudoers.d/mypresence >/dev/null

echo "==> [4/10] MySQL: database & user"
CRED=/root/mypresence-db.txt
if [ ! -f "$CRED" ]; then
  DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-28)"
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
  umask 077; printf 'DB_DATABASE=%s\nDB_USERNAME=%s\nDB_PASSWORD=%s\n' "$DB_NAME" "$DB_USER" "$DB_PASS" > "$CRED"
fi
cat > /etc/mysql/mysql.conf.d/zz-mypresence.cnf <<'CNF'
[mysqld]
# 2 GB RAM: cukup untuk puluhan sekolah. Naikkan buffer pool bila RAM VPS ditambah.
innodb_buffer_pool_size = 512M
max_connections = 150
default_time_zone = '+00:00'
bind-address = 127.0.0.1
CNF
systemctl restart mysql

echo "==> [5/10] Redis (cache, sesi, antrean) — hanya localhost"
sed -i 's/^#\? *maxmemory .*/maxmemory 256mb/; s/^#\? *maxmemory-policy .*/maxmemory-policy volatile-lru/' /etc/redis/redis.conf
grep -q '^maxmemory ' /etc/redis/redis.conf || printf 'maxmemory 256mb\nmaxmemory-policy volatile-lru\n' >> /etc/redis/redis.conf
systemctl enable --now redis-server >/dev/null && systemctl restart redis-server

echo "==> [6/10] PHP-FPM pool & OPcache"
sed "s/__APP_USER__/$APP_USER/g" "$HERE/php/mypresence-fpm.conf" > /etc/php/8.3/fpm/pool.d/mypresence.conf
rm -f /etc/php/8.3/fpm/pool.d/www.conf
cp "$HERE/php/99-mypresence.ini" /etc/php/8.3/fpm/conf.d/99-mypresence.ini
cp "$HERE/php/99-mypresence.ini" /etc/php/8.3/cli/conf.d/99-mypresence.ini
systemctl restart php8.3-fpm

echo "==> [7/10] Nginx (situs diaktifkan setelah SSL wildcard terpasang)"
sed "s#mypresence\.id#$DOMAIN#g; s#/var/www/mypresence#$APP_DIR#g" "$HERE/nginx/mypresence.conf" > /etc/nginx/sites-available/mypresence
sed -i 's/^\s*#\?\s*server_tokens .*/\tserver_tokens off;/' /etc/nginx/nginx.conf
if [ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
  ln -sf /etc/nginx/sites-available/mypresence /etc/nginx/sites-enabled/mypresence
  rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx
else
  echo "   Sertifikat belum ada → jalankan deploy/scripts/ssl-wildcard.sh setelah ini."
fi

echo "==> [8/10] Supervisor queue worker & cron scheduler"
sed "s#__APP_DIR__#$APP_DIR#g; s#__APP_USER__#$APP_USER#g" "$HERE/supervisor/mypresence-worker.conf" > /etc/supervisor/conf.d/mypresence-worker.conf
sed "s#__APP_DIR__#$APP_DIR#g; s#__APP_USER__#$APP_USER#g" "$HERE/cron/mypresence" > /etc/cron.d/mypresence
chmod 644 /etc/cron.d/mypresence
install -m 750 "$HERE/scripts/backup-db.sh" /usr/local/bin/mypresence-backup-db
systemctl enable --now supervisor cron >/dev/null
supervisorctl reread >/dev/null && supervisorctl update >/dev/null || true

echo "==> [9/10] Logrotate untuk log Nginx/Laravel sudah bawaan; log worker dirotasi di sini"
cat > /etc/logrotate.d/mypresence <<ROT
$APP_DIR/shared/storage/logs/worker.log $APP_DIR/shared/storage/logs/schedule.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
ROT

echo "==> [10/10] Firewall & fail2ban"
ufw allow OpenSSH >/dev/null; ufw allow 'Nginx Full' >/dev/null; ufw --force enable >/dev/null
systemctl enable --now fail2ban >/dev/null

cat <<DONE

================================================================
 VPS siap. Langkah berikutnya (lihat deploy/DEPLOY.md):
   1. SSL wildcard : DOMAIN=$DOMAIN EMAIL=... CF_API_TOKEN=... bash deploy/scripts/ssl-wildcard.sh
   2. .env produksi: cp deploy/env.production.example $APP_DIR/shared/.env  lalu isi
                    (kredensial DB ada di $CRED)
   3. Rilis pertama: sudo -u $APP_USER bash deploy/scripts/deploy.sh /path/mypresence-release.zip
================================================================
DONE
