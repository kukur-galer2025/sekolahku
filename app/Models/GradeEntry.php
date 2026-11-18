<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nilai sumatif satu siswa dalam satu buku nilai. */
class GradeEntry extends Model
{
    use BelongsToSchool;

    protected $fillable = ['grade_book_id', 'student_id', 'lm', 'sts_nontes', 'sts_tes', 'sas_nontes', 'sas_tes', 'updated_by'];

    protected function casts(): array
    {
        return ['lm' => 'array', 'sts_nontes' => 'float', 'sts_tes' => 'float', 'sas_nontes' => 'float', 'sas_tes' => 'float'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(GradeBook::class, 'grade_book_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
