<?php

namespace App\Models;

use App\Support\SchoolLevel;
use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Buku Daftar Nilai Sumatif: guru + kelas + mapel + semester. */
class GradeBook extends Model
{
    use BelongsToSchool;

    public const MAX_LM = 10;

    protected $fillable = ['teacher_id', 'classroom_id', 'subject', 'term', 'phase', 'lm_count', 'lm_labels'];

    protected function casts(): array
    {
        return ['lm_count' => 'integer', 'lm_labels' => 'array'];
    }

    /** Fase Kurikulum Merdeka dari tingkat kelas (A–F). */
    public static function guessPhase(?Classroom $c, ?School $school = null): ?string
    {
        if (! $c) {
            return null;
        }
        $g = $c->grade ?: ($school ? SchoolLevel::gradeOf($c->name, $school->schoolLevel()) : null);

        return match ((string) $g) {
            '1', '2', 'I', 'II' => 'A', '3', '4', 'III', 'IV' => 'B', '5', '6', 'V', 'VI' => 'C',
            '7', '8', '9', 'VII', 'VIII', 'IX' => 'D', '10', 'X' => 'E', '11', '12', 'XI', 'XII' => 'F', default => null,
        };
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(GradeEntry::class);
    }
}
