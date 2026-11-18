<?php

namespace App\Tenancy;

use App\Models\School;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pasang trait ini di setiap model yang datanya milik satu sekolah.
 *
 * - Semua query otomatis difilter school_id (SchoolScope).
 * - Saat membuat baris baru, school_id diisi dari tenant aktif.
 * - Menulis baris untuk sekolah lain, atau memindahkan baris ke sekolah lain, ditolak.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model) {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                if ($model->school_id === null && ! $model->allowsCentralRecord()) {
                    throw new TenantViolation('Tidak ada sekolah aktif saat membuat '.class_basename($model).'.');
                }

                return;
            }

            if ($model->school_id !== null && (int) $model->school_id !== $tenantId) {
                throw new TenantViolation('Mencoba menulis '.class_basename($model).' untuk sekolah lain.');
            }

            $model->school_id = $tenantId;
        });

        static::updating(function ($model) {
            if ($model->isDirty('school_id')) {
                throw new TenantViolation('school_id tidak boleh diubah.');
            }
        });
    }

    /** Model yang boleh punya baris tanpa sekolah (mis. User super-admin) menimpa ini. */
    public function allowsCentralRecord(): bool
    {
        return false;
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
