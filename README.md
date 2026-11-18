# MyPresence — SaaS multi-tenant untuk sekolah

Produk Parama Tech. Satu aplikasi Laravel melayani banyak sekolah; tiap sekolah punya subdomain sendiri
(`smkn1.mypresence.id`), dan datanya terpisah lewat kolom `school_id`.

**Status: Tahap 1–7 selesai — siap go-live.** Backend multi-tenant, portal admin, panel Parama Tech, langganan & pembayaran,
PWA untuk admin/guru/siswa, dan paket deploy VPS. Panduan go-live: **[`deploy/DEPLOY.md`](deploy/DEPLOY.md)**.

## Keputusan arsitektur

| Hal | Keputusan |
|---|---|
| Isolasi data | Satu database, kolom `school_id` di setiap tabel sekolah |
| Pengenal sekolah | Subdomain → middleware `IdentifyTenant` |
| Implementasi tenant | Buatan sendiri: trait `BelongsToSchool` + global scope `SchoolScope` |
| Paket | Per jumlah siswa → `schools.student_quota` ditegakkan saat tambah siswa |
| Auth | Sesi Laravel (cookie per subdomain + CSRF). Satu pintu login admin / guru (NIK) / siswa (NIS) |
| Hosting | VPS: Nginx + PHP-FPM 8.3 + MySQL 8, cron `schedule:run` |

## Cara kerja isolasi tenant

```
Request smkn1.mypresence.id/api/students
  → ResetTenant        : konteks dikosongkan
  → IdentifyTenant     : subdomain "smkn1" → School #1 → TenantContext
                         (tidak ada → 404, ditangguhkan → 403, sesi sekolah lain → logout)
  → Auth / bindings    : Student::find($id) otomatis WHERE school_id = 1
  → Controller
```

- `app/Tenancy/SchoolScope.php` — semua query model sekolah difilter `school_id`. **Tanpa tenant aktif,
  data sekolah tidak terlihat sama sekali** (gagal tertutup), jadi lupa menyetel tenant tidak membocorkan data.
- `app/Tenancy/BelongsToSchool.php` — `school_id` diisi otomatis saat `create`; menulis untuk sekolah lain
  atau memindahkan baris ke sekolah lain melempar `TenantViolation`.
- `school_id` **tidak pernah** dibaca dari input klien.
- Subdomain cadangan (`www`, `admin`, `api`, `mail`, ...) tidak pernah menjadi sekolah; `www` dialihkan ke domain utama.
  Validasi nama subdomain untuk pendaftaran: `App\Rules\AvailableSubdomain`.
- **Tes penjaga** (`TenancyArchitectureTest`): gagal bila ada tabel ber-`school_id` yang modelnya lupa memakai
  `BelongsToSchool`, atau ada rute `/api` di luar subdomain sekolah / tanpa middleware `role`.
- Setelah DNS diarahkan ke VPS: `php artisan tenancy:dns-check --expect=<IP_VPS> --schools`.
- Panel super-admin yang perlu melihat lintas sekolah harus menulis `withoutGlobalScope(SchoolScope::class)` secara eksplisit.
- Job/command yang memproses banyak sekolah memakai `app(TenantContext::class)->run($school, fn () => ...)`.

## Presensi: QR statis + 3 pengaman

Scan QR siswa hanya diterima bila **keempat** syarat terpenuhi:

1. QR adalah QR kelas siswa itu sendiri (kode diverifikasi di server).
2. HP tersambung ke **WiFi sekolah** (IP publik terdaftar).
3. Dari **HP yang terdaftar** untuk akun siswa itu (1 akun = 1 HP, 1 HP = 1 akun siswa).
4. Saat **jam pelajaran kelasnya berlangsung** menurut Jadwal (istirahat dan jam kosong ditolak).

### QR statis + WiFi sekolah

- **QR statis per kelas**, dicetak dan ditempel di kelas. Isinya `MP-{id kelas}-{kode 8 karakter}`, dan kodenya
  bisa juga diketik manual. Kode hanya berubah kalau admin menekan **Perbarui QR**. Setelah itu QR lama langsung
  tidak berlaku dan harus dicetak ulang. Mode `dynamic` (QR di layar guru, berganti tiap 30 detik) tetap tersedia
  sebagai opsi per sekolah.
- **Scan hanya diterima dari WiFi sekolah.** Browser/PWA tidak bisa membaca nama WiFi (SSID), jadi server
  mencocokkan **IP publik** perangkat dengan daftar jaringan sekolah (`settings.attendance.networks`). HP yang
  memakai kuota atau WiFi rumah ditolak. Kalau belum ada jaringan terdaftar, scan ditolak (gagal tertutup).
- Cara admin mendaftarkan jaringan: buka MyPresence dari perangkat yang tersambung ke WiFi sekolah, lalu tekan
  **Daftarkan jaringan ini** (`POST /api/settings/attendance/networks/register-current`). IPv4 disimpan sebagai `/32`,
  IPv6 sebagai prefiks `/64`. Kalau ISP sekolah memberi blok IP, admin bisa mengisi rentang (mis. `103.10.20.0/24`,
  minimal `/16`).
- Batas presensi = akhir jam pelajaran ke-N (`attendance_cutoff_period`, bawaan 1). Scan setelah batas tetap
  diterima tetapi ditandai **terlambat**.
- `php artisan attendance:mark-absent` (terjadwal tiap 5 menit) mencatat **Alpa otomatis** memakai zona waktu
  masing-masing sekolah. Aman dijalankan berulang.
- Guru/admin hanya bisa mengoreksi ke Hadir, Sakit, atau Izin. Hanya admin yang bisa memperbarui QR dan mengatur jaringan.

### Wajib diperhatikan saat deploy

- **IP klien diambil dari `REMOTE_ADDR`.** Header `X-Forwarded-For` diabaikan kecuali datang dari proxy di
  `TRUSTED_PROXIES`. Kalau Nginx langsung menerima koneksi, biarkan kosong. Kalau memakai Cloudflare (proxy
  oranye), isi dengan daftar IP Cloudflare. Kalau tidak, semua siswa akan terbaca ber-IP Cloudflare dan ditolak.
  **Jangan isi `*`**, karena siswa bisa memalsukan IP lewat header.
- **IP dinamis** (umum di paket rumahan seperti IndiHome) bisa berubah setelah modem restart. Gejalanya semua siswa
  ditolak. Solusinya admin menekan **Daftarkan jaringan ini** lagi, atau minta IP statis ke ISP (disarankan).
### 1 akun = 1 HP

- Saat login pertama, server membuat token perangkat acak di cookie (httpOnly, terenkripsi, hanya untuk subdomain
  sekolah). Di database (`user_devices`) yang tersimpan hanya hash-nya.
- Batas bawaan: **siswa 1 HP, guru 2 perangkat, admin bebas** (bisa diubah di `settings/attendance` → `device_limits`).
- Akun siswa yang sudah terikat tidak bisa login dari HP lain. HP yang sudah terikat ke satu siswa tidak bisa dipakai
  login akun siswa lain, jadi "titip absen" lewat satu HP tertutup.
- Scan wajib dari HP terdaftar milik siswa itu. Setiap presensi mencatat `device_id` dan `ip` untuk audit.
- **Ganti HP / data browser terhapus / aplikasi diinstal ulang** → wali kelas (untuk siswa perwaliannya) atau admin
  menekan **Reset perangkat** (`DELETE /api/users/{id}/devices`). Sesi lama ikut diputus.

### Hanya saat jam pelajaran

- Jendela scan = jam pelajaran kelas itu hari ini menurut Jadwal, ditambah toleransi datang awal sebelum jam pertama
  (bawaan 30 menit, `early_minutes`). Istirahat dan jam kosong ditolak.
- Kelas yang jadwalnya belum diisi memakai seluruh Jam KBM sekolah. Kelas yang punya jadwal tapi tidak ada pelajaran
  hari itu ditolak.
- Bisa dimatikan admin (`restrict_to_lessons: false`) untuk kegiatan khusus.
- PWA bisa menampilkan jendela ini ke siswa dari `GET /api/attendance/mine` → `scan_windows`.

### Sisa risiko (jujur)

Dengan QR statis, tidak ada sistem yang 100% tanpa celah. Yang masih mungkin: siswa yang **sudah ada di sekolah,
memakai HP-nya sendiri, di jam pelajaran**, tetapi tidak masuk kelas (mis. di kantin dengan foto QR). Pengawas
terakhirnya tetap guru di kelas: rekap kelas menampilkan siapa yang scan dan jam berapa. Kalau ini perlu ditutup
juga, gunakan mode `dynamic` (QR berganti tiap 30 detik di layar guru) untuk kelas/jam tertentu.

## Menjalankan di komputer lokal (Laravel Herd / Valet)

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite          # atau ganti ke MySQL di .env
php artisan migrate --seed
herd link mypresence                    # lalu buka http://demo.mypresence.test
```

Herd otomatis melayani `*.mypresence.test`. Tanpa Herd, tambahkan `demo.mypresence.test` ke file hosts
lalu `php artisan serve` dan akses lewat port-nya.

Akun demo di `demo.mypresence.test` (semua wajib ganti password setelah masuk):

| Peran | Username | Password |
|---|---|---|
| Admin sekolah | `admin` | `admin123` |
| Guru | `3301010101` (NIK) | `guru123` |
| Siswa | `1001` (NIS) | `2009-05-12` (tanggal lahir) |

## Portal HTML & impor data (tahap 3)

Portal sementara (Blade, desain prototipe) di subdomain sekolah, sebelum PWA dibangun di tahap 7:
`/masuk` · `/dashboard` · `/guru` · `/siswa` · `/kelas` · `/impor`.

- **Impor Excel** (format sama dengan prototipe; template di menu Impor): Data Guru, Data Siswa, Jam KBM.
  Alurnya unggah → **pratinjau** (tidak mengubah data) → Simpan → diproses **job antrean**.
- Siswa: satu sheet per kelas, kelas baru dibuat otomatis. Mode *Tambah & perbarui* atau *Ganti data lama*
  (siswa yang tidak ada di file **dinonaktifkan**, bukan dihapus). Kuota paket dicek sebelum simpan.
- Password awal = tanggal lahir bila ada, selain itu acak. Daftar akun (CSV) bisa diunduh admin; disimpan
  terenkripsi dan **dihapus otomatis setelah 7 hari** bersama berkas Excel-nya (`imports/school_{id}/`).
- Job antrean membutuhkan worker: lokal `php artisan queue:work`, produksi lewat Supervisor (tahap 7).
  Untuk coba cepat tanpa worker: `QUEUE_CONNECTION=sync` di `.env`.

## Panel super-admin & onboarding (tahap 4)

**Domain pusat** (`mypresence.id`):
- `/` halaman depan + "Masuk ke sekolah saya" · `/daftar` pendaftaran sekolah mandiri (trial 30 hari, kuota 300 siswa,
  cek ketersediaan alamat langsung, jebakan bot, persetujuan UU PDP).
- `/panel` **panel super-admin** (akun `superadmin`, password dari `SUPERADMIN_PASSWORD` di `.env` saat seeding):
  daftar sekolah + pemakaian (siswa/kuota, guru, presensi 30 hari, ukuran data, HP terdaftar, login terakhir),
  buat sekolah, ubah paket/kuota/masa aktif, tangguhkan/aktifkan (data tidak dihapus), reset password admin
  (ditampilkan sekali), **masuk sebagai sekolah** dengan alasan wajib, dan log aktivitas platform.
  Opsional: `PANEL_ALLOWED_IPS` membatasi panel ke IP kantor.

**Masuk sebagai sekolah (impersonate):** panel membuat token sekali pakai berumur 2 menit → subdomain sekolah
menukarnya dengan sesi admin + banner "Mode bantuan Parama Tech". Mulai, masuk, dan selesai tercatat di log sekolah
dan **terlihat oleh admin sekolah** di menu *Log Akses*.

**Di subdomain sekolah:** verifikasi email admin (wajib sebelum mengelola data; tautan bertanda tangan 3 hari),
`/lupa-password` (per sekolah walau email sama dipakai di sekolah lain; token 60 menit, sekali pakai, tidak
membocorkan email terdaftar), password sementara guru/siswa oleh admin, checklist onboarding di dashboard,
dan **impor jadwal** (`HARI | JAM KE | KELAS | MATA PELAJARAN | GURU`, rentang "3-4", deteksi bentrok kelas/guru).

Email: atur `MAIL_*` di `.env` (SMTP/Mailgun/SES). Lokal: `MAIL_MAILER=log` (email tertulis di `storage/logs`).
SSL wildcard: `deploy/scripts/ssl-wildcard.sh`.

## Berkas, backup, privasi & keamanan (tahap 5)

- **Awalan tenant:** semua berkas di `school_{id}/…` (`App\Tenancy\TenantFiles` menolak `..`, jalur absolut, dan berkas
  sekolah lain), cache lewat `TenantCache` (`school_{id}:kunci`), dan sesi terikat ke satu sekolah (`tenant_id` di sesi).
- **Object storage:** `TENANT_FILES_DISK=s3` + `AWS_*`/`AWS_ENDPOINT` untuk penyedia S3-compatible (IDCloudHost, Wasabi,
  Cloudflare R2, MinIO). Berkas privat dibuka lewat URL sementara (presigned di S3, rute bertanda tangan di lokal).
  Foto profil (`POST /api/me/photo`) dan logo sekolah (menu *Profil Sekolah*) di-encode ulang ke WebP, EXIF/GPS dibuang.
- **Backup per sekolah:** `php artisan school:backup --school=smkn1` / `--all` (terjadwal 01.30), ZIP **AES-256**
  (`BACKUP_PASSWORD`, wajib di produksi), rotasi `BACKUP_KEEP`. Pulihkan: `php artisan school:restore smkn1 <berkas>`
  — hanya data & berkas sekolah itu yang diganti, dan backup pengaman dibuat otomatis lebih dulu. Panel: backup sekarang & unduh.
  Tetap jalankan juga backup database penuh harian (mysqldump) di VPS.
- **Ekspor & hapus:** menu *Data & Privasi* — ekspor seluruh data ke Excel (7 sheet, berkas 7 hari), permintaan hapus
  data; panel: **hapus permanen** (harus ditangguhkan dulu + ketik alamat sekolah) menghapus database, berkas, dan backup.
- **Dokumen hukum** `/syarat-layanan`, `/kebijakan-privasi`, `/perjanjian-pemrosesan-data` (berversi, `config/legal.php`).
  Persetujuan tercatat (nama, jabatan, waktu, IP); admin sekolah wajib menyetujui DPA sebelum mengelola data.
  **Masih DRAF** (`LEGAL_DRAFT=true`): tinjau dengan konsultan hukum, isi `LEGAL_*` dan daftar subprosesor.
- **Persetujuan orang tua/wali** (UU PDP Ps. 25; PP 33/2026 berlaku 16 Jan 2027): catat bukti per siswa/kelas.
- **2FA super-admin** (TOTP, QR + kode cadangan, kode tidak bisa dipakai ulang) dan **header keamanan** (CSP, HSTS,
  X-Frame-Options, Permissions-Policy, no-store untuk halaman berisi data pribadi).

## Langganan & pembayaran (tahap 6)

Paket **per jumlah siswa**: `siswa × harga/siswa/bulan × bulan`, diskon periode (6 bln 5%, 12 bln ≈ gratis 2 bulan), pajak opsional. Semua angka di `config/billing.php` / `.env` (`BILLING_*`) — **harga Rp3.000 masih contoh**.

| Alur | Rincian |
|---|---|
| Portal admin → **Langganan** | status (trial/aktif/tenggang/diblokir), pilih kuota & periode dengan pratinjau harga, buat tagihan, tambah kuota prorata, riwayat |
| Bayar online | `PAYMENT_GATEWAY=midtrans` (Snap) atau `xendit` (Invoice) → halaman bayar VA/QRIS/e-wallet → aktif otomatis |
| Transfer manual | rekening `BILLING_BANK_*`, admin unggah bukti (gambar di-encode ulang / PDF dicek), Parama Tech konfirmasi di **Panel → Tagihan** |
| Invoice & kwitansi | `/langganan/tagihan/{id}/cetak` — siap cetak/PDF untuk administrasi BOS |
| Panel → Tagihan | pendapatan bulan/tahun, tagihan belum dibayar, bukti transfer menunggu, tandai lunas, batalkan, tagihan khusus, log webhook |

**Webhook** (domain pusat, tanpa CSRF):
- Midtrans → Settings › Payment › Notification URL: `https://mypresence.id/webhooks/midtrans`
- Xendit → Callbacks › Invoices paid: `https://mypresence.id/webhooks/xendit` (+ isi `XENDIT_CALLBACK_TOKEN`)

Keamanan webhook: tanda tangan SHA-512 Midtrans / token `X-CALLBACK-TOKEN` Xendit diverifikasi dengan `hash_equals`, status **dicek ulang ke API gateway**, nominal harus sama persis dengan tagihan, setiap notifikasi dicatat di `payment_events` (kunci unik → idempoten; notifikasi ganda tidak memperpanjang dua kali).

**Siklus harian** `billing:run` (07:05 WIB):
1. Pengingat H-14/7/3/1 sebelum trial/langganan berakhir (sekali per ambang).
2. Invoice perpanjangan otomatis H-14 (periode & kuota terakhir, minimal siswa aktif).
3. Lewat tanggal berakhir → email masa tenggang (`BILLING_GRACE_DAYS`, bawaan 7 hari).
4. Lewat masa tenggang → **blokir otomatis** (`suspension_type=billing`): guru/siswa melihat halaman "diblokir", API → HTTP 402; admin tetap bisa login dan membayar. Lunas → aktif kembali seketika. Penangguhan **manual** dari panel tidak terbuka oleh pembayaran.

Perpanjangan ditumpuk dari akhir masa aktif yang berjalan (tidak ada hari hilang); masa aktif berakhir 23:59 waktu sekolah.

```bash
php artisan billing:run                 # semua sekolah
php artisan billing:run --school=smkn1  # satu sekolah
```

## PWA admin, guru & siswa (tahap 7)

Satu aplikasi di `https://{sekolah}.mypresence.id/app`; menu menyesuaikan peran setelah login. Tanpa build step
(ES modules di `public/pwa/`), jadi rilis cukup upload file.

| Peran | Halaman |
|---|---|
| Siswa | Dashboard, **Scan QR kelas lewat kamera** (jsQR), rekap absensi, jadwal pelajaran, jurnal kelas, Tugas-Ku (centang selesai), agenda & informasi, profil (foto, ganti password) |
| Guru | Dashboard jadwal hari ini, QR kelas + rekap & koreksi H/S/I, jurnal (jam & presensi terisi otomatis) + tugas, jadwal mengajar, agenda, wali kelas (jabatan pengurus, reset HP siswa) |
| Admin | Semua di atas + data guru/siswa/kelas, agenda sekolah & briefing, informasi, pengaturan presensi (mode QR, batas jam, WiFi sekolah), **cetak QR semua kelas**; impor Excel, langganan, privasi tetap di portal admin |

- **Instal ke layar utama**: `manifest.webmanifest` per sekolah (nama sekolah, ikon, shortcut Absensi/Jadwal) + petunjuk instal Android/iPhone.
- **Offline** (`public/sw.js`): shell & aset di-cache; jadwal, agenda, tugas, jurnal, rekap tampil dari data terakhir dengan bilah "Mode offline".
  Scan presensi dan semua penyimpanan **selalu wajib online** (tidak pernah di-cache/antre).
- Guru & siswa yang login lewat `/masuk` otomatis diarahkan ke `/app`; admin tetap ke portal.
- Saat sekolah diblokir karena tagihan, shell tetap terbuka tetapi data API → 402 dan aplikasi menampilkan halaman "layanan diblokir".

## Menu lengkap sesuai prototipe (absensi guru, CBT, leger, pembiasaan, library, kehidupan kelas)

- **Absensi guru dua langkah**: ① scan *QR Kehadiran Guru* (`MPG-{sekolah}-{kode}`, dicetak dari menu QR, bisa diganti) dari WiFi
  sekolah + HP terdaftar; ② scan QR kelas per blok jadwal (dibuka 15 menit sebelum mulai, terlambat bila lewat 10 menit).
  Admin memantau rekap per guru dan per jadwal, dan bisa mencatat manual.
- **CBT**: bank soal diimpor dari Excel (baris `Q`/`A`, jawaban benar ditandai `1`), pratinjau soal.
- **Leger Rapor**: admin menetapkan master (bawaan 11 mapel atau unggah .xlsx), semester, lalu mendistribusikan ke
  wali kelas. Wali kelas mengisi DATA · CAPAIAN MATERI · nilai (rata-rata, jumlah, ranking otomatis) · RAPORT, lalu
  mencetak rapor (`/rapor/{kelas}`).
- **Pembiasaan** (CREO & Literasi): siswa unggah bukti (foto → WebP, atau PDF); guru/admin melihat rekap.
- **MP-Library**: guru/admin menambah bacaan (tautan/PDF) per kelas; siswa mencari, membuka, menyimpan favorit.
- **Kehidupan kelas**: Struktur Kelas, Jadwal Piket (acak / impor / ekspor Excel oleh wali kelas), Jurnal Kelas yang diisi
  pengurus kelas, dan Tugas-Ku (folder per mapel + unggah berkas tugas).
- Migrasi baru `2026_11_04_000008`: di cPanel jalankan lagi `platform:setup` (atau `php artisan migrate --force`).

## Operasional & go-live (tahap 7)

| Berkas | Fungsi |
|---|---|
| `deploy/DEPLOY.md` | **Runbook go-live langkah demi langkah** (domain → VPS → SSL → .env → rilis → super-admin → uji → monitoring) |
| `deploy/scripts/provision.sh` | Siapkan Ubuntu 24.04: Nginx, PHP 8.3-FPM, MySQL 8, Redis, Supervisor, cron, UFW, fail2ban, swap |
| `deploy/scripts/ssl-wildcard.sh` | Sertifikat `*.mypresence.id` (Let's Encrypt DNS-01 Cloudflare) + aktifkan situs |
| `deploy/scripts/deploy.sh` | Rilis atomik tanpa downtime (`releases/` + symlink `current`), migrate, cache, restart worker, cek `/up`, `--rollback` |
| `deploy/scripts/make-release.sh` | Bangun zip rilis bersih (tanpa vendor/.env/data lokal) |
| `deploy/cpanel/` + `make-cpanel-package.sh` | **Shared hosting cPanel** (tanpa root/terminal): zip berisi vendor, `public_html/index.php` khusus, `.env` berkunci acak, panduan `PANDUAN-CPANEL.md`; instalasi lewat Cron (`platform:setup`) |
| `deploy/scripts/backup-db.sh` | mysqldump harian terenkripsi AES-256, simpan 14 hari, opsi salin ke luar server |
| `deploy/env.production.example` | Template `.env` produksi (Redis untuk sesi/cache/antrean) |
| `deploy/supervisor`, `deploy/cron`, `deploy/php` | Worker antrean ×2, scheduler per menit, pool PHP-FPM + OPcache |

Monitoring: `/up` (hidup, tanpa token) dan `/health?token=HEALTH_TOKEN` (database, cache, cron, worker, storage, disk → 200/503)
untuk UptimeRobot/BetterStack; error produksi dikirim ke `OPS_ALERT_EMAIL` (maks. 1×/15 menit per jenis).
Super-admin produksi: `php artisan platform:superadmin {username} {email}` (jangan `db:seed` di produksi).

Diuji: 160 tes di SQLite **dan** MySQL 8.0 (server Dolt), uji browser end-to-end PWA (scan QR dari kamera, offline,
guru, admin), dan uji `deploy.sh` (rilis, rilis kedua, rollback) sebagai user non-root.

## Tes

```bash
php artisan test        # atau vendor/bin/phpunit
```

160 tes, termasuk `Pwa/PwaTest`, `Ops/OpsTest`, `Billing/BillingTest` (harga, checkout Midtrans/Xendit, webhook palsu/ganda/nominal salah, blokir & aktif kembali), `DataProtectionTest`, `TwoFactorTest`, `PanelTest`, `OnboardingTest`, `ScheduleImportTest`, `ImportTest` (impor Excel nyata, kuota, mode ganti, isolasi impor), `TenancyArchitectureTest` (penjaga aturan tenant), `SubdomainTest`, `DeviceBindingTest` (1 akun = 1 HP), `LessonWindowTest` (jam pelajaran), `SchoolNetworkTest` (WiFi sekolah, pemalsuan IP, IPv6), dan `tests/Feature/Tenancy/TenantIsolationTest.php`: sekolah A tidak bisa membaca, mengubah,
menghapus, login, atau scan QR milik sekolah B.

## API sekolah (prefix `/api`, di subdomain sekolah)

| Metode | Path | Akses |
|---|---|---|
| GET | `school` | publik (identitas sekolah untuk halaman login) |
| POST | `auth/login` · `auth/logout` | publik / login (maks. 5 percobaan per menit) |
| GET / PUT | `me` · `me/password` | login |
| POST | `attendance/scan` | siswa |
| GET | `attendance/mine` | siswa |
| GET | `classrooms` · `classrooms/{id}/qr` · `classrooms/{id}/attendance` | guru, admin |
| POST | `classrooms/{id}/qr/rotate` | admin (QR statis harus dicetak ulang) |
| GET / PUT | `settings/attendance` | admin (mode QR, batas jam ke-N, jaringan WiFi, batas perangkat, aturan jam pelajaran) |
| GET / DELETE | `users/{id}/devices` | admin; guru untuk siswa perwaliannya (lihat / reset perangkat) |
| POST | `settings/attendance/networks/register-current` | admin (daftarkan IP WiFi saat ini) |
| PUT | `students/{id}/attendance` | guru, admin (H/S/I) |
| CRUD | `classrooms` · `teachers` · `students` | admin |

## Struktur penting

```
app/Tenancy/                 TenantContext, SchoolScope, BelongsToSchool, TenantViolation
app/Http/Middleware/         IdentifyTenant, ResetTenant, RequireRole
app/Services/                ClassQrCode, AttendanceService, SchoolNetwork, DeviceBinding
app/Console/Commands/        MarkAbsentStudents (attendance:mark-absent)
deploy/                      skrip VPS, Nginx, Supervisor, cron, .env produksi, DEPLOY.md, DNS.md
public/pwa/ , public/sw.js   PWA (app.js inti, pages.js admin/guru, student.js siswa) + service worker
app/Services/Billing/        harga, invoice, gateway Midtrans/Xendit, siklus langganan
app/Services/Ops/            HealthCheck, ErrorAlert
routes/web.php               domain pusat (landing, super-admin, pendaftaran — tahap 4)
routes/tenant.php            semua rute sekolah
database/seeders/data/       data demo dari prototipe (42 kelas, 54 guru, 885 jadwal)
```

## Sebelum diumumkan ke sekolah

- Beli domain & VPS, lalu ikuti `deploy/DEPLOY.md`.
- Tentukan harga final (`BILLING_PRICE_PER_STUDENT`), isi rekening & data penerbit invoice, aktifkan Midtrans/Xendit.
- Tinjauan konsultan hukum atas Syarat Layanan, Kebijakan Privasi, dan DPA → `LEGAL_DRAFT=false`.
- Uji coba lapangan 1 sekolah pilot (WiFi sekolah, HP siswa Android & iPhone) sebelum membuka pendaftaran umum.
