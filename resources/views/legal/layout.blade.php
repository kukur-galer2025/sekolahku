@php $c = config('legal.company'); $doc = config("legal.documents.$key"); @endphp
@extends('central.layout')
@section('title', $doc['title'])
@section('content')
<article class="card legal" style="max-width:820px;margin:0 auto">
  @if (config('legal.draft'))<div class="alert wr"><b>Draf — belum berlaku.</b> Dokumen ini masih ditinjau konsultan hukum dan dapat berubah.</div>@endif
  <p class="mu">Versi {{ $doc['version'] }} · berlaku sejak {{ \Illuminate\Support\Carbon::parse(config('legal.effective_date'))->locale('id')->translatedFormat('j F Y') }}</p>
  <h1 style="font-size:clamp(22px,4vw,30px);margin:6px 0 14px;text-wrap:balance">{{ $doc['title'] }}</h1>
  @yield('doc')
  <hr style="border:0;border-top:1px solid var(--bd);margin:22px 0">
  <p class="mu">{{ $c['name'] }} · {{ $c['address'] }} · <span style="user-select:all">{{ $c['email'] }}</span></p>
  <p class="mu" style="margin-top:6px">Dokumen terkait:
    @foreach (config('legal.documents') as $k => $d)@if ($k !== $key)<a href="{{ route('legal.show', $d['slug']) }}">{{ $d['title'] }}</a>@if (! $loop->last) · @endif @endif @endforeach</p>
</article>
<style>.legal h2{font-size:17px;margin:22px 0 8px}.legal p,.legal li{font-size:14px;line-height:1.65;max-width:72ch}.legal ol,.legal ul{padding-left:22px;margin:6px 0}.legal li{margin:4px 0}.legal table{min-width:0}</style>
@endsection
