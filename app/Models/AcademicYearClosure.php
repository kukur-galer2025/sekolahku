<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat "Tutup Tahun Ajaran" (naik kelas, tinggal kelas, kelulusan) + salinan untuk dibatalkan. */
class AcademicYearClosure extends Model
{
    use BelongsToSchool;

    public const UNDO_DAYS = 7;

    protected $fillable = ['from_year', 'to_year', 'summary', 'snapshot', 'created_by', 'undone_at'];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'snapshot' => 'array', 'undone_at' => 'datetime'];
    }

    public function canUndo(): bool
    {
        return ! $this->undone_at && $this->created_at->gt(now()->subDays(self::UNDO_DAYS));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
