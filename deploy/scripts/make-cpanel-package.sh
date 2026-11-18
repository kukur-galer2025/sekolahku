#!/usr/bin/env bash
# Paket siap unggah untuk SHARED HOSTING cPanel (tanpa SSH/Composer di server):
#   bash deploy/scripts/make-cpanel-package.sh [../mypresence-cpanel.zip]
# Isi zip:
#   mypresence/   → unggah ke /home/USER/mypresence   (aplikasi + vendor + .env berisi kunci acak)
#   public_html/  → isi diunggah ke /home/USER/public_html
#   PANDUAN-CPANEL.md
# Butuh composer + internet (Packagist). Di lingkungan tanpa Packagist: VENDOR_FROM=/path/vendor-produksi.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="${1:-$(dirname "$ROOT")/mypresence-cpanel-$(date +%Y%m%d-%H%M).zip}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/mypresence-cpanel"; mkdir -p "$PKG"

bash "$ROOT/deploy/scripts/make-release.sh" "$TMP/rel.zip" >/dev/null
unzip -q "$TMP/rel.zip" -d "$PKG"
APP="$PKG/mypresence"

if [ -n "${VENDOR_FROM:-}" ]; then
  cp -a "$VENDOR_FROM" "$APP/vendor"
else
  (cd "$APP" && composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --quiet)
fi
[ -f "$APP/vendor/autoload.php" ] || { echo "vendor gagal dibuat"; exit 1; }

# public_html = salinan public/ dengan index.php yang menunjuk ke ../mypresence
cp -a "$APP/public" "$PKG/public_html"
cp "$ROOT/deploy/cpanel/index.php" "$PKG/public_html/index.php"
cp "$ROOT/deploy/cpanel/.htaccess" "$PKG/public_html/.htaccess"

# .env dengan kunci acak unik untuk paket ini
KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
HEALTH="$(php -r 'echo bin2hex(random_bytes(24));')"
BKP="$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(24)), "+/", "-_"), "=");')"
sed -e "s#__APP_KEY__#$KEY#" -e "s#__HEALTH_TOKEN__#$HEALTH#" -e "s#__BACKUP_PASSWORD__#$BKP#" \
  "$ROOT/deploy/cpanel/env.cpanel.example" > "$APP/.env"
chmod 640 "$APP/.env"

# Folder tulis yang wajib ada
for d in app/private app/public framework/cache/data framework/sessions framework/views logs; do mkdir -p "$APP/storage/$d"; done
mkdir -p "$APP/bootstrap/cache"
cp "$ROOT/deploy/cpanel/PANDUAN-CPANEL.md" "$PKG/"

rm -f "$OUT"
(cd "$TMP" && zip -qr9 "$OUT" mypresence-cpanel)
echo "$OUT ($(du -h "$OUT" | cut -f1))"
echo "PENTING: zip ini berisi APP_KEY & BACKUP_PASSWORD unik — simpan baik-baik, jangan dibagikan."
