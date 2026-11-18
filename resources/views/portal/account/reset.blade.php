@extends('portal.account.auth-layout')
@section('title', 'Password baru')
@section('content')
@if (! $valid)
  <div class="alert er" style="margin-top:12px">Tautan ini sudah kedaluwarsa atau sudah dipakai. <a href="{{ route('portal.password.forgot') }}">Minta tautan baru</a>.</div>
@else
<form method="post" action="{{ route('portal.password.reset', $token) }}">@csrf
  <label for="password">Password baru</label><input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
  <label for="password_confirmation">Ulangi password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
  @error('password')<div class="err">{{ $message }}</div>@enderror
  <button class="btn w" type="submit">Simpan password</button>
</form>
@endif
@endsection
