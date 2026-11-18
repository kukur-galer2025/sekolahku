<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** Bukti persetujuan dokumen hukum (berversi). */
class LegalAcceptance extends Model
{
    use BelongsToSchool;

    public $timestamps = false;

    protected $fillable = ['school_id', 'user_id', 'document', 'version', 'signer_name', 'signer_position', 'ip', 'user_agent', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    /** Persetujuan Syarat/Privasi bisa dicatat sebelum sekolah terbentuk penuh. */
    public function allowsCentralRecord(): bool
    {
        return true;
    }
}
