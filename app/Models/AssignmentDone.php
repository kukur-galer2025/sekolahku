<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class AssignmentDone extends Model
{
    use BelongsToSchool;

    protected $table = 'assignment_done';

    public $timestamps = false;

    protected $fillable = ['assignment_id', 'student_id', 'done_at', 'score', 'feedback', 'graded_by', 'graded_at'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime', 'graded_at' => 'datetime', 'score' => 'float'];
    }
}
