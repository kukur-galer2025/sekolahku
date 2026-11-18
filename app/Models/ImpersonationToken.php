<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

class ImpersonationToken extends Model
{
    use BelongsToSchool;

    protected $fillable = ['school_id', 'user_id', 'superadmin_id', 'token_hash', 'reason', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
