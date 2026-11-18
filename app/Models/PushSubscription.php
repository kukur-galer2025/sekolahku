<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HP/browser yang mengizinkan push notification untuk satu akun. */
class PushSubscription extends Model
{
    use BelongsToSchool;

    protected $fillable = ['user_id', 'endpoint_hash', 'endpoint', 'user_agent', 'last_success_at'];

    protected $hidden = ['endpoint'];

    protected function casts(): array
    {
        return ['last_success_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
