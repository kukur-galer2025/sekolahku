@extends('portal.layout')
@section('title', 'Log akses')
@section('content')
<h2>Log akses</h2>
<p class="sub">Catatan tindakan penting di sekolahmu, termasuk setiap kali tim Parama Tech membuka portal sekolah untuk membantu.</p>
<div class="card">
  <form class="row" method="get">
    <div><label for="q">Cari</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Nama atau keterangan"></div>
    <div><label for="from">Dari</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div>
    <div><label for="to">Sampai</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div>
    <button class="btn nf" type="submit">Tampilkan</button>
    <a class="btn o nf" href="{{ route('portal.audit.export', request()->only(['q', 'from', 'to'])) }}">Unduh CSV</a>
  </form>
  <details class="logbox" data-key="portal-audit" @if (request()->hasAny(['action', 'from', 'to', 'q', 'page', 'school'])) open @endif>
  <summary><span class="lb-t">Daftar catatan</span><span class="mu">{{ number_format($logs->total(), 0, ',', '.') }} catatan</span><span class="lb-b"><span class="lb-show">Tampilkan</span><span class="lb-hide">Sembunyikan</span></span></summary>
  <div class="sc" style="margin-top:14px"><table><tr><th>Waktu</th><th>Oleh</th><th>Keterangan</th><th>IP</th></tr>
  @forelse ($logs as $l)
    <tr><td>{{ $l->created_at->timezone(app(\App\Tenancy\TenantContext::class)->get()->timezone)->format('d/m/Y H:i') }}</td>
      <td>{!! str_contains((string) $l->actor_name, 'Parama Tech') ? '<span class="pl wr">'.e($l->actor_name).'</span>' : e($l->actor_name) !!}</td>
      <td class="wrap">{{ $l->description }}</td><td class="mu">{{ $l->ip }}</td></tr>
  @empty <tr><td colspan="4" class="mu">Belum ada catatan.</td></tr> @endforelse
</table></div>@include('portal.pager', ['p' => $logs])</details></div>
@endsection
