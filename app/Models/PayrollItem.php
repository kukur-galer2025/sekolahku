<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris gaji satu karyawan dalam satu periode (kolom mengikuti Template Tabel Penggajian Karyawan). */
class PayrollItem extends Model
{
    public const EARNINGS = ['base_salary' => 'Gaji Pokok', 'allowance_position' => 'Tunjangan Jabatan', 'allowance_meal' => 'Tunjangan Makan',
        'allowance_transport' => 'Tunjangan Transport', 'overtime' => 'Lembur', 'bonus' => 'Bonus / Insentif'];

    public const DEDUCTIONS = ['bpjs_tk' => 'BPJS Ketenagakerjaan', 'bpjs_kes' => 'BPJS Kesehatan', 'pph21' => 'PPh 21', 'other_deduction' => 'Potongan Lain'];

    public const PAY_STATUSES = ['belum' => 'Belum Dibayar', 'parsial' => 'DP/Parsial', 'dibayar' => 'Dibayar'];

    public const METHODS = ['transfer' => 'Transfer bank', 'tunai' => 'Tunai', 'lainnya' => 'Lainnya'];

    protected $fillable = ['payroll_run_id', 'employee_id', 'nik', 'name', 'position', 'work_status', 'work_days', 'present_days',
        'base_salary', 'allowance_position', 'allowance_meal', 'allowance_transport', 'overtime', 'bonus', 'gross',
        'bpjs_tk', 'bpjs_kes', 'pph21', 'other_deduction', 'deductions', 'net', 'paid_on', 'method', 'pay_status', 'paid_amount', 'note'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'work_days' => 'integer', 'present_days' => 'integer', 'gross' => 'integer', 'deductions' => 'integer',
            'net' => 'integer', 'paid_amount' => 'integer'] + array_fill_keys(array_keys(self::EARNINGS + self::DEDUCTIONS), 'integer');
    }

    /** Hitung ulang total pendapatan, total potongan, dan gaji bersih (rumus sama dengan template). */
    public function recalc(): static
    {
        $this->gross = array_sum(array_map(fn ($k) => (int) $this->{$k}, array_keys(self::EARNINGS)));
        $this->deductions = array_sum(array_map(fn ($k) => (int) $this->{$k}, array_keys(self::DEDUCTIONS)));
        $this->net = $this->gross - $this->deductions;
        if ($this->pay_status === 'dibayar') {
            $this->paid_amount = max(0, $this->net);
        } elseif ($this->pay_status === 'belum') {
            $this->paid_amount = 0;
        }

        return $this;
    }

    /** Nominal yang sudah dibayarkan. */
    public function paidValue(): int
    {
        return match ($this->pay_status) {
            'dibayar' => max(0, (int) $this->net),
            'parsial' => min((int) $this->paid_amount, max(0, (int) $this->net)),
            default => 0,
        };
    }

    public function statusLabel(): string
    {
        return self::PAY_STATUSES[$this->pay_status] ?? $this->pay_status;
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
