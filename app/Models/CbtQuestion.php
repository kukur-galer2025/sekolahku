<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Soal di paket guru. options: [{text, image}] — image = jalur berkas sekolah (gambar opsi, opsional). */
class CbtQuestion extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    protected $fillable = ['cbt_bank_id', 'number', 'type', 'body', 'image_path', 'options', 'answer', 'score', 'essay_guide'];

    protected function casts(): array
    {
        return ['options' => 'array', 'number' => 'integer', 'answer' => 'integer', 'score' => 'float'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(CbtBank::class, 'cbt_bank_id');
    }
}
