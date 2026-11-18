@extends('mail.layout')
@section('body')
@php
    $inv = $data['invoice'] ?? null;
    $rp = fn ($n) => \App\Models\Invoice::rupiah($n);
    $btn = 'display:inline-block;background:#0B63E5;color:#fff;text-decoration:none;font-weight:600;padding:11px 18px;border-radius:8px';
    $what = ($data['trial'] ?? false) ? 'masa trial' : 'langganan';
@endphp
<p style="margin:0 0 10px">Halo {{ $user->name }},</p>
@switch($kind)
@case('invoice')
<p style="margin:0 0 10px">Tagihan baru untuk <b>{{ $school->name }}</b>:</p>
<table style="width:100%;border-collapse:collapse;margin:0 0 14px;font-size:14px">
  <tr><td style="padding:4px 0;color:#647797">Nomor</td><td style="text-align:right"><b>{{ $inv->number }}</b></td></tr>
  <tr><td style="padding:4px 0;color:#647797">Rincian</td><td style="text-align:right">{{ $inv->description }}</td></tr>
  <tr><td style="padding:4px 0;color:#647797">Jatuh tempo</td><td style="text-align:right">{{ $inv->due_at->timezone($school->timezone)->translatedFormat('d F Y') }}</td></tr>
  <tr><td style="padding:6px 0;border-top:1px solid #e3e9f3"><b>Total</b></td><td style="text-align:right;border-top:1px solid #e3e9f3"><b>{{ $rp($inv->total) }}</b></td></tr>
  @if ($inv->isPartial())<tr><td style="padding:4px 0;color:#647797">Sudah dibayar (DP)</td><td style="text-align:right">{{ $rp($inv->paid_amount) }}</td></tr>
  <tr><td style="padding:4px 0"><b>Sisa tagihan</b></td><td style="text-align:right"><b>{{ $rp($inv->remaining()) }}</b></td></tr>@endif
</table>
<p style="margin:0 0 18px"><a href="{{ $billingUrl }}/tagihan/{{ $inv->id }}" style="{{ $btn }}">Lihat & bayar tagihan</a></p>
@break
@case('receipt')
<p style="margin:0 0 10px">Terima kasih, pembayaran tagihan <b>{{ $inv->number }}</b> sebesar <b>{{ $rp($inv->total) }}</b> sudah kami terima.</p>
@if ($inv->period_end)<p style="margin:0 0 10px">Layanan MyPresence {{ $school->name }} aktif sampai <b>{{ $inv->period_end->translatedFormat('d F Y') }}</b> dengan kuota <b>{{ number_format($inv->student_count, 0, ',', '.') }} siswa</b>.</p>@endif
<p style="margin:0 0 18px"><a href="{{ $billingUrl }}/tagihan/{{ $inv->id }}/cetak" style="{{ $btn }}">Unduh kwitansi</a></p>
@break
@case('reminder')
<p style="margin:0 0 10px">{{ ucfirst($what) }} MyPresence <b>{{ $school->name }}</b> berakhir pada <b>{{ $data['ends']->timezone($school->timezone)->translatedFormat('d F Y') }}</b> ({{ $data['days'] <= 1 ? 'besok' : $data['days'].' hari lagi' }}).</p>
<p style="margin:0 0 10px">Agar presensi guru dan siswa tetap berjalan, {{ ($data['trial'] ?? false) ? 'pilih paket langganan' : 'lakukan perpanjangan' }} sebelum tanggal tersebut.</p>
@if ($inv)<p style="margin:0 0 10px">Tagihan perpanjangan <b>{{ $inv->number }}</b> sebesar <b>{{ $rp($inv->total) }}</b> sudah tersedia.</p>@endif
<p style="margin:0 0 18px"><a href="{{ $billingUrl }}" style="{{ $btn }}">Buka halaman Langganan</a></p>
@break
@case('expired')
<p style="margin:0 0 10px">{{ ucfirst($what) }} MyPresence <b>{{ $school->name }}</b> sudah berakhir. Layanan masih berjalan selama masa tenggang sampai <b>{{ $data['grace']->timezone($school->timezone)->translatedFormat('d F Y') }}</b>.</p>
<p style="margin:0 0 10px">Setelah itu portal dan aplikasi guru/siswa akan diblokir sampai pembayaran diterima. Data sekolah tetap aman tersimpan.</p>
<p style="margin:0 0 18px"><a href="{{ $billingUrl }}" style="{{ $btn }}">Bayar sekarang</a></p>
@break
@case('suspended')
<p style="margin:0 0 10px">Layanan MyPresence <b>{{ $school->name }}</b> diblokir sementara karena {{ $what }} sudah berakhir dan masa tenggang terlewati.</p>
<p style="margin:0 0 10px">Data sekolah <b>tidak dihapus</b>. Admin tetap bisa masuk ke halaman Langganan; layanan aktif kembali otomatis begitu pembayaran diterima.</p>
<p style="margin:0 0 18px"><a href="{{ $billingUrl }}" style="{{ $btn }}">Aktifkan kembali</a></p>
@break
@endswitch
<p style="margin:0;font-size:12px;color:#647797">Pertanyaan tentang tagihan? Balas email ini atau hubungi tim Parama Tech.</p>
@endsection
