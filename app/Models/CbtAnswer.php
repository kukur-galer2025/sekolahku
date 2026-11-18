<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbtAnswer extends Model
{
    use BelongsToSchool;

    protected $fillable = ['cbt_attempt_id', 'cbt_exam_question_id', 'choice', 'text', 'flagged', 'rev', 'is_correct', 'score', 'graded_by', 'graded_at', 'note'];

    protected function casts(): array
    {
        return ['choice' => 'integer', 'flagged' => 'boolean', 'rev' => 'integer', 'is_correct' => 'boolean', 'score' => 'float', 'graded_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CbtAttempt::class, 'cbt_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtExamQuestion::class, 'cbt_exam_question_id');
    }
}
