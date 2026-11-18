<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegerEntry extends Model
{
    use BelongsToSchool;

    protected $fillable = ['classroom_id','student_id','term','scores','competencies','extras','absences','updated_by'];

    protected function casts(): array
    {
        return ['scores' => 'array', 'competencies' => 'array', 'extras' => 'array', 'absences' => 'array'];
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
