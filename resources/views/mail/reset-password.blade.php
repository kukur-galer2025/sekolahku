@extends('mail.layout')
@section('body')
<p style="margin:0 0 10px">Halo {{ $user->name }},</p>
<p style="margin:0 0 18px">Ada permintaan mengatur ulang password akunmu di portal <b>{{ $school->name }}</b> ({{ $school->url() }}).</p>
<p style="margin:0 0 18px"><a href="{{ $url }}" style="display:inline-block;background:#0B63E5;color:#fff;text-decoration:none;font-weight:600;padding:11px 18px;border-radius:8px">Buat password baru</a></p>
<p style="margin:0;font-size:12px;color:#647797">Tautan berlaku {{ \App\Services\Platform\PasswordResets::MINUTES }} menit dan hanya bisa dipakai sekali.</p>
@endsection
