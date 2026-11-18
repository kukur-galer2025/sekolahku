<?php

/*
| Langganan MyPresence — paket per jumlah siswa.
| Semua nominal dalam rupiah. Ubah lewat .env tanpa mengubah kode.
*/

return [
    // Harga per siswa per bulan (rupiah). CONTOH — tentukan harga final sebelum go-live.
    'price_per_student_month' => (int) env('BILLING_PRICE_PER_STUDENT', 3000),

    // Paket langganan. 'price' = harga per siswa per bulan (rupiah) — KOSONG sampai harga final ditetapkan.
    // Isi lewat .env, mis. PLAN_PRO_PRICE=4500. Selama kosong, tagihan memakai price_per_student_month di atas.
    // Pembagian fitur per paket: lihat 'features' di bawah (disetujui 8 Nov 2026).
    'plans' => [
        'starter' => [
            'label' => 'Starter',
            'price' => is_numeric(env('PLAN_STARTER_PRICE')) ? (int) env('PLAN_STARTER_PRICE') : null,
            'summary' => 'Presensi QR guru & siswa, data, jadwal, rekap, impor Excel.',
        ],
        'pro' => [
            'label' => 'Pro',
            'price' => is_numeric(env('PLAN_PRO_PRICE')) ? (int) env('PLAN_PRO_PRICE') : null,
            'summary' => 'Starter + jurnal, penugasan, agenda, wali kelas, piket, impor PDF aSc.',
        ],
        'all_service' => [
            'label' => 'All Service',
            'price' => is_numeric(env('PLAN_ALL_SERVICE_PRICE')) ? (int) env('PLAN_ALL_SERVICE_PRICE') : null,
            'summary' => 'Semua fitur: CBT, leger & rapor, pembiasaan, MP-Library + pendampingan.',
        ],
    ],
    'default_plan' => 'starter',

    // Fitur yang TIDAK tersedia di semua paket: kunci fitur → paket yang boleh memakai.
    // Fitur yang tidak tercantum di sini terbuka untuk semua paket. Kunci = kunci menu PWA (public/pwa/app.js).
    // Sumber: spreadsheet "Batasan Paket MyPresence" (disetujui 8 Nov 2026).
    'features' => [
        'pdf_import' => ['pro', 'all_service'],   // impor jadwal dari PDF aSc Timetables
        'jurnal' => ['pro', 'all_service'],
        'jkelas' => ['pro', 'all_service'],
        'tugas' => ['pro', 'all_service'],
        'agenda' => ['pro', 'all_service'],
        'wali' => ['pro', 'all_service'],
        'struktur' => ['pro', 'all_service'],
        'piket' => ['pro', 'all_service'],
        'cbt' => ['all_service'],
        'leger' => ['all_service'],
        'pembiasaan' => ['all_service'],
        'library' => ['all_service'],
        'denah_editor' => ['all_service'],       // mengubah denah interaktif; melihat denah terbuka untuk semua paket
        'nilai' => ['starter', 'pro', 'all_service'], // Daftar Nilai Sumatif (baru, terbuka sampai diatur di panel)
        'kas' => ['pro', 'all_service'],          // Kas Kelas (bendahara kelas)
    ],
    // Pembagian di atas adalah BAWAAN. Super-admin dapat mengubahnya di Panel → Paket & harga → "Menu & fitur per paket"
    // (tersimpan di tabel plan_features dan berlaku langsung tanpa deploy).

    /*
    | Katalog menu MyPresence (untuk daftar pilihan paket di panel & halaman Langganan sekolah).
    | 'lock' = kunci fitur yang bisa dibatasi per paket (harus ada di 'features' + 'feature_paths').
    | Tanpa 'lock' = menu inti, selalu tersedia di semua paket.
    | 'roles' = portal yang memakai menu ini (admin, guru, siswa).
    */
    'catalog' => [
        'Presensi & data inti' => [
            ['label' => 'Dashboard', 'desc' => 'Ringkasan harian tiap portal', 'roles' => 'admin, guru, siswa'],
            ['label' => 'Absensi QR siswa & guru', 'desc' => 'Kehadiran sekolah, masuk kelas per jadwal, rekap per guru', 'roles' => 'admin, guru, siswa'],
            ['label' => 'Rekap absensi', 'desc' => 'Rekap kehadiran harian/bulanan, ekspor Excel', 'roles' => 'admin, siswa'],
            ['label' => 'Data guru, siswa, kelas & rombel', 'desc' => 'Kelola data, kartu login, biodata siswa & cover rapor', 'roles' => 'admin'],
            ['label' => 'Jadwal KBM & jam pelajaran', 'desc' => 'Jadwal mengajar & pelajaran, bel sekolah', 'roles' => 'admin, guru, siswa'],
            ['label' => 'Impor Excel', 'desc' => 'Impor guru, siswa, kelas, jadwal dari Excel', 'roles' => 'admin'],
            ['label' => 'Impor jadwal PDF aSc Timetables', 'desc' => 'Baca jadwal langsung dari PDF aSc', 'roles' => 'admin', 'lock' => 'pdf_import'],
            ['label' => 'Informasi per bidang', 'desc' => 'Kepegawaian, kesiswaan, kurikulum, humas, sarpras oleh guru koordinator', 'roles' => 'admin, guru, siswa'],
            ['label' => 'Denah sekolah (lihat)', 'desc' => 'Denah JPG untuk semua warga sekolah', 'roles' => 'admin, guru, siswa'],
            ['label' => 'Pengaturan, profil & notifikasi', 'desc' => 'Pengaturan presensi & sekolah, profil, tema, notifikasi', 'roles' => 'admin, guru, siswa'],
        ],
        'Pembelajaran' => [
            ['label' => 'Jurnal mengajar', 'desc' => 'Jurnal guru per pertemuan', 'roles' => 'admin, guru', 'lock' => 'jurnal'],
            ['label' => 'Penugasan & pengumpulan tugas', 'desc' => 'Tenggat hari-tanggal-jam, unggah Word/PDF/JPG, nilai & catatan', 'roles' => 'guru, siswa', 'lock' => 'tugas'],
            ['label' => 'Agenda sekolah & briefing', 'desc' => 'Agenda yang ditulis admin, tampil di guru & siswa', 'roles' => 'admin, guru, siswa', 'lock' => 'agenda'],
            ['label' => 'CBT (ujian berbasis komputer)', 'desc' => 'Bank soal, impor Excel, token per kelas, kartu token, penilaian essay', 'roles' => 'admin, guru, siswa', 'lock' => 'cbt'],
            ['label' => 'MP-Library', 'desc' => 'Perpustakaan digital terlindung: watermark, log audit, batas unduh', 'roles' => 'admin, guru, siswa', 'lock' => 'library'],
        ],
        'Penilaian & rapor' => [
            ['label' => 'Daftar Nilai Sumatif', 'desc' => 'Nilai LM, STS, SAS & nilai rapor per kelas yang diampu, ekspor Excel', 'roles' => 'guru', 'lock' => 'nilai'],
            ['label' => 'Leger rapor', 'desc' => 'Leger nilai per kelas & distribusi rapor', 'roles' => 'admin, guru', 'lock' => 'leger'],
        ],
        'Kesiswaan & kelas' => [
            ['label' => 'Wali kelas', 'desc' => 'Presensi kelas, perangkat siswa, kartu login, struktur kelas', 'roles' => 'admin, guru', 'lock' => 'wali'],
            ['label' => 'Struktur kelas', 'desc' => 'Pengurus inti & seksi-seksi kelas', 'roles' => 'siswa', 'lock' => 'struktur'],
            ['label' => 'Jurnal kelas', 'desc' => 'Catatan harian oleh pengurus kelas', 'roles' => 'siswa', 'lock' => 'jkelas'],
            ['label' => 'Jadwal piket', 'desc' => 'Jadwal piket kelas', 'roles' => 'siswa', 'lock' => 'piket'],
            ['label' => 'Kas kelas', 'desc' => 'Bendahara mencatat iuran & pengeluaran; siswa melihat; wali kelas memantau', 'roles' => 'guru, siswa', 'lock' => 'kas'],
        ],
        'Program sekolah' => [
            ['label' => 'Pembiasaan CREO & Literasi', 'desc' => 'Informasi & dokumentasi oleh guru koordinator', 'roles' => 'admin, guru, siswa', 'lock' => 'pembiasaan'],
            ['label' => 'Penyusun denah (editor)', 'desc' => 'Koordinator sarpras menyusun & mengubah denah', 'roles' => 'admin, guru', 'lock' => 'denah_editor'],
        ],
    ],

    // Alamat (relatif ke subdomain sekolah) yang ditolak bila paket tidak memuat fiturnya.
    'feature_paths' => [
        'api/journals' => 'jurnal', 'api/journals/*' => 'jurnal',
        'api/class-notes' => 'jkelas', 'api/class-notes/*' => 'jkelas',
        'api/my-class' => 'struktur',
        'api/assignments' => 'tugas', 'api/assignments/*' => 'tugas', 'api/my-folders' => 'tugas', 'api/assignment-files' => 'tugas', 'api/assignment-files/*' => 'tugas',
        'api/agenda' => 'agenda', 'api/agenda/*' => 'agenda',
        'api/homeroom' => 'wali', 'api/homeroom/*' => 'wali', 'api/students/*/class-role' => 'wali',
        'api/rosters/*' => 'piket',
        'api/cbt' => 'cbt', 'api/cbt/*' => 'cbt', 'cbt/kartu-token/*' => 'cbt',
        'api/leger' => 'leger', 'api/leger/*' => 'leger', 'rapor/*' => 'leger',
        'api/habits' => 'pembiasaan', 'api/habits/*' => 'pembiasaan', 'api/habit-coordinators' => 'pembiasaan',
        'api/library' => 'library', 'api/library/*' => 'library', 'api/library-audit' => 'library', 'api/library-audit/*' => 'library',
        'api/grade-books' => 'nilai', 'api/grade-books/*' => 'nilai',
        'api/class-cash' => 'kas', 'api/class-cash/*' => 'kas',
    ],

    // Batas kuantitas per paket (null = tanpa batas). Sumber: spreadsheet, tab "Batas Kuantitas".
    // Yang SUDAH ditegakkan di kode: max_students (saat sekolah membuat tagihan & peringatan di panel).
    // Sisanya dicatat sebagai acuan dan ditegakkan bertahap (lihat roadmap).
    'limits' => [
        'starter' => ['max_students' => 300, 'admin_accounts' => 1, 'devices_per_user' => 1, 'storage_mb' => 500, 'history_months' => 12, 'backups_kept' => 7, 'impersonations_per_month' => 2],
        'pro' => ['max_students' => 1000, 'admin_accounts' => 3, 'devices_per_user' => 1, 'storage_mb' => 2000, 'history_months' => 24, 'backups_kept' => 14, 'impersonations_per_month' => 5],
        'all_service' => ['max_students' => null, 'admin_accounts' => null, 'devices_per_user' => 2, 'storage_mb' => 10000, 'history_months' => null, 'backups_kept' => 30, 'impersonations_per_month' => null],
    ],

    // Periode yang bisa dipilih sekolah dan diskonnya (persen).
    'periods' => [
        1 => ['label' => '1 bulan', 'discount' => 0],
        6 => ['label' => '6 bulan (1 semester)', 'discount' => 5],
        12 => ['label' => '12 bulan (1 tahun ajaran)', 'discount' => 16.67], // ≈ gratis 2 bulan
    ],

    // Tagihan minimal untuk sekian siswa; kuota dibulatkan ke atas kelipatan 'quota_step'.
    'min_students' => (int) env('BILLING_MIN_STUDENTS', 50), // (lama, tidak dipakai lagi)
    'quota_step' => 10,                                      // (lama, tidak dipakai lagi)
    // Tagihan per siswa AKTIF persis; minimal sekian siswa per sekolah.
    'min_billed_students' => (int) env('BILLING_MIN_BILLED_STUDENTS', 20),

    // Pajak (persen dari subtotal). 0 = tidak dipungut. Konsultasikan status PPN layanan ini dengan konsultan pajak.
    'tax_percent' => (float) env('BILLING_TAX_PERCENT', 0),
    'tax_label' => env('BILLING_TAX_LABEL', 'PPN'),

    'due_days' => 7,                    // jatuh tempo invoice baru
    // Kode unik 1–999 ditambahkan ke total invoice baru agar transfer mudah dicocokkan dengan mutasi bank (panel Keuangan).
    'unique_code' => (bool) env('BILLING_UNIQUE_CODE', true),
    'renewal_days_before' => 14,        // invoice perpanjangan dibuat sekian hari sebelum masa aktif berakhir
    'reminder_days' => [14, 7, 3, 1],   // pengingat sebelum trial/langganan berakhir
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7), // masa tenggang setelah berakhir sebelum diblokir

    // Gateway: midtrans | xendit | manual (transfer bank saja)
    'gateway' => env('PAYMENT_GATEWAY', 'manual'),

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'production' => (bool) env('MIDTRANS_PRODUCTION', false),
    ],

    'xendit' => [
        'secret_key' => env('XENDIT_SECRET_KEY'),
        'callback_token' => env('XENDIT_CALLBACK_TOKEN'),
    ],

    // Penerbit invoice (tampil di invoice & kwitansi).
    'seller' => [
        'name' => env('BILLING_SELLER_NAME', 'Parama Tech'),
        'address' => env('BILLING_SELLER_ADDRESS', '[alamat Parama Tech]'),
        'email' => env('BILLING_SELLER_EMAIL', 'billing@mypresence.id'),
        'phone' => env('BILLING_SELLER_PHONE', '[nomor WhatsApp]'),
        'npwp' => env('BILLING_SELLER_NPWP'),
    ],

    // Selalu tampil sebagai alternatif: transfer bank lalu unggah bukti, dikonfirmasi tim Parama Tech.
    'bank' => [
        'name' => env('BILLING_BANK_NAME', '[nama bank]'),
        'account' => env('BILLING_BANK_ACCOUNT', '[nomor rekening]'),
        'holder' => env('BILLING_BANK_HOLDER', '[atas nama]'),
    ],
];
