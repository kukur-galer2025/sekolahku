<?php

return [
    // Domain pusat. Sekolah diakses lewat subdomain: {subdomain}.{central_domain}
    // Produksi: mypresence.id · Lokal (Herd/Valet): mypresence.test
    'central_domain' => env('APP_DOMAIN', 'mypresence.test'),

    // Subdomain yang tidak boleh dipakai sekolah.
    // Termasuk subdomain bawaan cPanel (webmail, cpanel, autodiscover, ...) agar tidak bisa diklaim sekolah.
    'reserved_subdomains' => [
        'www', 'app', 'api', 'admin', 'panel', 'mail', 'static', 'cdn', 'status', 'docs', 'help', 'billing',
        'console', 'superadmin', 'root', 'parama', 'paramatech', 'mypresence', 'support', 'bantuan', 'login', 'masuk', 'daftar',
        'cpanel', 'webmail', 'whm', 'webdisk', 'autodiscover', 'autoconfig', 'cpcalendars', 'cpcontacts',
        'ftp', 'smtp', 'pop', 'pop3', 'imap', 'ns1', 'ns2', 'ns3', 'ns4', 'mx',
    ],

    // Zona waktu bawaan sekolah baru (WIB). WITA: Asia/Makassar · WIT: Asia/Jayapura
    'default_timezone' => 'Asia/Jakarta',

    // Hari sekolah (ISO: 1 = Senin ... 7 = Minggu). Bisa diubah per sekolah lewat settings.school_days
    'default_school_days' => [1, 2, 3, 4, 5],

    // Disk berkas sekolah: 'local' (pengembangan) atau 's3' (produksi, S3-compatible; isi AWS_* di .env)
    'files_disk' => env('TENANT_FILES_DISK', 'local'),

    // Backup per sekolah: disk tujuan, sandi ZIP (AES-256), dan jumlah backup yang disimpan per sekolah
    'backup_disk' => env('BACKUP_DISK', 'local'),
    'backup_password' => env('BACKUP_PASSWORD'),
    'backup_keep' => (int) env('BACKUP_KEEP', 14),

    // 2FA wajib untuk super-admin
    'superadmin_2fa' => (bool) env('SUPERADMIN_2FA', true),

    // Masa trial sekolah baru (hari)
    'trial_days' => 30,

    // Kuota siswa bawaan untuk sekolah trial (paket per jumlah siswa)
    'trial_student_quota' => 300,

    // Sebelum diverifikasi Parama Tech: kuota dibatasi dan guru/siswa belum bisa login.
    'unverified_student_quota' => (int) env('UNVERIFIED_STUDENT_QUOTA', 30),

    // Data pribadi siswa lulus/mutasi/keluar dianonimkan setelah sekian hari (kehadiran tetap untuk statistik).
    // Bisa diubah per sekolah lewat settings.lifecycle.retention_days (minimal 180).
    'alumni_retention_days' => (int) env('ALUMNI_RETENTION_DAYS', 365),

    // Log aktivitas disimpan sekian hari (minimal 365) lalu dihapus ops:prune.
    'audit_keep_days' => (int) env('AUDIT_KEEP_DAYS', 730),

    // 2FA wajib untuk admin sekolah (aplikasi authenticator).
    'school_admin_2fa' => (bool) env('SCHOOL_ADMIN_2FA', true),

    // Proxy/CDN tepercaya (mis. daftar IP Cloudflare), pisahkan koma. Kosong = tidak ada proxy.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    // Opsional: batasi panel super-admin ke IP kantor (pisahkan koma). Kosong = semua IP.
    'panel_allowed_ips' => array_filter(array_map('trim', explode(',', (string) env('PANEL_ALLOWED_IPS', '')))),

    // QR kelas berganti tiap N detik; scan diterima untuk langkah sekarang dan satu langkah sebelumnya
    'qr_step_seconds' => 30,
];
