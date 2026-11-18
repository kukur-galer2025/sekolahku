<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImport extends Model
{
    use BelongsToSchool;

    public const TYPES = [
        'teachers' => 'Data Guru',
        'students' => 'Data Siswa',
        'lesson_periods' => 'Jam KBM',
        'schedules' => 'Jadwal Mengajar',
        'class_schedules' => 'Jadwal Kelas',
    ];

    protected $fillable = ['user_id', 'type', 'mode', 'file_name', 'file_path', 'file_size', 'status', 'preview', 'result', 'credentials', 'error', 'finished_at'];

    protected $hidden = ['credentials', 'file_path'];

    protected function casts(): array
    {
        return [
            'preview' => 'array',
            'result' => 'array',
            'credentials' => 'encrypted:array',
            'finished_at' => 'datetime',
        ];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
