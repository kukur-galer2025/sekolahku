<?php

namespace App\Models;

use App\Enums\AttendanceMethod;
use App\Enums\AttendanceStatus;
use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = ['student_id', 'classroom_id', 'date', 'status', 'method', 'scanned_at', 'is_late', 'recorded_by', 'device_id', 'ip', 'note'];

    protected function casts(): array
    {
        return [
            // 'date' sengaja tanpa cast: disimpan apa adanya (Y-m-d menurut zona waktu sekolah)
            'status' => AttendanceStatus::class,
            'method' => AttendanceMethod::class,
            'scanned_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
