@php
    [$cls, $label] = match ($i->status) {
        'preview' => ['mu', 'Pratinjau'], 'queued' => ['wr', 'Antre'], 'processing' => ['wr', 'Diproses'],
        'done' => ['ok', 'Selesai'], 'failed' => ['er', 'Gagal'], default => ['mu', $i->status],
    };
@endphp
<span class="pl {{ $cls }}">{{ $label }}</span>
