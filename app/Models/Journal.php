<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Journal extends Model
{
    use BelongsToSchool;

    protected $fillable = ['teacher_id', 'classroom_id', 'date', 'period_start', 'period_end', 'subject', 'topic', 'notes', 'present', 'sick', 'permit', 'absent', 'total'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'period_start' => 'integer', 'period_end' => 'integer'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
