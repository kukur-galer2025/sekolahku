@if ($p->hasPages())
<div class="pager">
  <span class="mu">{{ $p->firstItem() }}–{{ $p->lastItem() }} dari {{ $p->total() }}</span>
  @if ($p->onFirstPage())<span class="btn o" aria-disabled="true" style="opacity:.5">‹ Sebelumnya</span>@else<a class="btn o" href="{{ $p->previousPageUrl() }}">‹ Sebelumnya</a>@endif
  @if ($p->hasMorePages())<a class="btn o" href="{{ $p->nextPageUrl() }}">Berikutnya ›</a>@else<span class="btn o" aria-disabled="true" style="opacity:.5">Berikutnya ›</span>@endif
</div>
@endif
