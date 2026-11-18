@extends('legal.layout', ['key' => 'privacy'])
@section('doc')
@php $c = config('legal.company'); @endphp
<p>Kebijakan ini menjelaskan bagaimana data pribadi diproses di MyPresence. Untuk data siswa, guru, dan pegawai yang dikelola Sekolah, <b>Sekolah adalah Pengendali Data Pribadi</b> dan {{ $c['name'] }} memprosesnya atas nama Sekolah. Untuk data akun admin sekolah dan data penagihan, {{ $c['name'] }} adalah pengendali.</p>

<h2>1. Data yang diproses</h2>
<ul>
<li><b>Siswa:</b> NIS, NISN, nama, jenis kelamin, kelas, tanggal lahir (bila diisi Sekolah), foto profil (opsional), riwayat presensi (tanggal, status, jam scan, keterlambatan), perangkat terdaftar, dan alamat IP saat scan.</li>
<li><b>Guru & pegawai:</b> NIK/nomor pegawai, nama, mata pelajaran, jabatan, email/nomor HP (opsional), jadwal mengajar.</li>
<li><b>Admin sekolah:</b> nama, email, nomor HP, catatan login dan tindakan penting.</li>
<li><b>Data teknis:</b> alamat IP, jenis perangkat & peramban, waktu akses, untuk keamanan dan pencegahan kecurangan.</li>
</ul>
<p>Kami <b>tidak</b> menggunakan data siswa untuk iklan, profil pemasaran, atau dijual kepada pihak lain.</p>

<h2>2. Tujuan dan dasar pemrosesan</h2>
<ul>
<li>Mencatat dan merekap kehadiran, jadwal, dan administrasi pembelajaran sesuai perintah Sekolah.</li>
<li>Mengamankan akun dan mencegah kecurangan presensi (pembatasan WiFi sekolah, satu akun satu perangkat, jam pelajaran).</li>
<li>Memberi dukungan teknis, menjalankan backup, dan memenuhi kewajiban hukum.</li>
</ul>

<h2>3. Data anak</h2>
<p>Sebagian besar siswa adalah anak. Pemrosesan data pribadi anak memerlukan <b>persetujuan orang tua atau wali</b>. Sekolah, sebagai pengendali, memperoleh dan memverifikasi persetujuan tersebut (misalnya melalui formulir pendaftaran bertanda tangan atau persetujuan elektronik terverifikasi); MyPresence menyediakan pencatatan bukti persetujuan per siswa. Orang tua/wali dapat mengajukan hak atas data anaknya melalui Sekolah.</p>

<h2>4. Penyimpanan, keamanan, dan retensi</h2>
<ul>
<li>Data disimpan di server di Indonesia. Kata sandi disimpan dalam bentuk hash; backup dienkripsi; akses tim kami dicatat.</li>
<li>Data disimpan selama Sekolah berlangganan. Setelah berhenti, data dapat diekspor selama 30 hari lalu dihapus. Backup terenkripsi terhapus mengikuti rotasi paling lama {{ config('tenancy.backup_keep') }} hari setelahnya.</li>
<li>Berkas impor Excel dan daftar kata sandi awal dihapus otomatis setelah 7 hari.</li>
</ul>

<h2>5. Pihak ketiga (subprosesor)</h2>
<table><tr><th>Pihak</th><th>Keperluan</th><th>Lokasi</th></tr>
@foreach (config('legal.subprocessors') as $p)<tr><td class="wrap">{{ $p['name'] }}</td><td class="wrap">{{ $p['purpose'] }}</td><td>{{ $p['location'] }}</td></tr>@endforeach</table>

<h2>6. Hak subjek data</h2>
<p>Siswa, orang tua/wali, guru, dan pegawai berhak meminta akses, salinan, perbaikan, penghapusan, pembatasan, atau menarik persetujuan. Ajukan melalui Sekolah; kami membantu Sekolah memenuhinya dalam batas waktu yang ditetapkan peraturan (saat ini 3×24 jam setelah identitas dan kewenangan pemohon terverifikasi).</p>

<h2>7. Kegagalan pelindungan data</h2>
<p>Bila terjadi kegagalan pelindungan data pribadi, kami memberi tahu Sekolah tanpa penundaan agar Sekolah dapat memberi tahu subjek data dan lembaga paling lambat 3×24 jam sebagaimana diatur Pasal 46 UU 27/2022, termasuk data yang terungkap, waktu dan cara kejadian, serta langkah penanganan dan pemulihan.</p>

<h2>8. Kontak</h2>
<p>{{ $c['dpo'] }} · <span style="user-select:all">{{ $c['email'] }}</span></p>
@endsection
