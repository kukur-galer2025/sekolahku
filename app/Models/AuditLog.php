<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Di dalam sekolah: hanya log sekolah itu yang terlihat (global scope).
 * Panel super-admin membaca lintas sekolah dengan withoutGlobalScope(SchoolScope::class).
 */
class AuditLog extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'actor_id', 'actor_name', 'action', 'description', 'ip', 'meta'];

    /** Kelompok log untuk filter panel: label, warna chip, awalan action. */
    public const CATEGORIES = [
        'keuangan' => ['Keuangan & tagihan', 'in', ['billing.', 'finance.', 'plan.']],
        'gaji' => ['Penggajian', 'wr', ['payroll.']],
        'sekolah' => ['Sekolah & data', 'ok', ['school.', 'school_map.', 'year.', 'student.', 'teacher.', 'classroom.', 'import.', 'backup.', 'data.', 'leger.', 'info.']],
        'keamanan' => ['Keamanan & akses', 'er', ['panel.', 'impersonation.', 'admin.', 'password.', '2fa.', 'passkey.', 'email.', 'account.', 'audit.']],
        'privasi' => ['Privasi & persetujuan', 'mu', ['privacy.', 'legal.', 'consent.']],
        'ops' => ['Operasional server', 'mu', ['ops.']],
    ];

    /** [kunci, label, warna] kategori log ini. */
    public function category(): array
    {
        return self::categoryOf((string) $this->action);
    }

    public static function categoryOf(string $action): array
    {
        foreach (self::CATEGORIES as $k => [$label, $tone, $prefixes]) {
            foreach ($prefixes as $p) {
                if (str_starts_with($action, $p)) {
                    return [$k, $label, $tone];
                }
            }
        }

        return ['lain', 'Lainnya', 'mu'];
    }

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** Log tingkat platform (tanpa sekolah) diperbolehkan. */
    public function allowsCentralRecord(): bool
    {
        return true;
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
