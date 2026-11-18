<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** Salinan beku soal saat ujian diterbitkan (perubahan bank soal tidak memengaruhi ujian). */
class CbtExamQuestion extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    protected $fillable = ['cbt_exam_id', 'source_question_id', 'number', 'type', 'body', 'image_path', 'options', 'answer', 'score', 'essay_guide'];

    protected function casts(): array
    {
        return ['options' => 'array', 'number' => 'integer', 'answer' => 'integer', 'score' => 'float'];
    }
}
