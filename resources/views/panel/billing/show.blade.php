@extends('panel.layout')
@section('title', 'Tagihan '.$invoice->number)
@section('content')
@php $rp = fn ($n) => \App\Models\Invoice::rupiah($n); $tz = 'Asia/Jakarta'; @endphp
<p class="mu"><a href="{{ route('panel.billing') }}">‹ Semua tagihan</a> · <a href="{{ route('panel.schools.show', $school->id) }}">{{ $school->name }}</a></p>
<h2>{{ $invoice->number }} @include('panel.billing.pill', ['i' => $invoice])</h2>
<p class="sub">{{ $invoice->typeLabel() }} · {{ $school->name }} ({{ $school->subdomain }}) · dibuat {{ $invoice->created_at->timezone($tz)->format('d/m/Y H:i') }} WIB oleh {{ $invoice->creator?->name ?? 'Sistem' }}</p>

<div class="two">
  <div class="card">
    <b>Rincian</b>
    <div class="sum" style="margin-top:10px">
      @foreach ($invoice->lines ?? [] as $l)<span>{{ $l['label'] }}</span><span>{{ $l['amount'] < 0 ? '−'.$rp(-$l['amount']) : $rp($l['amount']) }}</span>@endforeach
      <span class="t">Total tagihan</span><span class="t">{{ $rp($invoice->total) }}</span>
      @if ($invoice->payments->isNotEmpty())
        @php $bd = $invoice->paymentBreakdown(); @endphp
        <span>DP / uang muka</span><span>−{{ $rp($bd['dp']) }}</span>
        <span>Pembayaran tambahan</span><span>−{{ $rp($bd['other']) }}</span>
        <span class="t">Sisa tagihan</span><span class="t" style="color:{{ $invoice->remaining() ? 'var(--er)' : 'var(--ok)' }}">{{ $rp($invoice->remaining()) }}</span>
      @endif
    </div>
    <div class="kv" style="margin-top:16px">
      <span class="mu">Kuota / periode</span><span>{{ number_format($invoice->student_count, 0, ',', '.') }} siswa · {{ $invoice->months ? $invoice->months.' bulan' : '—' }}</span>
      <span class="mu">Jatuh tempo</span><span>{{ $invoice->due_at->timezone($tz)->format('d/m/Y') }}</span>
      @if ($invoice->gateway)<span class="mu">Gateway</span><span>{{ $invoice->gateway }} · {{ $invoice->gateway_ref }} ({{ $invoice->checkout_attempts }}× checkout)</span>@endif
      @if ($invoice->isPaid())
        <span class="mu">Dibayar</span><span>{{ $invoice->paid_at->timezone($tz)->format('d/m/Y H:i') }} WIB · {{ $invoice->payment_method }} · {{ $invoice->payment_ref }}</span>
        @if ($invoice->period_end)<span class="mu">Periode</span><span>{{ $invoice->period_start->format('d/m/Y') }} – {{ $invoice->period_end->format('d/m/Y') }}</span>@endif
      @endif
      @if ($invoice->notes)<span class="mu">Catatan</span><span>{{ $invoice->notes }}</span>@endif
    </div>
    <p style="margin-top:16px"><a class="btn o" href="{{ route('panel.billing.print', $invoice->id) }}" target="_blank" rel="noopener"><x-ti n="printer"/> {{ $invoice->isPaid() ? 'Kwitansi' : 'Invoice' }}</a></p>
  </div>

  <div>
    @if ($invoice->isPayable())
      @if ($invoice->transfer_proof_at)
        <div class="card"><b>Bukti transfer</b><p class="mu" style="margin-top:2px">Diunggah {{ $invoice->transfer_proof_at->timezone($tz)->format('d/m/Y H:i') }} WIB · {{ $invoice->notes }}</p>
          @if ($proofUrl)<p style="margin-top:8px"><a class="btn o" href="{{ $proofUrl }}" target="_blank" rel="noopener">Buka bukti transfer</a></p>@endif
          <p class="mu" style="margin-top:8px">Cocokkan dengan mutasi rekening: nominal tepat {{ $rp($invoice->remaining()) }}.</p></div>
      @endif
      <form class="card" method="post" action="{{ route('panel.billing.payments.store', $invoice->id) }}">@csrf
        <b>Catat DP / pembayaran</b>
        <p class="mu" style="margin-top:2px">Sisa tagihan <b>{{ $rp($invoice->remaining()) }}</b>. Bila nominal = sisa, tagihan otomatis lunas.</p>
        <div class="row">
          <div><label>Jenis</label><select name="kind">@if (! $invoice->paid_amount)<option value="dp">DP / Uang muka</option>@endif<option value="tambahan">Pembayaran tambahan</option></select></div>
          <div><label>Nominal (Rp)</label><input name="amount" type="number" min="1" max="{{ $invoice->remaining() }}" step="1" required inputmode="numeric" value="{{ old('amount') }}" placeholder="{{ $invoice->paid_amount ? $invoice->remaining() : round($invoice->total / 2) }}"></div>
        </div>
        <div class="row">
          <div><label>Tanggal bayar</label><input name="paid_on" type="date" required value="{{ old('paid_on', now($tz)->toDateString()) }}" max="{{ now($tz)->toDateString() }}"></div>
          <div><label>Metode</label><select name="method"><option value="transfer_manual">Transfer bank</option><option value="tunai">Tunai</option><option value="lainnya">Lainnya</option></select></div>
        </div>
        <label>Referensi (no. mutasi / bank / e-wallet)</label><input name="ref" maxlength="120">
        <label>Catatan (opsional)</label><input name="note" maxlength="200">
        @if (! $invoice->activated_at && in_array($invoice->type, ['new', 'renewal', 'upgrade'], true))
          <label class="opt" style="margin-top:10px"><input type="checkbox" name="activate" value="1"> Aktifkan layanan sekarang (masa aktif &amp; kuota langsung berlaku walau belum lunas)</label>
        @endif
        <button class="btn" type="submit" style="margin-top:12px"><x-ti n="receipt"/> Simpan pembayaran</button>
      </form>
      <form class="card" method="post" action="{{ route('panel.billing.paid', $invoice->id) }}">@csrf
        <b>{{ $invoice->paid_amount ? 'Lunasi sisa sekaligus' : 'Konfirmasi lunas' }}</b>
        <p class="mu" style="margin-top:2px">Masa aktif &amp; kuota sekolah langsung diperbarui; blokir karena tagihan dibuka otomatis.</p>
        <label>Metode</label><select name="method"><option value="transfer_manual">Transfer bank</option><option value="tunai">Tunai</option><option value="lainnya">Lainnya</option></select>
        <label>Referensi (no. mutasi / keterangan)</label><input name="ref" maxlength="120" required>
        <button class="btn" type="submit" style="margin-top:12px">Tandai lunas</button>
      </form>
      @if ($invoice->gateway_ref && $online)
        <form class="card" method="post" action="{{ route('panel.billing.check', $invoice->id) }}">@csrf<b>Cek ke gateway</b><p class="mu" style="margin-top:2px">Bila webhook terlambat.</p><button class="btn o" type="submit" style="margin-top:8px">Cek status</button></form>
      @endif
      <form class="card danger" method="post" action="{{ route('panel.billing.void', $invoice->id) }}">@csrf
        <b>Batalkan tagihan</b><label>Alasan</label><input name="reason" maxlength="200" required>
        <button class="btn er" type="submit" style="margin-top:10px">Batalkan</button></form>
    @endif
  </div>
</div>

<div class="card" style="margin-top:14px" id="pembayaran"><b>Riwayat pembayaran (DP &amp; cicilan)</b>
  <div class="sc" style="margin-top:10px"><table><tr><th>Tanggal</th><th>Jenis</th><th class="amt">Nominal</th><th>Metode</th><th>Referensi / catatan</th><th>Dicatat</th>@if ($invoice->isPayable())<th></th>@endif</tr>
  @forelse ($invoice->payments as $p)
    <tr><td>{{ $p->paid_on->format('d/m/Y') }}</td><td><span class="pl {{ $p->kind === 'dp' ? 'in' : ($p->kind === 'pelunasan' ? 'ok' : 'wr') }}">{{ $p->kindLabel() }}</span></td>
      <td class="amt">{{ $rp($p->amount) }}</td><td>{{ $p->methodLabel() }}</td><td class="wrap">{{ $p->ref }}@if ($p->note)<div class="mu">{{ $p->note }}</div>@endif</td>
      <td class="mu">{{ $p->creator?->name ?? 'Sistem' }}</td>
      @if ($invoice->isPayable())<td><form method="post" action="{{ route('panel.billing.payments.destroy', [$invoice->id, $p->id]) }}" data-confirm="Hapus pembayaran {{ $rp($p->amount) }}? Pakai ini hanya untuk salah catat.">@csrf @method('DELETE')<button class="btn o" type="submit" style="padding:4px 10px;font-size:12px">Hapus</button></form></td>@endif</tr>
  @empty <tr><td colspan="7" class="mu">Belum ada pembayaran. Catat DP lewat formulir “Catat DP / pembayaran”.</td></tr> @endforelse
  @if ($invoice->payments->isNotEmpty())<tr><td colspan="2"><b>Total diterima</b></td><td class="amt"><b>{{ $rp($invoice->payments->sum('amount')) }}</b></td><td colspan="4"></td></tr>@endif
  </table></div>
  @if ($invoice->activated_at && $invoice->isPayable())<p class="mu" style="margin-top:8px">Layanan sudah diaktifkan saat DP ({{ $invoice->activated_at->timezone($tz)->format('d/m/Y') }})@if ($invoice->period_end), aktif s.d. {{ $invoice->period_end->format('d/m/Y') }}@endif. Saat lunas, masa aktif tidak ditambah lagi.</p>@endif
</div>

<div class="card" style="margin-top:14px"><b>Notifikasi gateway untuk tagihan ini</b>
  <div class="sc" style="margin-top:10px"><table><tr><th>Waktu</th><th>Status</th><th>Hasil</th><th>Keterangan</th><th>IP</th></tr>
  @forelse ($events as $e)
    <tr><td>{{ $e->created_at->timezone($tz)->format('d/m H:i:s') }}</td><td>{{ $e->status }}</td><td>{{ $e->result }}</td><td class="wrap">{{ $e->message }}</td><td>{{ $e->ip }}</td></tr>
  @empty <tr><td colspan="5" class="mu">Belum ada.</td></tr> @endforelse
  </table></div></div>
@endsection
