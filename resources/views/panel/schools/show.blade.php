@extends('panel.layout')
@section('title', $school->name)
@section('content')
@php
  $bytes = \App\Services\Platform\SchoolUsage::bytes($u['storage_bytes']);
  $plan = \App\Support\Plan::normalize($school->plan);
  $perClass = $u['classrooms'] ? (int) round($u['students'] / $u['classrooms']) : null;
@endphp
<div class="sd">
<a class="sd-back" href="{{ route('panel.schools') }}"><x-ti n="arrow-left"/>Semua sekolah</a>
<div class="sd-head">
  <div class="sd-title">
    <h2>{{ $school->name }}</h2>
    <p class="sub"><a href="{{ $school->url('masuk') }}">{{ $school->subdomain }}.{{ config('tenancy.central_domain') }}</a> · kode <b>{{ \App\Support\SchoolCode::format($school->access_code) }}</b> · terdaftar {{ $school->created_at->timezone('Asia/Jakarta')->format('d/m/Y') }}
      @if ($school->isVerified()) · terverifikasi {{ $school->verified_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB @endif</p>
  </div>
  <div class="sd-badges"><span class="pl mu">Paket {{ \App\Support\Plan::label($plan) }}</span>@include('panel.status', ['school' => $school])</div>
</div>

@if (session('created'))
  <div class="alert ok"><b>Sekolah dibuat.</b> Berikan akun ini ke admin sekolah (ditampilkan sekali):
    <div class="kv" style="margin-top:8px"><span>Username</span><span><b>admin</b> atau {{ session('created.email') }}</span><span>Password sementara</span><span><span class="secret">{{ session('created.password') }}</span></span></div></div>
@endif
@if (session('reset'))
  <div class="alert ok"><b>Password admin {{ session('reset.name') }} direset.</b> Password sementara (ditampilkan sekali, wajib diganti saat login): <span class="secret">{{ session('reset.password') }}</span></div>
@endif
@if ($school->deletion_requested_at)
  <div class="alert er"><b>Admin sekolah meminta penghapusan data</b> pada {{ $school->deletion_requested_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB. Alasan: {{ $school->deletion_reason }}. Konfirmasi ke sekolah, tangguhkan, lalu hapus permanen di bawah.</div>
@endif
@unless ($school->isVerified())
  <div class="alert wr sd-verify">
    <span><b>Sekolah belum diverifikasi.</b> Kuota dibatasi {{ config('tenancy.unverified_student_quota') }} siswa dan guru/siswa belum bisa masuk.
      NPSN: <b>{{ $school->npsn ?: 'belum diisi' }}</b>@if ($school->npsn) · <a href="https://referensi.data.kemdikbud.go.id/tabs.php?npsn={{ $school->npsn }}" target="_blank" rel="noopener">cek di Referensi Kemdikbud</a>@endif.
      Pastikan nama dan alamat sekolah cocok sebelum verifikasi.</span>
    <form method="post" action="{{ route('panel.schools.verify', $school) }}">@csrf<button class="btn" type="submit" @disabled(! $school->npsn)>Verifikasi sekolah</button></form>
  </div>
@endunless
@if (! $dpa)<div class="alert wr">Sekolah belum menyetujui Perjanjian Pemrosesan Data versi terbaru.</div>@endif
@if ($school->isSuspended())
  <div class="alert er"><b>{{ $school->isBillingLocked() ? 'Diblokir otomatis (tagihan)' : 'Ditangguhkan manual' }}</b> sejak {{ $school->suspended_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB. Alasan: {{ $school->suspended_reason }}</div>
@endif

{{-- Dua tab: data sekolah & menu/istilah. Tab dipilih lewat hash (#menu-sekolah) agar kembali ke tab yang sama setelah simpan. --}}
<div class="me-tabs sd-tabs" role="tablist" aria-label="Bagian halaman sekolah">
  <button type="button" role="tab" class="on" aria-selected="true" data-sdtab="data">Data sekolah</button>
  <button type="button" role="tab" aria-selected="false" data-sdtab="menu">Menu &amp; istilah @if (\App\Support\SchoolMenu::of($school)['names'] || \App\Support\SchoolMenu::of($school)['hidden'])<span class="pl in" style="margin-left:4px">aktif</span>@endif</button>
</div>
<div data-sdpane="data">
{{-- Kartu statistik: struktur sama (label · angka · keterangan) agar tingginya seragam --}}
<div class="sd-stats">
  <div class="sd-stat"><span class="lbl">Siswa aktif / kuota</span>
    <span class="val">{{ number_format($u['students'], 0, ',', '.') }}<small> / {{ $school->student_quota ? number_format($school->student_quota, 0, ',', '.') : '∞' }}</small></span>
    <span class="note">@if ($u['quota_pct'] !== null)<span class="bar"><i style="width:{{ $u['quota_pct'] }}%"></i></span>{{ $u['quota_pct'] }}% kuota terpakai @else tanpa batas kuota @endif</span></div>
  <div class="sd-stat"><span class="lbl">Guru · kelas</span>
    <span class="val">{{ $u['teachers'] }} · {{ $u['classrooms'] }}</span>
    <span class="note">{{ $perClass !== null ? '± '.$perClass.' siswa per kelas' : 'belum ada kelas' }}</span></div>
  <div class="sd-stat"><span class="lbl">Presensi 30 hari</span>
    <span class="val">{{ number_format($u['attendance_30d'], 0, ',', '.') }}</span>
    <span class="note">{{ $u['attendance_30d'] ? '± '.number_format($u['attendance_30d'] / 30, 0, ',', '.').' per hari' : 'belum ada presensi' }}</span></div>
  <div class="sd-stat"><span class="lbl">Ukuran data</span>
    <span class="val">{{ number_format($u['total_rows'], 0, ',', '.') }}<small> baris</small></span>
    <span class="note">{{ $u['files'] }} berkas · {{ $bytes }}</span></div>
  <div class="sd-stat"><span class="lbl">HP terdaftar</span>
    <span class="val">{{ $u['devices'] }}</span>
    <span class="note">login terakhir {{ $u['last_login'] ? \Illuminate\Support\Carbon::parse($u['last_login'])->diffForHumans() : '-' }}</span></div>
</div>

<form class="card sd-code" method="post" action="{{ route('panel.schools.code', $school) }}">
  @csrf
  <div><span class="mu">Kode sekolah</span><div class="sd-code-val">{{ \App\Support\SchoolCode::format($school->access_code) }}</div></div>
  <div class="sd-code-input"><label for="access_code">Susun kode baru</label>
    <input id="access_code" name="access_code" maxlength="20" autocomplete="off" style="text-transform:uppercase" placeholder="mis. SMPN1KSG · kosongkan untuk kode acak">
    @error('access_code')<div class="err">{{ $message }}</div>@enderror</div>
  <button class="btn" type="submit" data-confirm="Ganti kode sekolah? Kode lama langsung tidak berlaku. Akun dan HP yang sudah masuk tidak terpengaruh.">Simpan kode</button>
  <p class="mu sd-code-help">Guru & siswa masuk di {{ \App\Models\School::codeEntryUrl('siswa') }} / {{ \App\Models\School::codeEntryUrl('guru') }} dengan kode ini. Bagikan kode ke admin sekolah; admin hanya bisa melihat, tidak mengubah.</p>
</form>

<div class="sd-cols">
  <form class="card" method="post" action="{{ route('panel.schools.update', $school) }}">
    @csrf @method('PUT')
    <b>Identitas & paket</b>

    <span class="sd-label">Paket langganan</span>
    <div class="sd-plans" role="radiogroup" aria-label="Paket langganan">
      @foreach (\App\Support\Plan::all() as $key => $p)
        <label class="sd-plan"><input type="radio" name="plan" value="{{ $key }}" @checked(old('plan', $plan) === $key)>
          <b>{{ $p['label'] }}</b>
          <span class="price">{{ \App\Support\Plan::priceLabel($key) }}<small> /siswa/bulan</small></span>
          <span class="mu">{{ $p['summary'] }}</span>
          <span class="mu">Maks. {{ \App\Support\Plan::limit($key, 'max_students') ? number_format(\App\Support\Plan::limit($key, 'max_students'), 0, ',', '.').' siswa' : 'siswa tanpa batas' }} · {{ count(\App\Support\Plan::locked($key)) ? count(\App\Support\Plan::locked($key)).' fitur terkunci' : 'semua fitur' }}</span></label>
      @endforeach
    </div>
    @if (\App\Support\Plan::price($plan) === null)<p class="mu" style="margin:6px 0 0">Harga paket belum ditetapkan. Tagihan sementara memakai harga umum {{ \App\Models\Invoice::rupiah((int) config('billing.price_per_student_month')) }}/siswa/bulan. <a href="{{ route('panel.plans') }}">Atur harga</a></p>@endif
    @error('plan')<div class="err">{{ $message }}</div>@enderror
    @php $maxStudents = \App\Support\Plan::limit($plan, 'max_students'); @endphp
    @if ($maxStudents !== null && (int) $school->student_quota > $maxStudents)
      <div class="alert wr" style="margin:10px 0 0">Kuota {{ number_format($school->student_quota, 0, ',', '.') }} siswa melebihi batas paket {{ \App\Support\Plan::label($plan) }} ({{ number_format($maxStudents, 0, ',', '.') }} siswa). Naikkan paket atau turunkan kuota.</div>
    @endif

    <label for="name">Nama sekolah</label><input id="name" name="name" value="{{ old('name', $school->name) }}">
    <div class="form-grid">
      <div><label for="npsn">NPSN</label><input id="npsn" name="npsn" value="{{ old('npsn', $school->npsn) }}"></div>
      <div><label for="level">Jenjang</label><select id="level" name="level">@foreach (\App\Support\SchoolLevel::LABELS as $v => $l)<option value="{{ $v }}" @selected(old('level', $school->schoolLevel()) === $v)>{{ $l }}</option>@endforeach</select></div>
      <div><label for="timezone">Zona waktu</label><select id="timezone" name="timezone">@foreach (\App\Http\Controllers\Central\RegistrationController::TIMEZONES as $v => $l)<option value="{{ $v }}" @selected($school->timezone === $v)>{{ $l }}</option>@endforeach</select></div>
      <div><label for="status">Status</label><select id="status" name="status" @disabled($school->isSuspended())><option value="trial" @selected($school->status->value === 'trial')>Trial</option><option value="active" @selected($school->status->value === 'active')>Aktif</option></select></div>
      <div><label for="student_quota">Kuota siswa</label><input id="student_quota" name="student_quota" type="number" min="1" value="{{ old('student_quota', $school->student_quota) }}"></div>
      <div><label for="trial_ends_at">Trial berakhir</label><input id="trial_ends_at" name="trial_ends_at" type="date" value="{{ old('trial_ends_at', $school->trial_ends_at?->format('Y-m-d')) }}"></div>
      <div><label for="active_until">Aktif sampai</label><input id="active_until" name="active_until" type="date" value="{{ old('active_until', $school->active_until?->format('Y-m-d')) }}"></div>
    </div>
    <button class="btn" type="submit" style="margin-top:16px">Simpan perubahan</button>
  </form>

  <div class="sd-side">
    <div class="card">
      <b>Admin sekolah</b>
      @forelse ($admins as $a)
        <div class="sd-admin">
          <span class="mu" style="font-size:11px">Nama admin / operator</span><b class="name">{{ $a->name }}</b>
          <span class="mu email">{{ $a->email ?? '-' }}</span>
          <span class="badges">{!! $a->email_verified_at ? '<span class="pl ok">email terverifikasi</span>' : '<span class="pl wr">email belum verifikasi</span>' !!}
            {!! $a->two_factor_confirmed_at ? '<span class="pl ok">2FA aktif</span>' : '<span class="pl wr">2FA belum aktif</span>' !!}</span>
          <form method="post" action="{{ route('panel.schools.reset-admin', $school) }}">@csrf<input type="hidden" name="user_id" value="{{ $a->id }}"><button class="btn o" type="submit" title="Password sementara baru + hapus 2FA (mis. HP hilang)">Reset password &amp; 2FA</button></form>
        </div>
      @empty <p class="mu">Belum ada akun admin.</p> @endforelse
    </div>

    <form class="card" method="post" action="{{ route('panel.schools.impersonate', $school) }}" target="_blank">
      @csrf
      <b>Masuk sebagai sekolah</b>
      <p class="mu" style="margin:4px 0 0">Untuk membantu admin sekolah. Tercatat di log sekolah dan terlihat oleh admin sekolah.</p>
      <label for="reason">Alasan (wajib)</label><input id="reason" name="reason" placeholder="Mis. bantu impor data siswa, tiket #123" required minlength="5">
      <button class="btn" type="submit" style="margin-top:10px" @disabled($school->isSuspended())>Masuk sebagai admin sekolah ↗</button>
    </form>

    <div class="card danger">
      @if ($school->isSuspended())
        <b>Aktifkan kembali</b>
        <p class="mu" style="margin:4px 0 10px">Portal sekolah bisa diakses lagi. Bila masa aktif sudah lewat, sekolah kembali ke trial 14 hari.</p>
        <form method="post" action="{{ route('panel.schools.activate', $school) }}">@csrf<button class="btn" type="submit">Aktifkan sekolah</button></form>
      @else
        <b>Tangguhkan sekolah</b>
        <p class="mu" style="margin:4px 0 0">Portal & aplikasi sekolah langsung tidak bisa diakses. Data tidak dihapus.</p>
        <form method="post" action="{{ route('panel.schools.suspend', $school) }}">@csrf
          <label for="sreason">Alasan</label><input id="sreason" name="reason" placeholder="Mis. tagihan belum dibayar" required>
          <button class="btn er" type="submit" style="margin-top:10px">Tangguhkan</button></form>
      @endif
    </div>
  </div>
</div>

<div class="sd-cols sd-cols-even">
  <div class="card">
    <div class="row" style="align-items:center"><div><b>Backup sekolah</b><p class="mu" style="margin-top:2px">Harian 01.30 WIB, disimpan {{ config('tenancy.backup_keep') }} terakhir{{ config('tenancy.backup_password') ? ', terenkripsi AES-256' : ' — BACKUP_PASSWORD belum diisi!' }}.</p></div>
      <form class="nf" method="post" action="{{ route('panel.schools.backup', $school) }}">@csrf<button class="btn o" type="submit">Backup sekarang</button></form></div>
    <div class="steps">
      @forelse (array_slice($backups, 0, 6) as $b)
        <div class="step"><span class="grow">{{ $b['name'] }}<span class="mu" style="display:block">{{ \App\Services\Platform\SchoolUsage::bytes($b['size']) }}</span></span><a class="btn o nf" href="{{ route('panel.schools.backup.download', [$school, $b['name']]) }}">Unduh</a></div>
      @empty <p class="mu">Belum ada backup.</p> @endforelse
    </div>
    <p class="mu" style="margin-top:10px">Pemulihan sengaja hanya lewat server: <code>php artisan school:restore {{ $school->subdomain }} &lt;nama-backup&gt;</code> (otomatis membuat backup pengaman dulu).</p>
  </div>
  <div class="card danger">
    <b>Hapus sekolah permanen</b>
    <p class="mu" style="margin:6px 0">Menghapus semua data database, berkas, dan backup sekolah ini. Tidak bisa dibatalkan. Hanya untuk sekolah yang sudah ditangguhkan.</p>
    @if ($school->isSuspended())
      <form method="post" action="{{ route('panel.schools.destroy', $school) }}">@csrf @method('DELETE')
        <label for="confirm">Ketik <b>{{ $school->subdomain }}</b> untuk konfirmasi</label><input id="confirm" name="confirm" autocomplete="off" required>
        <button class="btn er" type="submit" style="margin-top:10px">Hapus permanen</button></form>
    @else <p class="mu">Tangguhkan sekolah terlebih dahulu.</p> @endif
  </div>
</div>

<div class="card" style="margin-top:14px" id="tagihan">
  <b>Tagihan</b>
  <div class="sc" style="margin-top:10px"><table><tr><th>Nomor</th><th>Tanggal</th><th>Rincian</th><th class="amt">Total</th><th>Status</th></tr>
    @forelse ($invoices as $i)
      <tr><td><a href="{{ route('panel.billing.show', $i->id) }}">{{ $i->number }}</a></td><td>{{ $i->created_at->timezone('Asia/Jakarta')->format('d/m/Y') }}</td><td class="wrap">{{ $i->description }}</td>
        <td class="amt">{{ \App\Models\Invoice::rupiah($i->total) }}</td><td>@include('panel.billing.pill', ['i' => $i])</td></tr>
    @empty <tr><td colspan="5" class="mu">Belum ada tagihan.</td></tr> @endforelse
  </table></div>
  <details style="margin-top:12px"><summary class="linkbtn" style="font-size:13px">+ Buat tagihan untuk sekolah ini</summary>
    <div class="three" style="margin-top:10px">
      <form class="card" method="post" action="{{ route('panel.billing.store', $school->id) }}">@csrf <input type="hidden" name="kind" value="package">
        <b>Paket langganan</b>
        <p class="mu" style="margin:4px 0 0">Paket {{ \App\Support\Plan::label($plan) }} · {{ \App\Models\Invoice::rupiah(app(\App\Services\Billing\BillingCalculator::class)->unitPrice($plan)) }}/siswa/bulan. Ganti paket di kartu Identitas & paket.</p>
        @php $calcB = app(\App\Services\Billing\BillingCalculator::class); @endphp
        <label>Jumlah siswa ditagih</label><input name="students" type="number" min="1" value="{{ $calcB->normalizeStudents((int) $u['students']) }}" required>
        <p class="mu" style="margin:4px 0 0;font-size:12px">Siswa aktif sekarang: <b>{{ number_format((int) $u['students'], 0, ',', '.') }}</b> (minimal ditagih {{ $calcB->minStudents() }}).
          @if (! $u['students']) <span style="color:var(--er)">Sekolah belum punya data siswa aktif — minta admin sekolah impor siswa dulu.</span> @endif</p>
        <label>Periode</label><select name="months">@foreach (config('billing.periods') as $m => $p)<option value="{{ $m }}" @selected($m == 12)>{{ $p['label'] }}</option>@endforeach</select>
        <label class="check"><input type="checkbox" name="send" value="1" checked> Kirim ke email admin sekolah</label>
        <button class="btn" type="submit" style="margin-top:12px">Buat tagihan paket</button></form>
      <form class="card" method="post" action="{{ route('panel.billing.store', $school->id) }}">@csrf <input type="hidden" name="kind" value="custom">
        <b>Tagihan khusus</b>
        <label>Uraian</label><input name="description" maxlength="200" placeholder="mis. Pelatihan operator & cetak QR kelas" required>
        <label>Nominal (Rp, sebelum pajak)</label><input name="amount" type="number" min="1000" step="1000" required>
        <label class="check"><input type="checkbox" name="send" value="1" checked> Kirim ke email admin sekolah</label>
        <button class="btn o" type="submit" style="margin-top:12px">Buat tagihan khusus</button></form>
    </div>
  </details>
</div>

<details class="card" style="margin-top:14px"><summary>Rincian ukuran data ({{ number_format($u['total_rows'], 0, ',', '.') }} baris)</summary>
  <div class="kv" style="margin-top:10px">@foreach ($u['rows'] as $t => $n)<span class="mu">{{ $t }}</span><span>{{ number_format($n, 0, ',', '.') }}</span>@endforeach</div>
</details>

<div class="card"><details class="logbox" data-key="panel-school-logs">
  <summary><span class="lb-t">Log sekolah ini</span><span class="mu">{{ count($logs) }} catatan terakhir</span><span class="lb-b"><span class="lb-show">Tampilkan</span><span class="lb-hide">Sembunyikan</span></span></summary>
  <div class="sc" style="margin-top:10px"><table><tr><th>Waktu</th><th>Oleh</th><th>Tindakan</th><th>Keterangan</th></tr>
    @forelse ($logs as $l)
      <tr><td>{{ $l->created_at->timezone($school->timezone)->format('d/m/Y H:i') }}</td><td>{{ $l->actor_name }}</td><td><span class="pl mu">{{ $l->action }}</span></td><td class="wrap">{{ $l->description }}</td></tr>
    @empty <tr><td colspan="4" class="mu">Belum ada log.</td></tr> @endforelse
  </table></div></details></div>
</div>{{-- /data --}}
<div data-sdpane="menu" id="menu-sekolah" hidden>
@php $menuNow = \App\Support\SchoolMenu::of($school); $menuN = array_sum(array_map('count', $menuNow['names'])) + count($menuNow['hidden']); @endphp
<form class="card" id="menu-sekolah-form" method="post" action="{{ route('panel.schools.menu', $school->id) }}">@csrf @method('PUT')
  <div class="row" style="justify-content:space-between;align-items:flex-start">
    <div><b>Menu &amp; istilah sekolah ini</b>
      <p class="mu" style="margin-top:2px">Ganti nama menu per portal atau sembunyikan menu yang tidak dipakai — hanya untuk {{ $school->name }}. @if ($menuN) <span class="pl in">{{ $menuN }} pengaturan aktif</span> @else <span class="pl mu">Memakai nama bawaan</span> @endif</p></div>
    <a class="btn o nf" href="{{ route('panel.menu.templates') }}">Kelola template</a>
  </div>
  @include('panel.menu-editor', ['catalog' => \App\Support\SchoolMenu::catalogForUi(), 'value' => $menuNow, 'locked' => \App\Support\Plan::locked($school->plan),
    'templates' => \App\Models\MenuTemplate::query()->orderBy('name')->get(), 'schoolName' => $school->name])
  <div class="row" style="margin-top:12px;gap:8px">
    <button class="btn" type="submit">Simpan menu sekolah</button>
    @if ($menuN) <button class="btn o" type="submit" name="reset" value="1" data-confirm="Kembalikan semua menu sekolah ini ke nama bawaan?" data-action="reset-menu">Kembalikan ke bawaan</button> @endif
  </div>
</form>
</div>
<script @nonce>
(() => {
  const tabs = document.querySelector('.sd-tabs'); if (!tabs) return;
  const show = (t, push) => {
    tabs.querySelectorAll('[data-sdtab]').forEach((b) => { const on = b.dataset.sdtab === t; b.classList.toggle('on', on); b.setAttribute('aria-selected', on); });
    document.querySelectorAll('[data-sdpane]').forEach((x) => (x.hidden = x.dataset.sdpane !== t));
    if (push) history.replaceState(null, '', t === 'menu' ? '#menu-sekolah' : location.pathname + location.search);
  };
  tabs.addEventListener('click', (e) => { const b = e.target.closest('[data-sdtab]'); if (b) show(b.dataset.sdtab, true); });
  if (location.hash === '#menu-sekolah') { show('menu'); requestAnimationFrame(() => tabs.scrollIntoView({ block: 'start' })); }
})();
</script>
</div>
@endsection
