<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Paket soal buatan guru. Paket berstatus "diterima" = isi bank soal pusat yang dikelola admin. */
class CbtBank extends Model
{
    use BelongsToSchool;

    public const STATUSES = ['draf' => 'Draf', 'dikirim' => 'Dikirim', 'diterima' => 'Diterima', 'dikembalikan' => 'Dikembalikan'];

    protected $fillable = ['teacher_id', 'kind', 'name', 'subject', 'grade', 'status', 'review_note', 'reviewed_by', 'reviewed_at', 'submitted_at',
        'file_name', 'question_count', 'created_by', 'creator_name'];

    protected function casts(): array
    {
        return ['question_count' => 'integer', 'reviewed_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CbtQuestion::class)->orderBy('number');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /** Guru masih boleh mengubah isinya. */
    public function editable(): bool
    {
        return in_array($this->status, ['draf', 'dikembalikan'], true);
    }
}
