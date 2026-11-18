<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** Catatan notifikasi gateway. Diproses di domain pusat (tanpa tenant), jadi baris tanpa sekolah diperbolehkan. */
class PaymentEvent extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'invoice_id', 'gateway', 'event_key', 'status', 'verified', 'result', 'message', 'payload', 'ip'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'verified' => 'boolean', 'created_at' => 'datetime'];
    }

    public function allowsCentralRecord(): bool
    {
        return true;
    }
}
