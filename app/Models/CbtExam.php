<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ujian CBT. PTS/PAS dibuat admin; Ulangan Harian (uh) dibuat guru. Satu ujian = satu mapel; token berbeda di tiap kelas peserta. */
class CbtExam extends Model
{
    /** Sanksi keluar aplikasi saat ujian. */
    public const EXIT_ACTIONS = ['catat' => 'Hanya dicatat', 'kunci' => 'Ujian dikunci, dibuka pengawas', 'ulang' => 'Jawaban dihapus, ulang dari soal 1'];

    /** Jenis ujian: tab "PTS / PAS" (pts, pas, ujian) dan tab "Ulangan Harian" (uh). */
    public const KINDS = ['pts' => 'PTS', 'pas' => 'PAS', 'ujian' => 'Ujian', 'uh' => 'Ulangan Harian'];

    use BelongsToSchool;

    protected $fillable = ['title', 'kind', 'teacher_id', 'grade_lm', 'subject', 'grade', 'code', 'starts_at', 'ends_at', 'duration_minutes', 'shuffle_questions', 'shuffle_options',
        'max_exits', 'exit_action', 'exit_allow', 'status', 'draft', 'published_at', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'published_at' => 'datetime', 'draft' => 'array',
            'shuffle_questions' => 'boolean', 'shuffle_options' => 'boolean', 'duration_minutes' => 'integer', 'max_exits' => 'integer', 'exit_allow' => 'integer', 'teacher_id' => 'integer', 'grade_lm' => 'integer'];
    }

    public function isUh(): bool
    {
        return $this->kind === 'uh';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind ?: 'ujian'] ?? 'Ujian';
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CbtExamQuestion::class)->orderBy('number');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(CbtExamClass::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CbtAttempt::class);
    }

    /** draf | terbit (belum mulai) | berlangsung | selesai */
    public function phase(): string
    {
        if ($this->status !== 'terbit') {
            return 'draf';
        }
        $now = now();

        return $now->lt($this->starts_at) ? 'terbit' : ($now->lt($this->ends_at) ? 'berlangsung' : 'selesai');
    }
}
