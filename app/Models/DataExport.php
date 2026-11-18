<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExport extends Model
{
    use BelongsToSchool;

    protected $fillable = ['user_id', 'status', 'file_path', 'file_size', 'summary', 'error', 'expires_at'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
