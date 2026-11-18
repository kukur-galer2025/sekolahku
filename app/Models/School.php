<?php

namespace App\Models;

use App\Enums\SchoolStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant. Model ini sengaja TIDAK memakai BelongsToSchool.
 */
class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'subdomain', 'custom_domain', 'npsn', 'level', 'address', 'contact_name', 'contact_phone', 'logo_path', 'status',
        'timezone', 'plan', 'student_quota', 'trial_ends_at', 'active_until', 'suspended_at', 'suspended_reason', 'suspension_type', 'billing_notices', 'settings', 'registered_via', 'verified_at', 'deletion_requested_at', 'deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => SchoolStatus::class,
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'active_until' => 'datetime',
            'suspended_at' => 'datetime',
            'billing_notices' => 'array',
            'deletion_requested_at' => 'datetime',
            'verified_at' => 'datetime',
            'student_quota' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $forget = function (School $s) {
            \Illuminate\Support\Facades\Cache::forget('tenant:subdomain:'.$s->subdomain);
            \Illuminate\Support\Facades\Cache::forget('tenant:subdomain:'.$s->getOriginal('subdomain'));
        };
        static::saved($forget);
        static::deleted($forget);

        // Setiap sekolah baru otomatis punya kode sekolah (tidak bisa diisi lewat mass assignment).
        static::creating(function (School $s) {
            if (blank($s->access_code)) {
                $s->access_code = static::newAccessCode();
            }
        });
    }

    /** Kode sekolah unik baru, mis. A7K2QM. */
    public static function newAccessCode(): string
    {
        do {
            $code = \App\Support\SchoolCode::generate();
        } while (static::query()->where('access_code', $code)->exists());

        return $code;
    }

    /** Cari sekolah dari kode yang diketik pengguna (spasi, tanda hubung, huruf kecil diterima). */
    public static function findByAccessCode(string $input): ?self
    {
        $code = \App\Support\SchoolCode::normalize($input);

        return \App\Support\SchoolCode::isValidFormat($code) ? static::query()->where('access_code', $code)->first() : null;
    }

    /**
     * Ganti kode sekolah (khusus super-admin). Kosong = dibuat acak. Kode lama langsung tidak berlaku;
     * akun & HP yang sudah masuk tidak terpengaruh.
     */
    public function setAccessCode(?string $code = null): string
    {
        $this->access_code = filled($code) ? \App\Support\SchoolCode::normalize($code) : static::newAccessCode();
        $this->save();

        return $this->access_code;
    }

    /** Alamat satu pintu masuk di domain pusat, mis. https://parama.sbs/siswa */
    public static function codeEntryUrl(string $app = 'app'): string
    {
        return \App\Tenancy\Subdomain::centralUrl($app);
    }

    /** Subdomain tak dikenal juga dicache (0) agar pemindaian subdomain acak tidak membebani database. */
    public static function cachedBySubdomain(string $subdomain): ?self
    {
        $id = \Illuminate\Support\Facades\Cache::remember('tenant:subdomain:'.$subdomain, 60,
            fn () => static::query()->where('subdomain', $subdomain)->value('id') ?? 0);

        return $id ? static::find($id) : null;
    }

    /** Diblokir karena langganan berakhir: admin masih bisa masuk untuk membayar. */
    public function isBillingLocked(): bool
    {
        return $this->isSuspended() && $this->suspension_type === 'billing';
    }

    /** Sudah lewat masa aktif/trial tetapi masih dalam masa tenggang. */
    public function graceEndsAt(): ?\Illuminate\Support\Carbon
    {
        $exp = $this->expiresAt();

        return $exp?->copy()->addDays((int) config('billing.grace_days'));
    }

    /** Jenjang: sd | smp | sma (ditebak dari nama bila belum diisi). */
    public function schoolLevel(): string
    {
        return \App\Support\SchoolLevel::normalize($this->level, $this->name);
    }

    /** Tingkat kelas sesuai jenjang: 1–6, 7–9, atau X–XII. */
    public function grades(): array
    {
        return \App\Support\SchoolLevel::grades($this->schoolLevel());
    }

    /** Tahun ajaran berjalan (settings.academic_year bila diatur, kalau tidak dari tanggal sekarang di zona waktu sekolah). */
    public function currentAcademicYear(): string
    {
        $set = (string) $this->setting('academic_year', '');

        return preg_match('/^\d{4}\/\d{4}$/', $set) ? $set : \App\Support\SchoolLevel::academicYear($this->localNow());
    }

    /** Sudah diverifikasi Parama Tech (NPSN dicek). */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Batas siswa aktif yang boleh ditambahkan:
     *  - belum terverifikasi → dibatasi (tenancy.unverified_student_quota),
     *  - langganan AKTIF → batas paket saja (Starter/Pro; All Service tanpa batas). Siswa di atas yang sudah dibayar
     *    tetap boleh ditambah dan ditagih otomatis tiap awal bulan (prorata) oleh BillingCycle,
     *  - trial terverifikasi → kuota yang diatur panel.
     */
    public function effectiveStudentQuota(): ?int
    {
        if ($this->isVerified()) {
            if ($this->status === SchoolStatus::Active) {
                return \App\Support\Plan::limit($this->plan, 'max_students');
            }

            return $this->student_quota;
        }
        $cap = (int) config('tenancy.unverified_student_quota', 30);

        return $this->student_quota === null ? $cap : min($this->student_quota, $cap);
    }

    public function isSuspended(): bool
    {
        return $this->status === SchoolStatus::Suspended;
    }

    /** Waktu sekarang menurut zona waktu sekolah (WIB/WITA/WIT). */
    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone ?: config('tenancy.default_timezone'));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /** Hari sekolah (ISO 1..7). */
    public function schoolDays(): array
    {
        return array_map('intval', $this->setting('school_days', config('tenancy.default_school_days')));
    }

    public function isSchoolDay(CarbonImmutable $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->schoolDays(), true);
    }

    /** Batas presensi = akhir jam pelajaran ke-N (N diatur admin di Jam KBM, bawaan 1). */
    public function attendanceCutoffPeriod(): int
    {
        return max(1, (int) $this->setting('attendance_cutoff_period', 1));
    }

    /** Mode QR presensi: 'static' (dicetak, ditempel di kelas) atau 'dynamic' (layar guru, berganti 30 detik). */
    public function qrMode(): string
    {
        return $this->setting('attendance.qr_mode', 'static') === 'dynamic' ? 'dynamic' : 'static';
    }

    public function url(string $path = ''): string
    {
        return \App\Tenancy\Subdomain::url($this->subdomain, $path);
    }

    /** Berakhirnya masa pakai yang berlaku: trial → trial_ends_at, aktif → active_until. */
    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->status === SchoolStatus::Trial ? $this->trial_ends_at : $this->active_until;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
