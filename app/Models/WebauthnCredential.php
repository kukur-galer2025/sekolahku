<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kunci publik passkey (login sidik jari / Face ID) untuk satu HP milik satu akun. */
class WebauthnCredential extends Model
{
    use BelongsToSchool;

    protected $fillable = ['user_id', 'credential_id', 'credential_hash', 'public_key', 'alg', 'sign_count', 'user_handle', 'name', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return ['alg' => 'integer', 'sign_count' => 'integer', 'last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashId(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }
}
