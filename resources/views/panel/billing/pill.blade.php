@php
    $x = match (true) {
        $i->status === 'paid' => ['Lunas', 'ok'],
        $i->status === 'void' => ['Batal', 'mu'],
        $i->transfer_proof_at !== null => ['Cek bukti', 'er'],
        $i->due_at->isPast() => [(int) $i->paid_amount > 0 ? 'DP · lewat tempo' : 'Lewat tempo', 'er'],
        (int) $i->paid_amount > 0 => ['DP/Parsial', 'in'],
        default => ['Belum dibayar', 'wr'],
    };
@endphp
<span class="pl {{ $x[1] }}">{{ $x[0] }}</span>
