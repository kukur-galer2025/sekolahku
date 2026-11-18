@extends('portal.layout')
@section('title', 'Langganan')
@section('content')
@php
    $rp = fn ($n) => \App\Models\Invoice::rupiah($n);
    $tz = $school->timezone;
    $pills = ['unpaid' => ['Belum dibayar', 'wr'], 'paid' => ['Lunas', 'ok'], 'void' => ['Dibatalkan', 'mu']];
@endphp
<h2>Langganan</h2>
<p class="sub">Paket per jumlah siswa aktif · {{ $rp($unit) }} per siswa per bulan. Pembayaran online (VA, QRIS, e-wallet) atau transfer bank.</p>
@if ($errors->any())<div class="alert er">{{ $errors->first() }}</div>@endif
@if ($school->isBillingLocked())
  <div class="alert er"><b>Layanan diblokir sementara.</b> {{ $school->suspended_reason }} Guru dan siswa tidak bisa login sampai pembayaran diterima. Data sekolah tetap aman; layanan aktif kembali otomatis setelah lunas.</div>
@endif

<div class="gr">
  <div class="card"><div class="mu">Status</div><div class="big" style="font-size:18px;margin-top:4px"><span class="pl {{ $state[1] }}" style="font-size:14px;padding:3px 10px">{{ $state[0] }}</span></div></div>
  <div class="card"><div class="mu">{{ $school->status === \App\Enums\SchoolStatus::Trial ? 'Trial berakhir' : 'Aktif sampai' }}</div>
    <div class="big" style="font-size:20px">{{ $expires ? $expires->timezone($tz)->translatedFormat('d M Y') : '—' }}</div>
    <div class="mu">{{ $daysLeft ? $daysLeft.' hari lagi' : ($expires ? 'sudah berakhir' : 'tanpa batas') }}</div></div>
  <div class="card"><div class="mu">Siswa aktif / kuota</div><div class="big" style="font-size:20px">{{ number_format($activeStudents, 0, ',', '.') }} <span class="mu" style="font-size:14px">/ {{ $school->student_quota ? number_format($school->student_quota, 0, ',', '.') : '∞' }}</span></div>
    @if ($school->student_quota)<div class="bar"><i style="width:{{ min(100, round($activeStudents / max(1, $school->student_quota) * 100)) }}%"></i></div>@endif</div>
</div>

@foreach ($unpaid as $u)
  <div class="alert wr billbar"><span><b>{{ $u->number }}</b> · {{ $u->description }} · <b>{{ $u->isPartial() ? 'sisa '.$rp($u->remaining()) : $rp($u->total) }}</b> · jatuh tempo {{ $u->due_at->timezone($tz)->format('d/m/Y') }}
    @if ($u->transfer_proof_at) · <i>bukti transfer sedang dicek</i>@endif</span>
    <a class="btn nf" href="{{ route('portal.billing.invoice', $u) }}">{{ $u->transfer_proof_at ? 'Lihat' : 'Bayar' }}</a></div>
@endforeach

<div class="two">
  <form class="card" method="post" action="{{ route('portal.billing.store') }}" id="plan" data-unit="{{ $unit }}" data-min="{{ $minStudents }}" data-step="1" data-tax="{{ (float) config('billing.tax_percent') }}" data-taxlabel="{{ config('billing.tax_label') }}">
    @csrf <input type="hidden" name="type" value="new">
    <b>{{ $school->status === \App\Enums\SchoolStatus::Active && ! $school->isBillingLocked() ? 'Perpanjang langganan' : 'Pilih paket langganan' }}</b>
    <p class="mu" style="margin-top:2px">Biaya dihitung <b>per siswa aktif</b> (minimal {{ $minStudents }} siswa). Perpanjangan ditambahkan setelah masa aktif sekarang berakhir — tidak ada hari yang hilang.</p>
    <label for="students">Jumlah siswa ditagih</label>
    <input id="students" name="students" type="number" value="{{ $suggest }}" readonly aria-describedby="studentsHint">
    <p class="mu" id="studentsHint" style="margin-top:4px">Siswa aktif sekarang: <b>{{ number_format($activeStudents, 0, ',', '.') }}</b>@if ($activeStudents < $minStudents) · ditagih minimal {{ $minStudents }} siswa @endif. Impor/aktifkan data siswa dulu agar jumlahnya sesuai. Siswa baru di tengah periode ditagih otomatis tiap awal bulan (prorata).</p>
    <div class="plans" role="radiogroup" aria-label="Periode langganan">
      @foreach ($periods as $m => $p)
        <label class="plan"><input type="radio" name="months" value="{{ $m }}" data-disc="{{ $p['discount'] }}" @checked(old('months', 12) == $m)>
          @if ($p['discount'])<span class="save">hemat {{ rtrim(rtrim(number_format($p['discount'], 2, ',', '.'), '0'), ',') }}%</span>@endif
          <b>{{ $p['label'] }}</b><span class="pr" data-price="{{ $m }}">{{ $rp($quotes[$m]['total']) }}</span>
          <span class="mu" data-per="{{ $m }}">≈ {{ $rp(round($quotes[$m]['total'] / $m)) }}/bulan</span></label>
      @endforeach
    </div>
    <div class="sum" id="sum" aria-live="polite"></div>
    <button class="btn" type="submit" style="margin-top:14px">Buat tagihan</button>
  </form>

  <div>
    @if ($canUpgrade)
    <form class="card" method="post" action="{{ route('portal.billing.store') }}" id="upg" data-unit="{{ $unit }}" data-days="{{ $upgradeDays }}" data-quota="{{ $school->student_quota }}" data-step="1" data-min="{{ $minStudents }}">
      @csrf <input type="hidden" name="type" value="upgrade">
      <b>Bayar siswa tambahan sekarang (opsional)</b>
      <p class="mu" style="margin-top:2px">Sudah dibayar untuk {{ number_format((int) $school->student_quota, 0, ',', '.') }} siswa. Siswa baru otomatis ditagih tiap awal bulan; bila ingin melunasinya sekarang, bayar selisihnya prorata untuk sisa {{ $upgradeDays }} hari.</p>
      <label for="uq">Jumlah siswa menjadi</label>
      <input id="uq" name="students" type="number" inputmode="numeric" min="{{ $school->student_quota + 1 }}" step="1" value="{{ max($activeStudents, $school->student_quota + 1) }}" required>
      <p class="mu" style="margin-top:6px">Biaya tambahan: <b id="uprice">—</b></p>
      <button class="btn o" type="submit" style="margin-top:10px">Buat tagihan siswa tambahan</button>
    </form>
    @endif
    <div class="card">
      <b>Cara bayar</b>
      <ol class="mu" style="margin:8px 0 0 18px;font-size:13px;line-height:1.7">
        <li>Buat tagihan sesuai jumlah siswa aktif &amp; periode.</li>
        <li>Bayar online (VA bank, QRIS, e-wallet) — aktif <b>otomatis</b> dalam hitungan menit.</li>
        <li>Atau transfer ke rekening Parama Tech, lalu unggah bukti transfer.</li>
        <li>Invoice &amp; kwitansi bisa diunduh/cetak untuk administrasi BOS.</li>
      </ol>
    </div>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <b>Riwayat tagihan</b>
  <div class="sc" style="margin-top:10px">
    <table><tr><th>Nomor</th><th>Tanggal</th><th>Rincian</th><th class="amt">Total</th><th>Status</th><th></th></tr>
    @forelse ($invoices as $i)
      <tr><td><a href="{{ route('portal.billing.invoice', $i) }}">{{ $i->number }}</a></td><td>{{ $i->created_at->timezone($tz)->format('d/m/Y') }}</td>
        <td class="wrap">{{ $i->description }}@if ($i->period_end)<div class="mu">periode {{ $i->period_start->format('d/m/Y') }} – {{ $i->period_end->format('d/m/Y') }}</div>@endif</td>
        <td class="amt">{{ $rp($i->total) }}@if ($i->isPartial())<div class="mu" style="font-size:11px">sisa {{ $rp($i->remaining()) }}</div>@endif</td><td><span class="pl {{ $pills[$i->status][1] }}">{{ $pills[$i->status][0] }}</span></td>
        <td><a href="{{ route('portal.billing.print', $i) }}" target="_blank" rel="noopener">{{ $i->isPaid() ? 'Kwitansi' : 'Invoice' }}</a></td></tr>
    @empty
      <tr><td colspan="6" class="mu">Belum ada tagihan.</td></tr>
    @endforelse
    </table>
  </div>
</div>

<script @nonce>
(() => {
  const rp = n => 'Rp' + Math.round(n).toLocaleString('id-ID');
  const norm = (n, min, step) => Math.max(min, Math.ceil(Math.max(1, n) / step) * step);
  const f = document.getElementById('plan');
  if (f) {
    const unit = +f.dataset.unit, min = +f.dataset.min, step = +f.dataset.step, tax = +f.dataset.tax, sum = document.getElementById('sum');
    const calc = (n, m, disc) => { const g = n * unit * m, d = Math.round(g * disc / 100), t = Math.round((g - d) * tax / 100); return {g, d, t, total: g - d + t}; };
    const draw = () => {
      const n = norm(+f.students.value || 0, min, step);
      f.querySelectorAll('input[name=months]').forEach(r => {
        const m = +r.value, q = calc(n, m, +r.dataset.disc);
        f.querySelector(`[data-price="${m}"]`).textContent = rp(q.total);
        f.querySelector(`[data-per="${m}"]`).textContent = '≈ ' + rp(q.total / m) + '/bulan';
      });
      const r = f.querySelector('input[name=months]:checked'); if (!r) return;
      const m = +r.value, q = calc(n, m, +r.dataset.disc);
      sum.innerHTML = `<span>${n.toLocaleString('id-ID')} siswa × ${rp(unit)} × ${m} bulan</span><span>${rp(q.g)}</span>`
        + (q.d ? `<span>Diskon periode</span><span>−${rp(q.d)}</span>` : '')
        + (q.t ? `<span>${f.dataset.taxlabel} ${tax}%</span><span>${rp(q.t)}</span>` : '')
        + `<span class="t">Total</span><span class="t">${rp(q.total)}</span>`;
    };
    f.addEventListener('input', draw); draw();
  }
  const u = document.getElementById('upg');
  if (u) {
    const draw = () => {
      const n = norm(+u.students.value || 0, +u.dataset.min, +u.dataset.step), extra = n - +u.dataset.quota;
      document.getElementById('uprice').textContent = extra > 0 ? rp(Math.ceil(extra * +u.dataset.unit * +u.dataset.days / 30 / 100) * 100) + ` (+${extra} siswa)` : 'harus lebih besar dari yang sudah dibayar';
    };
    u.addEventListener('input', draw); draw();
  }
})();
</script>
@endsection
