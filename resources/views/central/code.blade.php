@extends('central.layout')
@section('title', $app ? 'Masuk '.$apps[$app] : 'Masuk dengan kode sekolah')
@section('content')
<form class="card" method="post" action="{{ route('central.code.go') }}" style="max-width:460px;margin:0 auto" id="kf">
  @csrf
  <b style="font-size:19px">{{ $app ? 'Masuk aplikasi '.$apps[$app] : 'Masuk ke aplikasi sekolah' }}</b>
  <p class="mu" style="margin-top:4px">Ketik kode sekolah dari wali kelas atau admin sekolah. Setelah itu masuk dengan
    {{ $app === 'guru' ? 'NIK' : ($app === 'siswa' ? 'NIS' : ($app === 'admin' ? 'akun admin' : 'NIK (guru) atau NIS (siswa)')) }} dan password.</p>

  <label for="code">Kode sekolah</label>
  <input id="code" name="code" value="{{ old('code') }}" required maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false"
    placeholder="Contoh: A7K-2QM" style="font-size:24px;letter-spacing:4px;text-transform:uppercase;text-align:center;font-weight:700">
  @error('code')<div class="err">{{ $message }}</div>@enderror

  @if ($app)
    <input type="hidden" name="app" value="{{ $app }}">
  @else
    <label>Masuk sebagai</label>
    <div class="row" style="gap:10px">
      @foreach (['siswa' => '👩‍🎓 Siswa', 'guru' => '👨‍🏫 Guru'] as $k => $l)
        <label class="check" style="flex:1;border:1px solid var(--bd);border-radius:12px;padding:10px;margin:0;justify-content:center">
          <input type="radio" name="app" value="{{ $k }}" @checked(old('app') === $k) required> {{ $l }}</label>
      @endforeach
    </div>
    @error('app')<div class="err">{{ $message }}</div>@enderror
  @endif

  <button class="btn w" type="submit" style="margin-top:16px">Lanjut</button>
  <p class="mu" style="margin-top:12px;font-size:13px">Kode hanya menunjuk sekolah Anda. Akun dan password tetap milik sekolah itu saja.
    @unless ($app === 'admin') Admin sekolah: <a href="{{ route('central.code', 'admin') }}">masuk di sini</a>.@endunless</p>
</form>
<script @nonce>
(() => {
  // Ingat kode terakhir di HP ini agar tidak perlu mengetik ulang.
  const f = document.getElementById('kf'), c = document.getElementById('code');
  try { if (!c.value) c.value = localStorage.getItem('mp_school_code') || ''; } catch (_) {}
  f.addEventListener('submit', () => { try { localStorage.setItem('mp_school_code', c.value.trim().toUpperCase()); } catch (_) {} });
})();
</script>
@endsection
