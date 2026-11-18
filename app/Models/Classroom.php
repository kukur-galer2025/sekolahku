<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Classroom extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = ['name', 'grade', 'homeroom_teacher_id'];

    protected $hidden = ['qr_secret'];

    protected static function booted(): void
    {
        static::creating(function (Classroom $c) {
            $c->qr_secret ??= Str::random(40);
        });

        // Tingkat (7/8/9 atau X/XI/XII) mengikuti jenjang sekolah: dari isian bila valid, kalau tidak ditebak dari nama kelas.
        static::saving(function (Classroom $c) {
            $school = app(\App\Tenancy\TenantContext::class)->get();
            if (! $school) {
                return;
            }
            $grades = $school->grades();
            if (! in_array((string) $c->grade, $grades, true)) {
                $c->grade = \App\Support\SchoolLevel::gradeOf((string) $c->name, $school->schoolLevel());
            }
        });
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'homeroom_teacher_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
