<?php

namespace App\Services\Billing;

use App\Models\School;
use App\Support\Plan;
use Illuminate\Validation\ValidationException;

/**
 * Harga paket per siswa AKTIF:
 *   bruto = siswa aktif (persis, minimal 20) × harga/siswa/bulan × bulan
 *   diskon periode (6 bln 5%, 12 bln ≈ gratis 2 bulan) → subtotal → pajak (opsional) → total
 * Tambah kuota di tengah periode dihitung prorata sisa hari.
 */
class BillingCalculator
{
    /** Harga per siswa per bulan untuk paket ini; harga paket kosong → harga umum. */
    public function unitPrice(?string $plan = null): int
    {
        return Plan::price($plan) ?? max(0, (int) config('billing.price_per_student_month'));
    }

    /** Minimal siswa yang ditagih per sekolah (menutup biaya dasar server). */
    public function minStudents(): int
    {
        return max(1, (int) config('billing.min_billed_students', 20));
    }

    /** Jumlah siswa yang ditagih = siswa aktif persis (tanpa pembulatan), minimal minStudents(). */
    public function normalizeStudents(int $students): int
    {
        return max($this->minStudents(), $students);
    }

    public function periods(): array
    {
        return config('billing.periods', []);
    }

    /** @return array{student_count:int,months:int,unit_price:int,gross:int,discount:int,tax:int,total:int,lines:list<array>} */
    public function quote(int $students, int $months, ?string $plan = null): array
    {
        $periods = $this->periods();
        if (! isset($periods[$months])) {
            throw ValidationException::withMessages(['months' => 'Periode langganan tidak tersedia.']);
        }
        $n = $this->normalizeStudents($students);
        $unit = $this->unitPrice($plan);
        $gross = $n * $unit * $months;
        $discount = (int) round($gross * ((float) $periods[$months]['discount']) / 100);
        $lines = [['label' => 'Langganan MyPresence '.Plan::label($plan)." {$periods[$months]['label']} · ".number_format($n, 0, ',', '.').' siswa × Rp'.number_format($unit, 0, ',', '.').' × '.$months.' bulan', 'amount' => $gross]];
        if ($discount) {
            $lines[] = ['label' => 'Diskon periode '.rtrim(rtrim(number_format((float) $periods[$months]['discount'], 2, ',', '.'), '0'), ',').'%', 'amount' => -$discount];
        }

        return $this->withTax(['student_count' => $n, 'months' => $months, 'unit_price' => $unit, 'gross' => $gross, 'discount' => $discount], $lines);
    }

    /** Tambah kuota sampai $newQuota siswa, prorata sisa masa aktif (hari/30 × harga bulanan). */
    public function upgradeQuote(School $school, int $newQuota): array
    {
        $newQuota = $this->normalizeStudents($newQuota);
        $current = (int) $school->student_quota;
        if ($newQuota <= $current) {
            throw ValidationException::withMessages(['students' => 'Jumlah siswa baru harus lebih besar dari yang sudah dibayar ('.$current.').']);
        }
        if (! $school->active_until?->isFuture()) {
            throw ValidationException::withMessages(['students' => 'Tambah kuota hanya untuk langganan aktif. Pilih paket baru.']);
        }
        $days = max(1, (int) ceil(now()->floatDiffInDays($school->active_until)));
        $extra = $newQuota - $current;
        $unit = $this->unitPrice($school->plan);
        $gross = (int) (ceil($extra * $unit * $days / 30 / 100) * 100); // dibulatkan ke atas ratusan rupiah
        $lines = [['label' => 'Tambahan '.number_format($extra, 0, ',', '.')." siswa aktif (dari $current menjadi $newQuota) · prorata $days hari s.d. ".$school->active_until->format('d/m/Y'), 'amount' => $gross]];

        return $this->withTax(['student_count' => $newQuota, 'months' => 0, 'unit_price' => $unit, 'gross' => $gross, 'discount' => 0], $lines);
    }

    private function withTax(array $q, array $lines): array
    {
        $subtotal = $q['gross'] - $q['discount'];
        $rate = (float) config('billing.tax_percent', 0);
        $tax = (int) round($subtotal * $rate / 100);
        if ($tax) {
            $lines[] = ['label' => config('billing.tax_label').' '.rtrim(rtrim(number_format($rate, 2, ',', '.'), '0'), ',').'%', 'amount' => $tax];
        }

        return $q + ['tax' => $tax, 'total' => $subtotal + $tax, 'lines' => $lines];
    }
}
