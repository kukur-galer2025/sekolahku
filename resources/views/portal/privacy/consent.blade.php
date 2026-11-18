@extends('portal.layout')
@section('title', 'Persetujuan orang tua')
@section('content')
<p class="mu"><a href="{{ route('portal.privacy') }}">‹ Data & Privasi</a></p>
<h2>Persetujuan orang tua/wali · {{ $classroom->name }}</h2>
<p class="sub">Centang siswa yang persetujuan orang tua/walinya sudah diterima dan diverifikasi, lalu isi keterangan buktinya (mis. "Formulir PPDB 2026 no. 0123, disimpan di TU").</p>
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('portal.privacy.consent') }}">
  @csrf
  <div class="card">
    <div class="sc"><table>
      <tr><th><input type="checkbox" id="all" aria-label="Pilih semua" data-action="check-all" data-target=".sid"></th><th>NIS</th><th>Nama</th><th>Status persetujuan</th><th>Bukti</th></tr>
      @foreach ($students as $s)
        <tr><td><input type="checkbox" class="sid" name="student_ids[]" value="{{ $s->id }}" aria-label="Pilih {{ $s->name }}"></td><td>{{ $s->nis }}</td><td><b>{{ $s->name }}</b></td>
          <td>{!! $s->parent_consent_at ? '<span class="pl ok">'.e($s->parent_consent_at->format('d/m/Y')).' · '.e($s->parent_consent_method).'</span>' : '<span class="pl wr">Belum</span>' !!}</td>
          <td class="wrap mu">{{ $s->parent_consent_ref ?? '-' }}</td></tr>
      @endforeach
    </table></div>
  </div>
  <div class="card" style="max-width:720px">
    <div class="form-grid">
      <div><label for="method">Cara persetujuan</label><select id="method" name="method"><option value="kertas">Formulir kertas bertanda tangan</option><option value="elektronik">Elektronik terverifikasi</option></select></div>
      <div><label for="consent_date">Tanggal persetujuan</label><input id="consent_date" name="consent_date" type="date" value="{{ now()->toDateString() }}" required></div>
      <div class="full"><label for="ref">Keterangan bukti</label><input id="ref" name="ref" value="{{ old('ref') }}" placeholder="Formulir PPDB 2026, arsip TU rak B" required></div>
    </div>
    <div class="row" style="margin-top:14px">
      <button class="btn nf" type="submit" name="action" value="record">Catat persetujuan</button>
      <button class="btn o nf" type="submit" name="action" value="revoke">Tandai persetujuan ditarik</button>
    </div>
  </div>
</form>
@endsection
