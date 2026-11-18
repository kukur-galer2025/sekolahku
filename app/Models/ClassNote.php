<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassNote extends Model
{
    use BelongsToSchool;

    protected $fillable = ['classroom_id','student_id','author_name','author_role','date','note'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
