<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherAttendance extends Model
{
    use BelongsToSchool;

    protected $fillable = ['teacher_id','date','checked_in_at','method','ip','device_id','recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'checked_in_at' => 'datetime'];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
