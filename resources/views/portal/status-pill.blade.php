@php $cls = ['H' => 'ok', 'S' => 'wr', 'I' => 'wr', 'A' => 'er'][$s->value] ?? 'mu'; @endphp
<span class="pl {{ $cls }}">{{ $s->label() }}</span>
