<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Karyawan ParamaEdu.Tech (data pusat, panel super-admin). Data gaji bersifat rahasia. */
class Employee extends Model
{
    public const STATUSES = ['tetap' => 'Tetap', 'kontrak' => 'Kontrak', 'paruh_waktu' => 'Paruh waktu', 'magang' => 'Magang', 'freelance' => 'Freelance'];

    /** Komponen bawaan per bulan yang disalin ke baris gaji saat periode dibuat. */
    public const DEFAULTS = ['base_salary', 'allowance_position', 'allowance_meal', 'allowance_transport', 'bpjs_tk', 'bpjs_kes', 'pph21', 'other_deduction'];

    protected $fillable = ['nik', 'name', 'position', 'work_status', 'join_date', 'phone', 'bank_name', 'bank_account', 'bank_holder',
        'base_salary', 'allowance_position', 'allowance_meal', 'allowance_transport', 'bpjs_tk', 'bpjs_kes', 'pph21', 'other_deduction', 'active', 'notes'];

    protected function casts(): array
    {
        return ['join_date' => 'date', 'active' => 'boolean'] + array_fill_keys(self::DEFAULTS, 'integer');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->work_status] ?? (string) $this->work_status;
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
