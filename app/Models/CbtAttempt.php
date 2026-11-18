<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sesi pengerjaan satu siswa. Batas waktu dihitung server (deadline_at). */
class CbtAttempt extends Model
{
    use BelongsToSchool;

    protected $fillable = ['cbt_exam_id', 'student_id', 'classroom_id', 'status', 'started_at', 'deadline_at', 'finished_at', 'finish_reason',
        'question_order', 'option_orders', 'pg_score', 'pg_max', 'essay_score', 'essay_max', 'final_score', 'correct', 'wrong', 'blank', 'answered',
        'exits', 'locked_at', 'restarts', 'last_seen_at', 'ip'];

    protected function casts(): array
    {
        return ['locked_at' => 'datetime', 'restarts' => 'integer', 'started_at' => 'datetime', 'deadline_at' => 'datetime', 'finished_at' => 'datetime', 'last_seen_at' => 'datetime',
            'question_order' => 'array', 'option_orders' => 'array', 'pg_score' => 'float', 'pg_max' => 'float', 'essay_score' => 'float',
            'essay_max' => 'float', 'final_score' => 'float', 'correct' => 'integer', 'wrong' => 'integer', 'blank' => 'integer',
            'answered' => 'integer', 'exits' => 'integer'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(CbtExam::class, 'cbt_exam_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(CbtAnswer::class);
    }

    /** Nilai PG dalam skala 0–100 (ditampilkan langsung ke siswa). */
    public function pgPercent(): ?float
    {
        return $this->pg_max > 0 ? round($this->pg_score / $this->pg_max * 100, 1) : null;
    }

    public function essayPending(): bool
    {
        return $this->status === 'selesai' && $this->essay_max > 0 && $this->final_score === null;
    }
}
