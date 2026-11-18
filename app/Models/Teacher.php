<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    use BelongsToSchool, HasFactory;

    /** Sama dengan default kolom di database (guru baru langsung "aktif", bukan null). */
    protected $attributes = ['status' => 'aktif'];

    protected $fillable = ['user_id', 'nik', 'name', 'subject', 'position', 'status', 'left_at', 'left_reason'];

    public const STATUSES = ['aktif' => 'Aktif', 'mutasi' => 'Mutasi', 'pensiun' => 'Pensiun', 'nonaktif' => 'Nonaktif'];

    protected function casts(): array
    {
        return ['left_at' => 'date:Y-m-d'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Kelas yang diampu sebagai wali kelas. */
    public function homeroomClassrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'homeroom_teacher_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
