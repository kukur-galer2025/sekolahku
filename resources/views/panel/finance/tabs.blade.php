<nav class="ltabs" aria-label="Menu keuangan">
  <a href="{{ route('panel.finance') }}" class="{{ request()->routeIs('panel.finance') ? 'on' : '' }}"><x-ti n="chart-bar"/> Ringkasan</a>
  <a href="{{ route('panel.finance.ledger') }}" class="{{ request()->routeIs('panel.finance.ledger*') ? 'on' : '' }}"><x-ti n="receipt"/> Buku kas &amp; laporan</a>
  <a href="{{ route('panel.finance.mutations') }}" class="{{ request()->routeIs('panel.finance.mutations*') ? 'on' : '' }}"><x-ti n="upload"/> Mutasi bank
    @php $pm = \App\Models\BankMutation::where('status', 'pending')->count(); @endphp
    @if ($pm) <span class="pl wr" style="margin-left:4px">{{ $pm }}</span> @endif
  </a>
</nav>
