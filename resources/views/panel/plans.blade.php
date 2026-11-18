@extends('panel.layout')
@section('title', 'Paket & harga')
@section('content')
@php $rp = fn ($n) => \App\Models\Invoice::rupiah($n); @endphp
<div class="sd">
<div class="sd-head">
  <div class="sd-title"><h2>Paket & harga</h2>
    <p class="sub">Harga per siswa per bulan untuk tiap paket. Berlaku untuk tagihan baru; tagihan yang sudah dibuat tidak berubah.</p></div>
</div>

<form method="post" action="{{ route('panel.plans.update') }}">
  @csrf @method('PUT')
  <div class="pp-grid">
    @foreach ($plans as $key => $p)
      @php
        $price = \App\Support\Plan::price($key);
        $h = $history[$key] ?? null;
        $max = \App\Support\Plan::limit($key, 'max_students');
        $locked = \App\Support\Plan::locked($key);
      @endphp
      <div class="card pp-card">
        <div class="pp-top"><b class="pp-name">{{ $p['label'] }}</b><span class="pl mu">{{ $counts[$key] ?? 0 }} sekolah</span></div>
        <p class="mu" style="margin:4px 0 0">{{ $p['summary'] }}</p>

        <label for="price_{{ $key }}">Harga per siswa per bulan</label>
        <div class="pp-input"><span>Rp</span><input id="price_{{ $key }}" name="price[{{ $key }}]" inputmode="numeric" autocomplete="off"
          value="{{ old("price.$key", $price !== null ? number_format($price, 0, ',', '.') : '') }}" placeholder="belum ditetapkan" data-rp></div>
        @error("price.$key")<div class="err">{{ $message }}</div>@enderror

        <div class="pp-periods">
          @foreach ($periods as $m => $per)
            @php $unit = $price ?? $fallback; $v = (int) round($unit * $m * (1 - $per['discount'] / 100)); @endphp
            <div><span class="mu">{{ $m }} bulan{{ $per['discount'] ? ' · −'.rtrim(rtrim(number_format((float) $per['discount'], 2, ',', '.'), '0'), ',').'%' : '' }}</span><b data-period="{{ $m }}" data-disc="{{ $per['discount'] }}">{{ $rp($v) }}</b></div>
          @endforeach
          <p class="mu" style="margin:0;grid-column:1/-1">Per siswa, sudah termasuk diskon periode.@if ($price === null) <b>Harga belum ditetapkan</b> — sementara memakai harga umum {{ $rp($fallback) }}.@endif</p>
        </div>

        @php $menus = \App\Support\Plan::menus($key); $nMenu = collect($menus)->flatten(1)->count(); @endphp
        <details class="pp-menus"><summary><b>{{ $nMenu }} menu termasuk</b> <span class="mu">lihat daftar</span></summary>
          @foreach ($menus as $g => $items)
            <div class="pp-mg">{{ $g }}</div>
            <ul>@foreach ($items as $i)<li>{{ $i['label'] }}</li>@endforeach</ul>
          @endforeach
        </details>

        <div class="pp-meta mu">
          Maks. {{ $max ? number_format($max, 0, ',', '.').' siswa' : 'siswa tanpa batas' }} · {{ $locked ? count($locked).' fitur terkunci' : 'semua fitur' }}
          @if ($h && $h->updated_by_name)<br>Diubah {{ \Illuminate\Support\Carbon::parse($h->updated_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB oleh {{ $h->updated_by_name }}@endif
        </div>
      </div>
    @endforeach
  </div>
  <div class="card pp-save">
    <span class="mu">Kosongkan kolom harga untuk kembali ke "belum ditetapkan". Setiap perubahan tercatat di Log aktivitas.</span>
    <button class="btn" type="submit" data-confirm="Simpan harga paket? Berlaku untuk tagihan baru.">Simpan harga</button>
  </div>
</form>

<div class="card" style="margin-top:18px;display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
  <div><b>Template menu &amp; istilah</b><p class="mu" style="margin:2px 0 0">Nama menu per portal untuk jenis sekolah tertentu (Pesantren, SMP, dll.) — diisi manual, diterapkan per sekolah. {{ \App\Models\MenuTemplate::count() }} template tersimpan.</p></div>
  <a class="btn o nf" href="{{ route('panel.menu.templates') }}">Kelola template</a>
</div>

{{-- ── Menu & fitur per paket: daftar menu MyPresence sebagai pilihan isi paket ── --}}
<form method="post" action="{{ route('panel.plans.features') }}" class="card" style="margin-top:18px">
  @csrf @method('PUT')
  <div class="pp-fh"><div><h3 style="margin:0">Menu &amp; fitur per paket</h3>
    <p class="mu" style="margin:4px 0 0">Centang menu yang termasuk di tiap paket. Menu inti selalu tersedia. Perubahan langsung berlaku: menu yang tidak termasuk paket tersembunyi di portal guru & siswa, tampil terkunci di portal admin, dan aksesnya ditolak server.</p></div>
    <div class="pp-quick mu">Cepat:
      @foreach ($plans as $key => $p)<button type="button" class="linkbtn" data-all="{{ $key }}">semua {{ $p['label'] }}</button>@endforeach
    </div></div>
  <div class="sc" style="margin-top:12px"><table class="pp-mx">
    <thead><tr><th style="text-align:left">Menu</th><th style="text-align:left">Portal</th>@foreach ($plans as $key => $p)<th>{{ $p['label'] }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($catalog as $group => $items)
      <tr class="pp-grp"><td colspan="{{ 2 + count($plans) }}">{{ $group }}</td></tr>
      @foreach ($items as $i)
        <tr>
          <td><b>{{ $i['label'] }}</b><div class="mu" style="font-size:12px">{{ $i['desc'] }}</div></td>
          <td class="mu" style="font-size:12px;white-space:nowrap">{{ $i['roles'] }}</td>
          @foreach ($plans as $key => $p)
            <td style="text-align:center">
              @if (isset($i['lock']))
                <input type="checkbox" name="features[{{ $i['lock'] }}][]" value="{{ $key }}" data-plan="{{ $key }}" aria-label="{{ $i['label'] }} di {{ $p['label'] }}"
                  @checked(in_array($key, (array) ($featureMap[$i['lock']] ?? []), true))>
              @else
                <span class="pp-core" title="Menu inti, selalu tersedia">✓</span>
              @endif
            </td>
          @endforeach
        </tr>
      @endforeach
    @endforeach
    </tbody>
    <tfoot><tr><td colspan="2"><b>Jumlah menu</b></td>@foreach ($plans as $key => $p)<td style="text-align:center"><b data-count="{{ $key }}"></b></td>@endforeach</tr></tfoot>
  </table></div>
  <div class="pp-save" style="margin-top:12px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
    <span class="mu">Sekolah yang sedang memakai fitur lalu kehilangan akses: datanya tetap tersimpan dan muncul lagi bila paket memuatnya kembali.</span>
    <button class="btn" type="submit" data-confirm="Simpan menu per paket? Langsung berlaku untuk semua sekolah.">Simpan menu paket</button>
  </div>
</form>
</div>

<script @nonce>
// Hitung jumlah menu per paket (inti + yang dicentang) dan tombol "semua".
(() => {
  const core = document.querySelectorAll('.pp-mx tbody tr:not(.pp-grp) .pp-core').length / {{ max(1, count($plans)) }};
  const count = () => document.querySelectorAll('[data-count]').forEach((b) => {
    b.textContent = core + document.querySelectorAll(`.pp-mx input[data-plan="${b.dataset.count}"]:checked`).length;
  });
  document.querySelectorAll('.pp-mx input[type=checkbox]').forEach((c) => c.addEventListener('change', count));
  document.querySelectorAll('[data-all]').forEach((b) => b.addEventListener('click', () => {
    const boxes = [...document.querySelectorAll(`.pp-mx input[data-plan="${b.dataset.all}"]`)];
    const on = boxes.some((x) => !x.checked);
    boxes.forEach((x) => (x.checked = on));
    count();
  }));
  count();
})();
</script>

<script @nonce>
// Format ribuan saat mengetik + pratinjau harga per periode (perhitungan final tetap di server).
document.querySelectorAll('[data-rp]').forEach((inp) => {
  const card = inp.closest('.pp-card'), fallback = {{ $fallback }};
  const fmt = (n) => 'Rp' + n.toLocaleString('id-ID');
  inp.addEventListener('input', () => {
    const d = inp.value.replace(/\D/g, '');
    inp.value = d ? Number(d).toLocaleString('id-ID') : '';
    const unit = d ? Number(d) : fallback;
    card.querySelectorAll('[data-period]').forEach((b) => {
      const m = Number(b.dataset.period), disc = Number(b.dataset.disc);
      b.textContent = fmt(Math.round(unit * m * (1 - disc / 100)));
    });
  });
});
</script>
@endsection
