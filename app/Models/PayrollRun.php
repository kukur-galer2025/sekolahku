<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu periode penggajian (bulan & tahun). */
class PayrollRun extends Model
{
    public const MONTHS = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    protected $fillable = ['year', 'month', 'pay_date', 'work_days', 'company', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'work_days' => 'integer', 'pay_date' => 'date'];
    }

    public function label(): string
    {
        return (self::MONTHS[$this->month] ?? $this->month).' '.$this->year;
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class)->orderBy('name')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Rekap seperti sheet "Rekap" pada template. */
    public function summary(?iterable $items = null): array
    {
        $items = collect($items ?? $this->items()->get());

        return [
            'count' => $items->count(),
            'gross' => (int) $items->sum('gross'),
            'deductions' => (int) $items->sum('deductions'),
            'net' => (int) $items->sum('net'),
            'paid' => (int) $items->sum(fn ($i) => $i->paidValue()),
            'unpaid' => (int) $items->sum(fn ($i) => max(0, $i->net - $i->paidValue())),
            'n_paid' => $items->where('pay_status', 'dibayar')->count(),
            'n_partial' => $items->where('pay_status', 'parsial')->count(),
        ];
    }
}
