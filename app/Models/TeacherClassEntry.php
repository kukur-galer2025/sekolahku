<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherClassEntry extends Model
{
    use BelongsToSchool;

    protected $fillable = ['teacher_id','schedule_id','classroom_id','subject','period_start','period_end','date','entered_at','is_late','method','ip','recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'entered_at' => 'datetime', 'is_late' => 'boolean', 'period_start' => 'integer', 'period_end' => 'integer'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
