@extends('legal.layout', ['key' => 'terms'])
@section('doc')
@php $c = config('legal.company'); @endphp
<p>Syarat Layanan ini mengatur penggunaan MyPresence, layanan perangkat lunak berbasis langganan untuk presensi dan administrasi sekolah yang disediakan oleh <b>{{ $c['name'] }}</b> ("kami"), oleh sekolah atau penyelenggara pendidikan ("Sekolah") beserta pengguna yang diberi akses oleh Sekolah (admin, guru, siswa).</p>

<h2>1. Akun dan wewenang</h2>
<ol>
<li>Orang yang mendaftarkan Sekolah menyatakan berwenang mewakili Sekolah atau yayasannya.</li>
<li>Sekolah bertanggung jawab atas akun admin, guru, dan siswa yang dibuatnya, termasuk menjaga kerahasiaan kata sandi dan segera menonaktifkan akun yang tidak lagi berhak.</li>
<li>Setiap Sekolah mendapat alamat portal sendiri ({subdomain}.{{ config('tenancy.central_domain') }}). Data tiap Sekolah dipisahkan dan tidak dapat diakses Sekolah lain.</li>
</ol>

<h2>2. Paket, trial, dan pembayaran</h2>
<ol>
<li>Sekolah baru mendapat masa uji coba {{ config('tenancy.trial_days') }} hari dengan kuota {{ config('tenancy.trial_student_quota') }} siswa aktif.</li>
<li>Biaya langganan dihitung per jumlah siswa aktif sesuai paket yang dipilih. Kuota yang terlampaui menahan penambahan siswa baru, bukan menghapus data.</li>
<li>Bila tagihan tidak dibayar setelah jatuh tempo, kami dapat menangguhkan akses. Data tidak dihapus selama masa penangguhan dan dapat dipulihkan setelah pembayaran.</li>
</ol>

<h2>3. Penggunaan yang dilarang</h2>
<ul>
<li>Memalsukan presensi, mengakali pembatasan jaringan, perangkat, atau jam pelajaran, atau membagikan akun.</li>
<li>Mengunggah konten yang melanggar hukum, berbahaya, atau melanggar hak pihak lain.</li>
<li>Mencoba mengakses data Sekolah lain, menguji keamanan tanpa izin tertulis, atau membebani layanan secara tidak wajar.</li>
</ul>

<h2>4. Data dan privasi</h2>
<ol>
<li>Data yang diunggah atau dihasilkan Sekolah tetap milik Sekolah. Sekolah adalah <b>Pengendali Data Pribadi</b>; kami bertindak sebagai <b>Prosesor Data Pribadi</b> atas perintah Sekolah, sebagaimana diatur dalam <a href="{{ route('legal.show', 'perjanjian-pemrosesan-data') }}">Perjanjian Pemrosesan Data Pribadi</a>.</li>
<li>Pengolahan data pribadi pengguna dijelaskan dalam <a href="{{ route('legal.show', 'kebijakan-privasi') }}">Kebijakan Privasi</a>.</li>
<li>Sekolah dapat mengekspor seluruh datanya kapan saja melalui menu Data &amp; Privasi, dan dapat meminta penghapusan data.</li>
</ol>

<h2>5. Ketersediaan layanan dan dukungan</h2>
<ol>
<li>Kami berupaya menjaga layanan tersedia setiap hari sekolah, melakukan backup harian per Sekolah, dan memberi tahu pemeliharaan terjadwal lebih dulu.</li>
<li>Tim kami hanya membuka portal Sekolah untuk membantu bila diperlukan; setiap akses dicatat dan terlihat oleh admin Sekolah di menu Log Akses.</li>
</ol>

<h2>6. Tanggung jawab</h2>
<ol>
<li>Kami bertanggung jawab atas kerugian yang timbul karena kelalaian kami dalam menjalankan layanan dan kewajiban pelindungan data sesuai peraturan perundang-undangan.</li>
<li>Kami tidak bertanggung jawab atas kerugian akibat kesalahan input data oleh Sekolah, penyalahgunaan akun oleh pengguna Sekolah, atau gangguan di luar kendali wajar kami (keadaan kahar).</li>
</ol>

<h2>7. Berakhirnya layanan</h2>
<ol>
<li>Sekolah dapat berhenti berlangganan kapan saja. Setelah berhenti, Sekolah dapat mengekspor data selama 30 hari, kemudian data dihapus sesuai Perjanjian Pemrosesan Data.</li>
<li>Kami dapat mengakhiri layanan bagi Sekolah yang melanggar Syarat ini secara berat setelah pemberitahuan tertulis.</li>
</ol>

<h2>8. Perubahan dan hukum yang berlaku</h2>
<p>Perubahan material atas Syarat ini diberitahukan kepada admin Sekolah sebelum berlaku. Syarat ini tunduk pada hukum Republik Indonesia. Perselisihan diselesaikan secara musyawarah terlebih dahulu.</p>
@endsection
