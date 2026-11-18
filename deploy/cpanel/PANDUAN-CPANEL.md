# Pasang MyPresence di Shared Hosting (cPanel)

Untuk tahap awal / pilot. Semua lewat cPanel dan Cloudflare — **tanpa terminal**.
Ganti `USER` dengan username cPanel Bro (terlihat di cPanel → kolom kanan "Current User" / "Home Directory").

```
/home/USER/
├── mypresence/        ← aplikasi (kode, .env, data). TIDAK bisa diakses dari internet.
└── public_html/       ← halaman web (index.php, css, PWA). Dilayani untuk mypresence.id dan *.mypresence.id
```

---

## Langkah 1 — Versi PHP

cPanel → **Select PHP Version** (atau **MultiPHP Manager**) → pilih **PHP 8.3** (minimal 8.2) untuk `mypresence.id`.

Di tab **Extensions**, pastikan tercentang:
`pdo_mysql` · `mysqlnd` · `mbstring` · `intl` · `zip` · `gd` · `fileinfo` · `curl` · `openssl` · `xml` · `dom` · `tokenizer` · `ctype`

Di tab **Options** (atau **MultiPHP INI Editor**): `memory_limit` **256M**, `upload_max_filesize` **16M**, `post_max_size` **20M**, `max_execution_time` **120**.

## Langkah 2 — Database

cPanel → **MySQL Database Wizard**:
1. Nama database: `mypresence` → jadi `USER_mypresence`
2. User: `mypresence` → jadi `USER_mypresence`, password kuat (catat)
3. Privileges: **ALL PRIVILEGES** → Next

## Langkah 3 — Email pengirim

cPanel → **Email Accounts** → Create → `noreply@mypresence.id`, password kuat (catat).
Dipakai untuk verifikasi email admin sekolah, lupa password, dan tagihan.

## Langkah 4 — Unggah aplikasi

1. cPanel → **File Manager** → buka folder **home** (`/home/USER`, satu tingkat di atas `public_html`).
2. Klik **Settings** (kanan atas) → centang **Show Hidden Files (dotfiles)** → Save.
3. **Upload** `mypresence-cpanel.zip` ke `/home/USER` → klik kanan → **Extract**.
   Hasilnya folder `mypresence-cpanel/` berisi `mypresence/`, `public_html/`, dan panduan ini.
4. **Move** folder `mypresence-cpanel/mypresence` ke `/home/USER/` → jadi `/home/USER/mypresence`.
5. Buka `public_html` yang lama. Bila ada isinya (mis. `index.html` bawaan hosting), pindahkan ke folder cadangan.
6. Buka `mypresence-cpanel/public_html`, **Select All** (termasuk `.htaccess`) → **Move** ke `/home/USER/public_html`.
7. Hapus folder `mypresence-cpanel` dan file zip-nya.

## Langkah 5 — Isi `.env`

File Manager → `/home/USER/mypresence/.env` → klik kanan **Edit**. Ubah baris di bawah tanda `# ↓ UBAH`:

| Baris | Isi |
|---|---|
| `DB_DATABASE` / `DB_USERNAME` | `USER_mypresence` (dari langkah 2) |
| `DB_PASSWORD` | password database |
| `MAIL_PASSWORD` | password `noreply@mypresence.id` |
| `OPS_ALERT_EMAIL` | email Bro untuk notifikasi error |
| `BILLING_PRICE_PER_STUDENT` | harga per siswa per bulan |

`APP_KEY`, `HEALTH_TOKEN`, dan `BACKUP_PASSWORD` **sudah dibuatkan acak** — jangan diubah, jangan dibagikan.
**Salin `BACKUP_PASSWORD` ke catatan aman** (tanpa itu backup sekolah tidak bisa dibuka).

## Langkah 6 — Subdomain wildcard (`*.mypresence.id`)

Agar setiap sekolah otomatis punya alamat sendiri (`smkn1.mypresence.id`, `smpn2.mypresence.id`, ...):

cPanel → **Domains** → **Create A New Domain** (atau **Subdomains** di cPanel lama):
- Domain: `*.mypresence.id`
- **Centang "Share document root"** / isi Document Root: `public_html` (sama dengan domain utama)
- Submit

> Bila muncul pesan wildcard tidak diizinkan, kirim tiket ke support hosting:
> *"Mohon aktifkan wildcard subdomain *.mypresence.id dengan document root public_html."*

## Langkah 7 — Cloudflare (HTTPS untuk semua subdomain, gratis)

HTTPS wajib: tanpa itu kamera HP tidak boleh dipakai untuk scan QR.

1. Daftar di **cloudflare.com** → **Add a site** → `mypresence.id` → paket **Free**.
2. Cloudflare menyalin record DNS lama. Periksa di **DNS → Records**:

   | Tipe | Nama | Isi | Proxy |
   |---|---|---|---|
   | A | `mypresence.id` (`@`) | IP hosting* | 🟠 Proxied |
   | A | `*` | IP hosting* | 🟠 Proxied |
   | A | `mail` | IP hosting* | ⚪ DNS only |
   | MX | `mypresence.id` | `mail.mypresence.id` | — |

   *IP hosting: cPanel → kolom kanan **Shared IP Address** (General Information). Tambahkan record `*` bila belum ada.
3. Cloudflare memberi **2 nameserver** (mis. `xxx.ns.cloudflare.com`). Buka **client area penyedia hosting** →
   Domain `mypresence.id` → **Nameservers / Ubah DNS** → ganti dengan 2 nameserver Cloudflare. Simpan.
   Tunggu email "mypresence.id is now active" dari Cloudflare (biasanya 15 menit–beberapa jam).
4. Cloudflare → **SSL/TLS → Overview**: pilih **Full**.
5. **SSL/TLS → Edge Certificates**: aktifkan **Always Use HTTPS**.
6. **Speed / Optimization**: pastikan **Rocket Loader = Off** (bisa mengganggu aplikasi).

Lebih aman (disarankan setelah semua jalan): **SSL/TLS → Origin Server → Create Certificate**
(hostname `mypresence.id` dan `*.mypresence.id`, 15 tahun) → salin Certificate & Private Key →
cPanel → **SSL/TLS → Manage SSL sites** → pasang untuk `mypresence.id` dan `*.mypresence.id` → lalu ubah mode Cloudflare ke **Full (strict)**.

## Langkah 8 — Instalasi awal (lewat Cron, sekali saja)

1. Edit `.env`, isi sementara: `SUPERADMIN_PASSWORD=PasswordKuat2026` (min. 12 karakter, huruf besar & kecil, angka).
2. cPanel → **Cron Jobs** → Common Settings: **Once Per Minute** → Command:

   ```
   /usr/local/bin/php /home/USER/mypresence/artisan platform:setup superadmin emailbro@gmail.com
   ```
3. Tunggu 1–2 menit, buka File Manager → `/home/USER/mypresence/storage/logs/setup.txt`.
   - Semua `→ OK` dan "Super-admin dibuat" → **hapus cron ini**, lalu **kosongkan** `SUPERADMIN_PASSWORD` di `.env`.
   - Berkas tidak muncul → path PHP berbeda. Ganti `/usr/local/bin/php` dengan salah satu:
     `/usr/local/bin/ea-php83` · `/opt/cpanel/ea-php83/root/usr/bin/php` · `/opt/alt/php83/usr/bin/php`
   - Ada `GAGAL` → kirim isi `setup.txt` ke Parama Tech / Claude.

## Langkah 9 — Cron permanen (2 baris)

cPanel → **Cron Jobs** → tambahkan, keduanya **Once Per Minute** (pakai path PHP yang berhasil di langkah 8):

```
/usr/local/bin/php /home/USER/mypresence/artisan schedule:run >> /dev/null 2>&1
/usr/local/bin/php /home/USER/mypresence/artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
```

Baris 1: alpa otomatis, backup harian, tagihan, pengingat. Baris 2: impor Excel, ekspor data, email.

> Bila hosting membatasi cron minimal 5 menit, pakai `*/5 * * * *` — semua tetap jalan, hanya lebih lambat.

## Langkah 10 — Uji

1. `https://mypresence.id` → halaman depan MyPresence (gembok 🔒 di browser).
2. `https://mypresence.id/panel/masuk` → login super-admin → **aktifkan 2FA** (Google Authenticator) → simpan kode cadangan.
3. `https://mypresence.id/daftar` → daftarkan sekolah percobaan → cek email verifikasi masuk.
4. Buka `https://{alamat-sekolah}.mypresence.id` → login admin → **Impor Data** guru & siswa → **Cetak QR** kelas.
5. Dari **WiFi sekolah**: aplikasi → Pengaturan Presensi → **Daftarkan jaringan ini**.
6. HP siswa → `https://{alamat-sekolah}.mypresence.id/app` → login → **Pasang aplikasi** → scan QR kelas → tercatat Hadir.
7. Monitor: buka `https://mypresence.id/health?token=HEALTH_TOKEN` (token dari `.env`) → `"status":"ok"`.
   Daftarkan URL itu di **UptimeRobot** (gratis, cek tiap 5 menit) agar Bro dapat notifikasi bila server bermasalah.

## Perawatan

| Kapan | Apa |
|---|---|
| Mingguan | cPanel → **Backup** → unduh Full Backup / database. Backup per sekolah ada di `mypresence/storage/app/private/backups`. |
| Rutin | cPanel → **Resource Usage**: bila sering kena batas CPU/RAM/Entry Processes, saatnya pindah VPS (`deploy/DEPLOY.md`). |
| Update aplikasi | Parama Tech mengirim zip update → ganti folder kode di `mypresence/` (**jangan** timpa `.env` dan `storage/`) dan isi `public_html/`, lalu jalankan sekali `artisan platform:setup` lewat cron. |

## Pemecahan masalah

| Gejala | Solusi |
|---|---|
| `500 Server Error` | Lihat `mypresence/storage/logs/laravel-YYYY-MM-DD.log`. Paling sering: DB salah di `.env`, atau PHP < 8.2. |
| Halaman kosong / 403 | Pastikan `.htaccess` ikut terpindah ke `public_html` (aktifkan Show Hidden Files). |
| `smkn1.mypresence.id` tidak ditemukan | Record `*` belum ada di Cloudflare, atau wildcard subdomain belum dibuat di cPanel (langkah 6). |
| Semua siswa ditolak "bukan WiFi sekolah" | IP publik WiFi sekolah berubah → daftarkan ulang. Bila tetap, laporkan ke Parama Tech (cek `TRUSTED_PROXIES`). |
| Email tidak terkirim | Cek `MAIL_PASSWORD`; coba `MAIL_PORT=587` + `MAIL_SCHEME=smtp`. |
| Impor Excel "menunggu" terus | Cron baris 2 belum ada / path PHP salah. |
| Ubah `.env` tidak berpengaruh | Jalankan sekali lewat cron: `php /home/USER/mypresence/artisan optimize` |
