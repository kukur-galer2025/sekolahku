@extends('central.layout')
@section('title', 'Sekolah terdaftar')
@section('content')
<div class="card" style="max-width:620px;margin:0 auto">
  <span class="pl ok">Berhasil</span>
  <h2 style="margin-top:10px">{{ $r['name'] }} sudah terdaftar</h2>
  <p class="sub">Kami mengirim email verifikasi ke <b>{{ $r['email'] }}</b>. Buka tautannya, lalu masuk ke portal sekolahmu.</p>
  <div class="kv"><span class="mu">Link portal</span><span><a href="{{ $r['url'] }}">{{ $r['url'] }}</a></span>
    <span class="mu">Username admin</span><span><b>admin</b> atau email di atas</span>
    @if (! empty($r['code']))<span class="mu">Kode sekolah</span><span><b style="font-size:20px;letter-spacing:3px">{{ \App\Support\SchoolCode::format($r['code']) }}</b> · untuk guru & siswa di {{ \App\Models\School::codeEntryUrl('app') }}</span>@endif</div>
  <div class="steps">
    <div class="step"><span class="dot">1</span><span class="grow">Verifikasi email dari kotak masuk (periksa juga folder spam).</span></div>
    <div class="step"><span class="dot">2</span><span class="grow">Masuk, lalu impor Jam KBM, data siswa, data guru, dan jadwal dari Excel.</span></div>
    <div class="step"><span class="dot">3</span><span class="grow">Daftarkan jaringan WiFi sekolah dan cetak QR kelas.</span></div>
  </div>
  <a class="btn w" href="{{ $r['url'] }}">Buka portal sekolah</a>
</div>
@endsection
