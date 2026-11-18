# DNS wildcard & subdomain sekolah

Setiap sekolah diakses lewat `{subdomain}.mypresence.id`. Agar sekolah baru langsung aktif tanpa
menambah DNS satu per satu, cukup **satu record wildcard** `*` yang mengarah ke VPS.

## 1. Record DNS di produksi

Atur di panel registrar domain (Niagahoster/Domainesia/IDCloudHost/Rumahweb) atau Cloudflare:

| Tipe | Nama / Host | Nilai | TTL |
|---|---|---|---|
| A | `@` | IP publik VPS (IPv4) | 300 |
| A | `*` | IP publik VPS (IPv4) | 300 |
| AAAA | `@` | IPv6 VPS (bila ada) | 300 |
| AAAA | `*` | IPv6 VPS (bila ada) | 300 |

- `@` = `mypresence.id`, `*` = semua subdomain (`smkn1.mypresence.id`, `www.mypresence.id`, dst.).
- Record yang lebih spesifik selalu menang atas wildcard. Bila nanti ada `mail.mypresence.id`
  atau `status.mypresence.id` di server lain, cukup tambah record khusus itu. Nama-nama ini sudah
  dicadangkan di `config/tenancy.php`, jadi tidak bisa dipakai sekolah.
- **Cloudflare:** boleh mode "DNS only" (awan abu-abu) atau "Proxied" (awan oranye).
  Kalau Proxied, isi `TRUSTED_PROXIES` di `.env` dengan daftar IP Cloudflare
  (https://www.cloudflare.com/ips/). Kalau tidak, cek WiFi sekolah akan menolak semua siswa,
  karena semuanya terbaca ber-IP Cloudflare.

### Verifikasi

Setelah record disimpan (biasanya menyebar dalam beberapa menit, paling lama 24 jam), jalankan di VPS:

```bash
php artisan tenancy:dns-check --expect=<IP_VPS> --schools
```

Semua baris harus berstatus **OK**, termasuk baris "Subdomain acak (bukti wildcard)".

## 2. SSL wildcard (checklist item 10)

**Cara cepat (disarankan):** `DOMAIN=mypresence.id EMAIL=... CF_API_TOKEN=... bash deploy/scripts/ssl-wildcard.sh`
— memasang certbot, meminta sertifikat `mypresence.id` + `*.mypresence.id`, dan memasang reload Nginx otomatis saat perpanjangan.

Sertifikat biasa hanya berlaku untuk satu nama. Untuk `*.mypresence.id`, Let's Encrypt mewajibkan
verifikasi **DNS-01** (menambah record TXT). Cara termudah memakai plugin Cloudflare:

```bash
apt install certbot python3-certbot-dns-cloudflare
# /root/.secrets/cloudflare.ini berisi: dns_cloudflare_api_token = <token dengan izin Zone:DNS:Edit>
chmod 600 /root/.secrets/cloudflare.ini
certbot certonly --dns-cloudflare --dns-cloudflare-credentials /root/.secrets/cloudflare.ini \
  -d mypresence.id -d '*.mypresence.id'
```

Perpanjangan otomatis sudah dijadwalkan oleh certbot. Tanpa Cloudflare, gunakan
`certbot certonly --manual --preferred-challenges dns`, tetapi perpanjangannya manual tiap 90 hari,
jadi tidak disarankan.

## 3. Uji lokal

| Alat | Cara |
|---|---|
| **Laravel Herd** (Mac/Windows) | `herd link mypresence` → semua `*.mypresence.test` otomatis jalan. Buka `http://demo.mypresence.test` |
| **Valet** (Mac) | `valet link mypresence` → sama seperti Herd |
| **Laragon** (Windows) | Laragon hanya membuat `mypresence.test`. Tambah per sekolah di `C:\Windows\System32\drivers\etc\hosts`: `127.0.0.1 demo.mypresence.test`, lalu di Laragon *Menu → Apache/Nginx → sites-enabled*, tambahkan `*.mypresence.test` pada `ServerAlias`/`server_name` |
| **Tanpa alat** | Tambah `127.0.0.1 mypresence.test demo.mypresence.test` ke file hosts, lalu `php artisan serve --host=0.0.0.0 --port=8000` dan buka `http://demo.mypresence.test:8000` |

`.env` lokal: `APP_DOMAIN=mypresence.test`, `SESSION_DOMAIN=null`.
