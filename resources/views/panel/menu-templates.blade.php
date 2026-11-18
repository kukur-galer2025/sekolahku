@extends('panel.layout')
@section('title', 'Template menu & istilah')
@section('content')
<p class="mu"><a href="{{ route('panel.plans') }}">‹ Paket &amp; harga</a></p>
<h2>Template menu &amp; istilah</h2>
<p class="sub">Buat sendiri kumpulan nama menu per portal (mis. "Pesantren", "SMP", "Sekolah Adiwiyata"), lalu terapkan cepat di halaman detail sekolah. Mengubah template tidak mengubah sekolah yang sudah memakainya.</p>

<div class="two" style="align-items:start">
  <div class="card">
    <div class="row" style="justify-content:space-between;align-items:center"><b>Template ({{ $templates->count() }})</b>
      @if ($edit) <a class="btn o nf" href="{{ route('panel.menu.templates') }}">+ Template baru</a> @endif</div>
    <div class="sc" style="margin-top:8px"><table><tr><th>Nama</th><th>Isi</th><th></th></tr>
      @forelse ($templates as $t)
        <tr style="{{ $edit?->id === $t->id ? 'background:var(--sf,#eef3fa)' : '' }}"><td><b>{{ $t->name }}</b> @if ($t->description) <div class="mu" style="font-size:12px">{{ $t->description }}</div> @endif</td>
          <td class="mu">{{ $t->changeCount() }} pengaturan</td>
          <td style="white-space:nowrap"><a class="btn o" style="padding:4px 10px;font-size:12px" href="{{ route('panel.menu.templates', ['edit' => $t->id]) }}">Ubah</a>
            <form method="post" action="{{ route('panel.menu.templates.destroy', $t->id) }}" style="display:inline" data-confirm="Hapus template {{ $t->name }}?">@csrf @method('DELETE')<button class="btn o" type="submit" style="padding:4px 10px;font-size:12px">Hapus</button></form></td></tr>
      @empty <tr><td colspan="3" class="mu">Belum ada template. Isi formulir di samping untuk membuat yang pertama.</td></tr> @endforelse
    </table></div>
  </div>
  <div class="card">
    <b>Cara pakai</b>
    <ol class="mu" style="margin:6px 0 0 18px;padding:0;font-size:13px;line-height:1.7">
      <li>Beri nama template, isi nama menu yang ingin diganti per portal, dan matikan menu yang biasanya tidak dipakai.</li>
      <li>Buka <b>Sekolah → detail sekolah → Menu &amp; istilah</b>, pilih template di "Isi cepat", periksa, lalu simpan.</li>
      <li>Sekolah tetap bisa disesuaikan sendiri setelah template diterapkan.</li>
    </ol>
    <p class="mu" style="font-size:12px;margin-top:10px">{{ $usage }} sekolah sudah punya pengaturan menu sendiri.</p>
  </div>
</div>

<form class="card" id="tpl" method="post" action="{{ $edit ? route('panel.menu.templates.update', $edit->id) : route('panel.menu.templates.store') }}" style="margin-top:14px">@csrf @if ($edit) @method('PUT') @endif
  <b>{{ $edit ? 'Ubah template: '.$edit->name : 'Template baru' }}</b>
  <div class="row" style="margin-top:6px">
    <div><label for="tn">Nama template</label><input id="tn" name="name" required maxlength="60" value="{{ old('name', $edit?->name) }}" placeholder="mis. Pesantren / Boarding School"></div>
    <div style="flex:2"><label for="td">Keterangan (opsional)</label><input id="td" name="description" maxlength="200" value="{{ old('description', $edit?->description) }}" placeholder="untuk sekolah berasrama: wali kelas = musyrif, kas = kas asrama"></div>
  </div>
  <div style="margin-top:12px">
    @include('panel.menu-editor', ['catalog' => $catalog, 'value' => $value, 'locked' => [], 'templates' => collect(), 'schoolName' => 'Contoh Sekolah'])
  </div>
  <button class="btn" type="submit" style="margin-top:12px">{{ $edit ? 'Simpan perubahan template' : 'Simpan template' }}</button>
</form>
@endsection
