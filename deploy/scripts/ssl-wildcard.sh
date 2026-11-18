#!/usr/bin/env bash
# MyPresence — sertifikat SSL WILDCARD (*.domain + domain) dari Let's Encrypt via DNS-01 (Cloudflare).
# Checklist item 10. Jalankan sekali di VPS sebagai root setelah DNS diarahkan ke VPS.
#
#   DOMAIN=mypresence.id EMAIL=admin@paramatech.id CF_API_TOKEN=xxxx bash deploy/scripts/ssl-wildcard.sh
#
# CF_API_TOKEN: Cloudflare → My Profile → API Tokens → template "Edit zone DNS", zona mypresence.id saja.
set -euo pipefail

: "${DOMAIN:?Isi DOMAIN, mis. mypresence.id}"
: "${EMAIL:?Isi EMAIL untuk notifikasi LetsEncrypt}"
: "${CF_API_TOKEN:?Isi CF_API_TOKEN (izin Zone:DNS:Edit)}"

echo "==> Memasang certbot + plugin Cloudflare"
apt-get update -qq
apt-get install -y -qq certbot python3-certbot-dns-cloudflare

echo "==> Menyimpan kredensial Cloudflare (hanya root yang bisa membaca)"
install -d -m 700 /root/.secrets
umask 077
printf 'dns_cloudflare_api_token = %s\n' "$CF_API_TOKEN" > /root/.secrets/cloudflare.ini

echo "==> Meminta sertifikat untuk $DOMAIN dan *.$DOMAIN"
certbot certonly --non-interactive --agree-tos -m "$EMAIL" \
  --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini \
  --dns-cloudflare-propagation-seconds 30 \
  -d "$DOMAIN" -d "*.$DOMAIN"

echo "==> Reload Nginx otomatis setiap kali sertifikat diperpanjang"
install -d /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh <<'HOOK'
#!/bin/sh
systemctl reload nginx
HOOK
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh

echo "==> Uji perpanjangan (dry run)"
certbot renew --dry-run

echo "==> Aktifkan situs MyPresence di Nginx"
if [ -f /etc/nginx/sites-available/mypresence ]; then
  ln -sf /etc/nginx/sites-available/mypresence /etc/nginx/sites-enabled/mypresence
  rm -f /etc/nginx/sites-enabled/default
fi
nginx -t && systemctl reload nginx
echo "Selesai. Sertifikat: /etc/letsencrypt/live/$DOMAIN/ (cocok dengan deploy/nginx/mypresence.conf)"
echo "Cek: curl -I https://$DOMAIN && curl -I https://demo.$DOMAIN"
