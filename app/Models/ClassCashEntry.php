<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu transaksi Kas Kelas (pemasukan / pengeluaran). */
class ClassCashEntry extends Model
{
    use BelongsToSchool;

    public const IN = ['iuran' => 'Iuran', 'sumbangan' => 'Sumbangan', 'lainnya' => 'Pemasukan lain'];

    public const OUT = ['kegiatan' => 'Kegiatan kelas', 'perlengkapan' => 'Perlengkapan', 'kebersihan' => 'Kebersihan', 'sosial' => 'Sosial / jenguk', 'lainnya' => 'Pengeluaran lain'];

    protected $fillable = ['classroom_id', 'type', 'category', 'amount', 'date', 'description', 'student_id', 'proof_path', 'recorded_by', 'recorded_name'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'date' => 'date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
