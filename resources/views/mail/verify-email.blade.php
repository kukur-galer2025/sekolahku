@extends('mail.layout')
@section('body')
<p style="margin:0 0 10px">Halo {{ $user->name }},</p>
<p style="margin:0 0 10px">Akun admin <b>{{ $school->name }}</b> di MyPresence sudah dibuat. Link portal sekolahmu:</p>
<p style="margin:0 0 16px;font-weight:600">{{ $school->url() }}</p>
<p style="margin:0 0 18px">Konfirmasi bahwa email ini milikmu agar bisa mulai mengelola sekolah:</p>
<p style="margin:0 0 18px"><a href="{{ $url }}" style="display:inline-block;background:#0B63E5;color:#fff;text-decoration:none;font-weight:600;padding:11px 18px;border-radius:8px">Verifikasi email</a></p>
<p style="margin:0;font-size:12px;color:#647797">Tautan berlaku 3 hari. Username login admin: <b>admin</b> (atau email ini).</p>
@endsection
