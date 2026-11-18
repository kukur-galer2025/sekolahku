<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use BelongsToSchool, HasFactory;

    /**
     * Struktur kelas (diatur wali kelas). Satu jabatan = satu siswa; satu siswa = satu jabatan.
     * Kunci maks. 16 karakter (kolom students.class_role).
     */
    public const CLASS_ROLES = [
        'ketua' => 'Ketua Kelas', 'wakil' => 'Wakil Ketua',
        'sekretaris_1' => 'Sekretaris 1', 'sekretaris_2' => 'Sekretaris 2',
        'bendahara_1' => 'Bendahara 1', 'bendahara_2' => 'Bendahara 2',
        'kegiatan_1' => 'Seksi Kegiatan 1', 'kegiatan_2' => 'Seksi Kegiatan 2',
        'olahraga_1' => 'Seksi Olahraga 1', 'olahraga_2' => 'Seksi Olahraga 2',
        'kebersihan_1' => 'Seksi Kebersihan 1', 'kebersihan_2' => 'Seksi Kebersihan 2',
        'keamanan_1' => 'Seksi Keamanan 1', 'keamanan_2' => 'Seksi Keamanan 2',
        'rohani_1' => 'Seksi Rohani 1', 'rohani_2' => 'Seksi Rohani 2',
    ];

    /** Pengurus inti: boleh mengisi Jurnal Kelas. */
    public const CORE_ROLES = ['ketua', 'wakil', 'sekretaris_1', 'sekretaris_2', 'bendahara_1', 'bendahara_2'];

    /**
     * Sama dengan default kolom di database. Tanpa ini, model yang baru dibuat tanpa is_active/status bernilai null
     * di memori, dan hook saved() mencatat riwayat kelas (student_enrollments) sebagai "nonaktif".
     */
    protected $attributes = ['is_active' => true, 'status' => 'aktif'];

    protected $fillable = ['user_id', 'classroom_id', 'nis', 'nisn', 'name', 'gender', 'birth_date', 'class_role', 'is_active', 'status', 'left_at', 'left_reason', 'parent_consent_at', 'parent_consent_method', 'parent_consent_ref', 'parent_consent_by'];

    protected function casts(): array
    {
        return ['birth_date' => 'date:Y-m-d', 'left_at' => 'date:Y-m-d', 'is_active' => 'boolean', 'parent_consent_at' => 'datetime'];
    }

    /**
     * Riwayat kelas tahun ajaran berjalan selalu mengikuti kelas & status siswa.
     * Tahun ajaran sebelumnya tidak pernah diubah dari sini (dikunci saat Tutup Tahun Ajaran).
     */
    protected static function booted(): void
    {
        static::saved(function (Student $s) {
            if (! $s->wasRecentlyCreated && ! $s->wasChanged(['classroom_id', 'is_active', 'status'])) {
                return;
            }
            $school = app(\App\Tenancy\TenantContext::class)->get();
            if (! $school) {
                return;
            }
            $class = $s->classroom_id ? Classroom::query()->find($s->classroom_id) : null;
            StudentEnrollment::query()->updateOrCreate(
                ['student_id' => $s->id, 'academic_year' => $school->currentAcademicYear()],
                ['classroom_id' => $class?->id, 'classroom_name' => $class?->name, 'grade' => $class?->grade,
                    'status' => $s->status && $s->status !== 'aktif' ? $s->status : ($s->is_active ? 'aktif' : 'nonaktif')],
            );
        });
    }

    public const STATUSES = ['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'lulus' => 'Lulus', 'mutasi_keluar' => 'Mutasi keluar', 'keluar' => 'Keluar', 'arsip' => 'Arsip'];

    /** Alumni boleh membuka aplikasi (hanya baca) selama 90 hari setelah lulus. */
    public const ALUMNI_DAYS = 90;

    public function alumniUntil(): ?\Illuminate\Support\Carbon
    {
        return $this->status === 'lulus' && $this->left_at ? $this->left_at->copy()->addDays(self::ALUMNI_DAYS) : null;
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Biodata buku rapor. */
    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
