<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitSubmission extends Model
{
    use BelongsToSchool;

    protected $fillable = ['kind','title','user_id','student_id','classroom_id','coordinator_id','file_path','file_name','file_type','file_size'];

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
