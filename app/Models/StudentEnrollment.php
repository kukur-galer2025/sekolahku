<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat kelas siswa per tahun ajaran (satu baris per siswa per tahun ajaran). */
class StudentEnrollment extends Model
{
    use BelongsToSchool;

    protected $fillable = ['student_id', 'academic_year', 'classroom_id', 'classroom_name', 'grade', 'status'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
