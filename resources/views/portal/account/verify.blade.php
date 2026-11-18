@extends('portal.layout')
@section('title', 'Verifikasi email')
@section('content')
<div class="card" style="max-width:560px">
  <span class="pl wr">Satu langkah lagi</span>
  <h2 style="margin-top:10px">Verifikasi email admin</h2>
  <p class="sub">Kami sudah mengirim tautan verifikasi ke <b>{{ $user->email }}</b>. Buka tautan itu untuk mulai mengelola sekolah. Email ini juga dipakai bila kamu lupa password.</p>
  <form method="post" action="{{ route('portal.verify.resend') }}">@csrf<button class="btn" type="submit">Kirim ulang email verifikasi</button></form>
  <p class="mu" style="margin-top:12px">Tidak ada email? Periksa folder spam/promosi. Salah alamat email? Hubungi Parama Tech.</p>
</div>
@endsection
