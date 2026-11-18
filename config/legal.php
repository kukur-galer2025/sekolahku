<?php

/*
| Dokumen hukum MyPresence. Naikkan 'version' setiap isi dokumen berubah secara material:
| admin sekolah akan diminta menyetujui ulang Perjanjian Pemrosesan Data (DPA).
|
| PENTING: teks dokumen adalah DRAF yang disusun berdasarkan UU 27/2022 (PDP) dan PP 33/2026.
| Wajib ditinjau konsultan hukum sebelum dipakai resmi, lalu set LEGAL_DRAFT=false.
*/

return [
    'draft' => (bool) env('LEGAL_DRAFT', true),

    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '2026-11-03'),

    'company' => [
        'name' => env('LEGAL_COMPANY', 'Parama Tech'),           // ganti dengan nama badan hukum, mis. PT Parama Teknologi Indonesia
        'address' => env('LEGAL_ADDRESS', '[alamat kantor Parama Tech]'),
        'email' => env('LEGAL_EMAIL', 'privasi@mypresence.id'),
        'dpo' => env('LEGAL_DPO', 'Pejabat Pelindungan Data Pribadi Parama Tech'),
    ],

    'documents' => [
        'terms' => ['title' => 'Syarat Layanan', 'slug' => 'syarat-layanan', 'version' => '1.0'],
        'privacy' => ['title' => 'Kebijakan Privasi', 'slug' => 'kebijakan-privasi', 'version' => '1.0'],
        'dpa' => ['title' => 'Perjanjian Pemrosesan Data Pribadi', 'slug' => 'perjanjian-pemrosesan-data', 'version' => '1.0'],
    ],

    // Subprosesor (pihak ketiga yang ikut memproses data atas perintah Parama Tech). Perbarui saat memilih penyedia.
    'subprocessors' => [
        ['name' => env('SUBPROCESSOR_HOSTING', '[penyedia VPS/cloud]'), 'purpose' => 'Server aplikasi & database', 'location' => 'Indonesia'],
        ['name' => env('SUBPROCESSOR_STORAGE', '[penyedia object storage]'), 'purpose' => 'Penyimpanan berkas & backup terenkripsi', 'location' => 'Indonesia'],
        ['name' => env('SUBPROCESSOR_EMAIL', '[penyedia email transaksional]'), 'purpose' => 'Email verifikasi & reset password (alamat email & nama admin/guru)', 'location' => '[negara]'],
        ['name' => 'Midtrans / Xendit (tahap 6)', 'purpose' => 'Pembayaran langganan (data penagihan sekolah, bukan data siswa)', 'location' => 'Indonesia'],
    ],
];
