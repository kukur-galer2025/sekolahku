@php
  [$c, $l] = match ($school->status) { \App\Enums\SchoolStatus::Trial => ['wr', 'Trial'], \App\Enums\SchoolStatus::Active => ['ok', 'Aktif'], \App\Enums\SchoolStatus::Suspended => ['er', 'Ditangguhkan'] };
  $exp = $school->expiresAt();
@endphp
<span class="pl {{ $c }}">{{ $l }}</span>@if ($exp && ! $school->isSuspended())<span class="mu" style="display:block">{{ $exp->isPast() ? 'berakhir' : 's.d.' }} {{ $exp->format('d/m/Y') }}</span>@endif @unless ($school->isVerified())<span class="pl wr" style="display:inline-block;margin-top:4px">Belum diverifikasi</span>@endunless
