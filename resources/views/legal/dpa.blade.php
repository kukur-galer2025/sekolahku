@extends('legal.layout', ['key' => 'dpa'])
@section('doc')
@php $c = config('legal.company'); @endphp
<p>Perjanjian ini ("Perjanjian") dibuat antara <b>Sekolah</b> pengguna MyPresence sebagai <b>Pengendali Data Pribadi</b> dan <b>{{ $c['name'] }}</b> sebagai <b>Prosesor Data Pribadi</b>, berdasarkan Undang-Undang Nomor 27 Tahun 2022 tentang Pelindungan Data Pribadi dan peraturan pelaksananya, termasuk PP Nomor 33 Tahun 2026.</p>

<h2>1. Ruang lingkup pemrosesan</h2>
<ul>
<li><b>Subjek data:</b> siswa (termasuk anak), orang tua/wali bila dicatat, guru, pegawai, dan admin Sekolah.</li>
<li><b>Jenis data:</b> sebagaimana tercantum dalam Kebijakan Privasi bagian 1.</li>
<li><b>Tujuan:</b> presensi, jadwal, administrasi pembelajaran, keamanan akun, dukungan teknis, dan backup.</li>
<li><b>Lama pemrosesan:</b> selama Sekolah berlangganan, ditambah masa ekspor dan penghapusan sesuai bagian 8.</li>
</ul>

<h2>2. Kewajiban Sekolah (Pengendali)</h2>
<ol>
<li>Memastikan dasar pemrosesan yang sah, termasuk <b>memperoleh dan memverifikasi persetujuan orang tua/wali</b> untuk data siswa yang masih anak, serta menyimpan buktinya (dapat dicatat di menu Data &amp; Privasi).</li>
<li>Memberi tahu subjek data tentang pemrosesan melalui MyPresence.</li>
<li>Hanya memasukkan data yang diperlukan untuk tujuan di atas, dan menjaga akurasinya.</li>
<li>Mengatur hak akses admin, guru, dan wali kelas sesuai kebutuhan.</li>
</ol>

<h2>3. Kewajiban {{ $c['name'] }} (Prosesor)</h2>
<ol>
<li>Memproses data <b>hanya atas perintah tertulis Sekolah</b>, yaitu melalui penggunaan fitur MyPresence dan permintaan resmi admin Sekolah.</li>
<li>Menjaga kerahasiaan; personel yang mengakses data terikat kewajiban kerahasiaan.</li>
<li>Menerapkan langkah keamanan teknis dan organisasi, sekurang-kurangnya: pemisahan data per Sekolah yang diuji otomatis, kata sandi ber-hash, autentikasi dua faktor untuk akun super-admin, enkripsi backup, pembatasan dan pencatatan akses tim dukungan, serta backup harian per Sekolah.</li>
<li>Tidak menggunakan data untuk tujuan lain, termasuk iklan atau penjualan data.</li>
<li>Membantu Sekolah memenuhi permintaan hak subjek data dalam batas waktu peraturan.</li>
<li>Mendokumentasikan kegiatan pemrosesan dan membantu Sekolah dalam penilaian dampak pelindungan data bila diperlukan.</li>
</ol>

<h2>4. Akses tim dukungan</h2>
<p>Akses ke portal Sekolah oleh tim {{ $c['name'] }} hanya untuk membantu, wajib disertai alasan, berlaku sementara, dan setiap aksesnya tercatat serta terlihat oleh admin Sekolah di menu Log Akses.</p>

<h2>5. Subprosesor</h2>
<p>Sekolah menyetujui penggunaan subprosesor yang tercantum dalam Kebijakan Privasi. {{ $c['name'] }} memberi tahu admin Sekolah sebelum menambah atau mengganti subprosesor, dan memastikan subprosesor terikat kewajiban pelindungan data yang setara.</p>

<h2>6. Lokasi data dan transfer</h2>
<p>Data disimpan dan diproses di Indonesia. Bila suatu saat diperlukan transfer ke luar wilayah Indonesia, transfer hanya dilakukan sesuai mekanisme yang diatur UU 27/2022 dan peraturan pelaksananya, dengan pemberitahuan kepada Sekolah.</p>

<h2>7. Kegagalan pelindungan data</h2>
<p>{{ $c['name'] }} memberi tahu admin Sekolah <b>tanpa penundaan</b> setelah kegagalan pelindungan data dipastikan, beserta informasi yang dibutuhkan Sekolah untuk memberi tahu subjek data dan lembaga paling lambat 3×24 jam (Pasal 46 UU 27/2022): data yang terungkap, kapan dan bagaimana terjadi, serta langkah penanganan dan pemulihan.</p>

<h2>8. Pengembalian dan penghapusan data</h2>
<ol>
<li>Sekolah dapat mengekspor seluruh datanya kapan saja dalam format Excel.</li>
<li>Atas permintaan Sekolah atau setelah layanan berakhir (dengan masa ekspor 30 hari), {{ $c['name'] }} menghapus seluruh data Sekolah, berkas, dan backup-nya, lalu mencatat penghapusan tersebut.</li>
</ol>

<h2>9. Audit</h2>
<p>Sekolah dapat meminta informasi yang wajar untuk memastikan kepatuhan Perjanjian ini, antara lain ringkasan langkah keamanan dan log akses tim dukungan atas data Sekolah.</p>

<h2>10. Lain-lain</h2>
<p>Perjanjian ini merupakan bagian dari Syarat Layanan. Perjanjian ini tidak mengurangi kewajiban masing-masing pihak menurut peraturan perundang-undangan. Persetujuan dilakukan secara elektronik oleh admin Sekolah yang berwenang, dan dicatat beserta nama, jabatan, waktu, dan alamat IP.</p>
@endsection
