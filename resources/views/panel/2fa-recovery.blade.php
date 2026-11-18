@extends('panel.layout')
@section('title', '2FA aktif')
@section('content')
<h2>{{ ! empty($regenerated) ? 'Kode cadangan baru' : '2FA aktif' }}</h2>
<div class="alert ok">{{ ! empty($regenerated) ? 'Kode cadangan lama sudah tidak berlaku. Simpan 10 kode baru di bawah.' : 'Mulai sekarang, login panel meminta kode dari aplikasi authenticator.' }}</div>
<div class="card" style="max-width:620px">
  <b>Kode cadangan</b>
  <p class="mu" style="margin:6px 0 12px">Simpan di tempat aman (mis. pengelola kata sandi). Masing-masing hanya bisa dipakai sekali bila HP hilang. Kode ini hanya ditampilkan sekarang.</p>
  <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px">@foreach ($codes as $c)<span class="secret">{{ $c }}</span>@endforeach</div>
  <a class="btn w" href="{{ ! empty($regenerated) ? route('panel.profile') : route('panel.schools') }}">{{ ! empty($regenerated) ? 'Kembali ke profil' : 'Lanjut ke panel' }}</a>
</div>
@endsection
