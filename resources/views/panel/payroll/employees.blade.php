@extends('panel.layout')
@section('title', 'Data karyawan')
@section('content')
@php
  $rp = fn ($n) => \App\Models\Invoice::rupiah($n);
  $e = $edit;
  $val = fn ($k) => old($k, $e?->{$k});
  $num = fn ($k) => number_format((int) old($k, $e?->{$k} ?? 0), 0, ',', '.');
  $fields = ['base_salary' => 'Gaji pokok', 'allowance_position' => 'Tunjangan jabatan', 'allowance_meal' => 'Tunjangan makan', 'allowance_transport' => 'Tunjangan transport'];
  $cuts = ['bpjs_tk' => 'BPJS Ketenagakerjaan', 'bpjs_kes' => 'BPJS Kesehatan', 'pph21' => 'PPh 21', 'other_deduction' => 'Potongan lain'];
@endphp
<h2>Penggajian karyawan</h2>
<p class="sub">Data karyawan &amp; komponen gaji bawaan. Saat periode baru dibuat, komponen ini disalin ke tabel gaji (bisa diubah per bulan).</p>
@include('panel.payroll.tabs')

<form class="card" method="post" action="{{ $e ? route('panel.payroll.employees.update', $e->id) : route('panel.payroll.employees.store') }}" id="ef">@csrf @if ($e) @method('PUT') @endif
  <div class="row" style="justify-content:space-between;align-items:center"><b><x-ti n="{{ $e ? 'edit' : 'user-plus' }}"/> {{ $e ? 'Ubah data '.$e->name : 'Tambah karyawan' }}</b>
    @if ($e)<a href="{{ route('panel.payroll.employees') }}" class="mu">Batal ubah</a>@endif</div>
  <div class="emp-grid" style="margin-top:4px">
    <div><label for="nik">NIK / ID karyawan</label><input id="nik" name="nik" maxlength="40" required value="{{ $val('nik') }}" placeholder="PET-001"></div>
    <div style="grid-column:span 2"><label for="nm">Nama karyawan</label><input id="nm" name="name" maxlength="120" required value="{{ $val('name') }}"></div>
    <div><label for="pos">Jabatan</label><input id="pos" name="position" maxlength="80" value="{{ $val('position') }}" placeholder="mis. Customer Success"></div>
    <div><label for="ws">Status kerja</label><select id="ws" name="work_status">@foreach (\App\Models\Employee::STATUSES as $k => $v)<option value="{{ $k }}" @selected($val('work_status') === $k)>{{ $v }}</option>@endforeach</select></div>
    <div><label for="jd">Tanggal masuk</label><input id="jd" name="join_date" type="date" value="{{ old('join_date', $e?->join_date?->toDateString()) }}"></div>
    <div><label for="ph">No. HP</label><input id="ph" name="phone" maxlength="30" value="{{ $val('phone') }}"></div>
    <div><label for="bn">Bank / e-wallet</label><input id="bn" name="bank_name" maxlength="60" value="{{ $val('bank_name') }}" placeholder="BRI / BCA / DANA"></div>
    <div><label for="ba">No. rekening</label><input id="ba" name="bank_account" maxlength="40" value="{{ $val('bank_account') }}" inputmode="numeric"></div>
    <div><label for="bh">Atas nama</label><input id="bh" name="bank_holder" maxlength="120" value="{{ $val('bank_holder') }}"></div>
  </div>
  <div class="two" style="margin-top:10px;gap:14px">
    <div><b style="color:#16a34a;font-size:13px">Pendapatan bawaan / bulan</b>
      <div class="emp-grid">@foreach ($fields as $k => $l)<div><label for="{{ $k }}">{{ $l }}</label><div class="pp-input"><span>Rp</span><input id="{{ $k }}" name="{{ $k }}" class="rpi" inputmode="numeric" value="{{ $num($k) }}"></div></div>@endforeach</div></div>
    <div><b style="color:#dc2626;font-size:13px">Potongan bawaan / bulan</b>
      <div class="emp-grid">@foreach ($cuts as $k => $l)<div><label for="{{ $k }}">{{ $l }}</label><div class="pp-input"><span>Rp</span><input id="{{ $k }}" name="{{ $k }}" class="rpi" inputmode="numeric" value="{{ $num($k) }}"></div></div>@endforeach</div></div>
  </div>
  <p class="mu" style="font-size:12px;margin:8px 0 0">Nominal BPJS &amp; PPh 21 diisi sesuai perhitungan yang berlaku untuk tiap karyawan — tidak dihitung otomatis.</p>
  <div class="row" style="align-items:center;margin-top:8px">
    <div style="flex:3"><label for="nt">Catatan</label><input id="nt" name="notes" maxlength="200" value="{{ $val('notes') }}"></div>
    <label class="opt" style="flex:none;margin-top:22px"><input type="checkbox" name="active" value="1" @checked(old('active', $e ? $e->active : true))> Aktif (ikut periode gaji baru)</label>
  </div>
  <button class="btn" type="submit" style="margin-top:12px">{{ $e ? 'Simpan perubahan' : 'Tambah karyawan' }}</button>
</form>

<div class="card">
  <b>Daftar karyawan ({{ $employees->count() }})</b>
  <div class="sc" style="margin-top:10px"><table><tr><th>NIK / ID</th><th>Nama</th><th>Jabatan</th><th>Status</th><th class="amt">Pendapatan tetap</th><th class="amt">Potongan tetap</th><th>Rekening</th><th></th></tr>
  @forelse ($employees as $x)
    @php $in = $x->base_salary + $x->allowance_position + $x->allowance_meal + $x->allowance_transport; $out = $x->bpjs_tk + $x->bpjs_kes + $x->pph21 + $x->other_deduction; @endphp
    <tr style="{{ $x->active ? '' : 'opacity:.55' }}"><td>{{ $x->nik }}</td><td><b>{{ $x->name }}</b>@unless ($x->active) <span class="pl mu">Nonaktif</span>@endunless</td><td>{{ $x->position }}</td><td>{{ $x->statusLabel() }}</td>
      <td class="amt">{{ $rp($in) }}</td><td class="amt">{{ $rp($out) }}</td><td class="wrap">{{ trim($x->bank_name.' '.$x->bank_account) ?: '—' }}</td>
      <td style="white-space:nowrap"><a class="btn o" style="padding:4px 10px;font-size:12px" href="{{ route('panel.payroll.employees', ['edit' => $x->id]) }}#ef">Ubah</a>
        <form method="post" action="{{ route('panel.payroll.employees.destroy', $x->id) }}" style="display:inline" data-confirm="Hapus {{ $x->name }}? Riwayat gajinya tetap tersimpan. Untuk karyawan yang keluar, cukup nonaktifkan.">@csrf @method('DELETE')<button class="btn o" style="padding:4px 10px;font-size:12px" type="submit">Hapus</button></form></td></tr>
  @empty <tr><td colspan="8" class="mu">Belum ada karyawan.</td></tr> @endforelse
  </table></div>
</div>
<script @nonce>
document.querySelectorAll('input.rpi').forEach((i) => i.addEventListener('input', () => { const d = i.value.replace(/\D/g, ''); i.value = d ? (+d).toLocaleString('id-ID') : ''; }));
</script>
@endsection
