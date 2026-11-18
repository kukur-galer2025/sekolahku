<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Jam KBM: satu baris = satu jam pelajaran atau istirahat. */
class LessonPeriod extends Model
{
    use BelongsToSchool, HasFactory;

    protected $fillable = ['label', 'starts_at', 'ends_at', 'is_break', 'sort'];

    protected function casts(): array
    {
        return ['is_break' => 'boolean', 'sort' => 'integer'];
    }
}
