# Go-live MyPresence di VPS — langkah demi langkah

Perkiraan waktu: **±2 jam** (di luar menunggu DNS). Semua perintah dijalankan di VPS lewat SSH.

| Kebutuhan | Rekomendasi awal |
|---|---|
| VPS | Ubuntu **24.04 LTS**, 2 vCPU, 2–4 GB RAM, 40 GB SSD, lokasi Indonesia/Singapura (mis. IDCloudHost, Biznet Gio, DigitalOcean SGP) |
| Domain | `mypresence.id` (PANDI) — DNS dikelola di **Cloudflare** (gratis; dibutuhkan untuk SSL wildcard otomatis) |
| Email keluar | SMTP transaksional: Brevo / Mailgun / Zoho / Google Workspace |
| Pembayaran | Akun Midtrans **atau** Xendit (boleh mulai dengan `PAYMENT_GATEWAY=manual`) |

---

## 0. Di komputer Bro: buat paket rilis

```bash
cd mypresence
bash deploy/scripts/make-release.sh ../mypresence-final.zip
scp ../mypresence-final.zip root@IP_VPS:/root/
```

(Atau langsung upload `mypresence-final.zip` yang sudah disiapkan — isinya sama.)

## 1. Domain & DNS

1. Beli `mypresence.id`, lalu di registrar ganti **nameserver** ke Cloudflare (Cloudflare → Add site).
2. Di Cloudflare → DNS, buat record (detail: `deploy/DNS.md`):

   | Tipe | Nama | Nilai | Proxy |
   |---|---|---|---|
   | A | `@` | IP VPS | DNS only (abu-abu) |
   | A | `*` | IP VPS | DNS only (abu-abu) |

   > Mulai dengan **DNS only**. Kalau nanti ingin Proxied (oranye), isi `TRUSTED_PROXIES` dengan IP Cloudflare,
   > kalau tidak cek WiFi sekolah akan menolak semua siswa.
3. Buat API token: Cloudflare → My Profile → API Tokens → template **Edit zone DNS** → zona `mypresence.id`.

## 2. Siapkan server (sekali)

```bash
ssh root@IP_VPS
unzip mypresence-final.zip && cd mypresence
DOMAIN=mypresence.id bash deploy/scripts/provision.sh
```

Yang terpasang: Nginx, PHP 8.3-FPM, MySQL 8, Redis, Supervisor (2 queue worker), cron scheduler,
backup database harian 00:45, UFW (22/80/443), fail2ban, swap 2 GB. Kredensial database: `/root/mypresence-db.txt`.

> **Keamanan SSH (disarankan):** buat SSH key, lalu matikan login password di `/etc/ssh/sshd_config`
> (`PasswordAuthentication no`) dan `systemctl reload ssh`.

## 3. SSL wildcard (`*.mypresence.id` + `mypresence.id`)

```bash
DOMAIN=mypresence.id EMAIL=admin@paramatech.id CF_API_TOKEN=xxxx bash deploy/scripts/ssl-wildcard.sh
php -v >/dev/null && nginx -t
```

Sertifikat diperpanjang otomatis (certbot timer) dan Nginx di-reload sendiri.

## 4. Isi `.env` produksi

```bash
cp deploy/env.production.example /var/www/mypresence/shared/.env
chown mypresence:www-data /var/www/mypresence/shared/.env && chmod 640 /var/www/mypresence/shared/.env
nano /var/www/mypresence/shared/.env
```

Wajib diisi (bertanda `[WAJIB]`):

| Kunci | Isi |
|---|---|
| `APP_KEY` | `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` |
| `DB_PASSWORD` | dari `/root/mypresence-db.txt` |
| `MAIL_*` | SMTP; uji di langkah 7 |
| `BACKUP_PASSWORD` | sandi panjang acak — **simpan juga di password manager** (tanpa ini backup tidak bisa dibuka) |
| `HEALTH_TOKEN` | `openssl rand -hex 24` |
| `OPS_ALERT_EMAIL` | email tim untuk alert error |
| `BILLING_*` | harga per siswa, rekening, data penerbit invoice |

## 5. Rilis pertama

```bash
cp /root/mypresence-final.zip /home/mypresence/ && chown mypresence /home/mypresence/mypresence-final.zip
sudo -u mypresence bash /root/mypresence/deploy/scripts/deploy.sh /home/mypresence/mypresence-final.zip
```

Skrip: ekstrak ke `releases/…` → `composer install --no-dev` → `migrate` → cache → ganti symlink `current`
(atomik, tanpa downtime) → reload PHP-FPM → restart worker → cek `https://mypresence.id/up`.

Rilis berikutnya: perintah yang sama dengan zip baru. Ada masalah? `deploy.sh --rollback`.

## 6. Akun super-admin Parama Tech

```bash
cd /var/www/mypresence/current
sudo -u mypresence php artisan platform:superadmin superadmin admin@paramatech.id "Tim Parama Tech"
```

Buka `https://mypresence.id/panel/masuk` → aktifkan 2FA (Google Authenticator) → **simpan kode cadangan**.
HP 2FA hilang: jalankan perintah yang sama dengan `--reset-2fa`.

> Jangan jalankan `db:seed` di produksi — seeder membuat sekolah demo dan password bawaan.

## 7. Uji sebelum diumumkan

```bash
cd /var/www/mypresence/current
sudo -u mypresence php artisan tenancy:dns-check --expect=IP_VPS     # DNS @ dan * sudah benar
curl -s "https://mypresence.id/health?token=HEALTH_TOKEN" | head -c 400  # semua komponen ok
sudo -u mypresence php artisan tinker --execute="Mail::raw('Tes SMTP MyPresence', fn(\$m) => \$m->to('emailmu@gmail.com')->subject('Tes'));"
sudo supervisorctl status                                              # 2 worker RUNNING
```

Lalu dari HP:
1. `https://mypresence.id/daftar` → daftarkan sekolah percobaan → cek email verifikasi masuk.
2. Login admin di `https://{sekolah}.mypresence.id` → impor Excel guru/siswa → **Cetak QR** kelas.
3. Pengaturan Presensi → dari WiFi sekolah tekan **Daftarkan jaringan ini**.
4. Login siswa di HP (Chrome/Safari) → **Pasang aplikasi** → scan QR kelas dari WiFi sekolah → tercatat Hadir.
5. Matikan data HP → buka aplikasi → jadwal & agenda tetap terbaca (mode offline).

## 8. Pembayaran

- Midtrans: Dashboard → Settings → Payment → **Notification URL** `https://mypresence.id/webhooks/midtrans`;
  isi `MIDTRANS_SERVER_KEY`, `MIDTRANS_PRODUCTION=true`, `PAYMENT_GATEWAY=midtrans`.
- Xendit: Settings → Callbacks → **Invoices paid** `https://mypresence.id/webhooks/xendit`;
  isi `XENDIT_SECRET_KEY`, `XENDIT_CALLBACK_TOKEN`, `PAYMENT_GATEWAY=xendit`.
- Setelah mengubah `.env`: `sudo -u mypresence php /var/www/mypresence/current/artisan optimize`.
- Uji sekali di mode sandbox dulu (`MIDTRANS_PRODUCTION=false` + server key sandbox).

## 9. Monitoring

| Apa | Cara |
|---|---|
| Uptime | UptimeRobot / BetterStack (gratis): monitor HTTP `https://mypresence.id/health?token=HEALTH_TOKEN`, interval 5 menit, alert ke email/WhatsApp/Telegram. Status 503 = database, cache, cron, worker, storage, atau disk bermasalah (detail di JSON). |
| Error aplikasi | Email otomatis ke `OPS_ALERT_EMAIL` (maks. 1× per 15 menit per jenis error). Log: `/var/www/mypresence/shared/storage/logs/laravel-YYYY-MM-DD.log` (14 hari). |
| Antrean | `sudo supervisorctl status`, log `shared/storage/logs/worker.log` |
| Backup | Per sekolah 01:30 (`shared/storage/app/private/backups`), database penuh 00:45 (`/var/www/mypresence/backups`). **Salin ke luar server**: isi `OFFSITE_CMD` (mis. rclone ke Cloudflare R2) atau unduh berkala. |

## 10. Perawatan rutin

| Kapan | Tugas |
|---|---|
| Mingguan | `apt update && apt upgrade` (lalu `systemctl reload php8.3-fpm`), cek `/health` |
| Bulanan | Uji pulihkan 1 backup: `php artisan school:restore {subdomain} {nama.zip}` di server staging |
| Tiap rilis | `make-release.sh` → upload → `deploy.sh`; rollback bila perlu |
| Sebelum tahun ajaran baru | Tingkatkan RAM/`pm.max_children` bila siswa > ±20.000 |

## Pemecahan masalah

| Gejala | Penyebab umum |
|---|---|
| Semua siswa ditolak "bukan WiFi sekolah" | IP publik WiFi sekolah berubah (daftarkan ulang), atau Cloudflare Proxied tanpa `TRUSTED_PROXIES` |
| Email tidak terkirim | `MAIL_*` salah / domain pengirim belum diverifikasi (SPF/DKIM) di penyedia SMTP |
| Impor Excel "menunggu" terus | Worker mati: `supervisorctl restart mypresence-worker:*` |
| `/health` scheduler gagal | Cron mati: `systemctl status cron`, cek `/etc/cron.d/mypresence` |
| Perubahan `.env` tidak berlaku | Konfigurasi di-cache: `php artisan optimize` |
| 502 Bad Gateway | `systemctl status php8.3-fpm`, log `/var/log/php8.3-fpm-mypresence.log` |
