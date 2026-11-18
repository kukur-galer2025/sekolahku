<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu ruang di denah interaktif sekolah (gedung, lantai, posisi & ukuran di kanvas). */
class SchoolRoom extends Model
{
    use BelongsToSchool;

    public const CATEGORIES = ['kelas' => 'Ruang kelas', 'lab' => 'Laboratorium', 'kantor' => 'Kantor & layanan', 'fasilitas' => 'Fasilitas umum'];

    /** Batas kanvas editor (satuan SVG). */
    public const CANVAS = ['w' => 2000, 'h' => 1200];

    protected $fillable = ['classroom_id', 'building', 'floor', 'name', 'category', 'grade', 'capacity', 'x', 'y', 'width', 'height'];

    protected function casts(): array
    {
        return ['floor' => 'integer', 'capacity' => 'integer', 'x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float'];
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
