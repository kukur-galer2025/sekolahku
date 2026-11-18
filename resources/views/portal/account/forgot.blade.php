@extends('portal.account.auth-layout')
@section('title', 'Lupa password')
@section('content')
<p class="tag">Masukkan email akunmu. Kami kirim tautan untuk membuat password baru.</p>
<form method="post" action="{{ route('portal.password.forgot') }}">@csrf
  <label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
  @error('email')<div class="err">{{ $message }}</div>@enderror
  <button class="btn w" type="submit">Kirim tautan</button>
</form>
<p class="hint">Siswa atau guru tanpa email: minta wali kelas atau admin sekolah membuatkan password sementara.</p>
@endsection
