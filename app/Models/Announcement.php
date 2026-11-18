<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use BelongsToSchool;

    public const AUDIENCES = ['semua' => 'Guru dan siswa', 'guru' => 'Guru', 'siswa' => 'Siswa'];

    /**
     * Bidang informasi yang ditawarkan: Kepegawaian, Kesiswaan, Kurikulum, Humas, Sarpras.
     * 'creo' & 'literasi' ditulis koordinatornya dari menu Pembiasaan; 'umum' hanya untuk data lama.
     * Hanya Sarpras yang boleh melampirkan berkas (mis. denah ruang kelas).
     */
    public const CATEGORIES = ['kepegawaian' => 'Kepegawaian', 'kesiswaan' => 'Kesiswaan', 'kurikulum' => 'Kurikulum', 'humas' => 'Humas', 'sarpras' => 'Sarpras',
        'creo' => 'Pembiasaan CREO', 'literasi' => 'Pembiasaan Literasi', 'umum' => 'Umum'];

    /** Bidang yang tampil di menu Informasi. Hanya guru koordinator bidang itu yang menulis; yang lain membaca. */
    public const INFO_CATEGORIES = ['kepegawaian', 'kesiswaan', 'kurikulum', 'humas', 'sarpras'];

    /** Bidang yang boleh ditulisi informasi baru: menu Informasi + kolom informasi di menu Pembiasaan (CREO & Literasi). */
    public const WRITABLE = ['kepegawaian', 'kesiswaan', 'kurikulum', 'humas', 'sarpras', 'creo', 'literasi'];

    /** Semua bidang yang punya koordinator (guru pilihan admin), disimpan di schools.settings.info_coordinators. CREO & Literasi = menu Pembiasaan. */
    public const COORDINATED = ['kepegawaian', 'kesiswaan', 'kurikulum', 'humas', 'sarpras', 'creo', 'literasi'];

    /** id guru koordinator sebuah bidang (null bila belum dipilih admin). */
    public static function coordinatorId(string $category): ?int
    {
        $id = (int) (((array) (app(\App\Tenancy\TenantContext::class)->get()?->settings['info_coordinators'] ?? []))[$category] ?? 0);

        return $id ?: null;
    }

    /** Bidang yang dikoordinasi seorang guru. */
    public static function coordinatedBy(?int $teacherId): array
    {
        if (! $teacherId) {
            return [];
        }

        $map = (array) (app(\App\Tenancy\TenantContext::class)->get()?->settings['info_coordinators'] ?? []);

        // Urutan baku bidang (COORDINATED), bukan urutan admin menunjuk koordinator.
        return array_values(array_filter(self::COORDINATED, fn ($cat) => (int) ($map[$cat] ?? 0) === $teacherId));
    }

    public const FILE_CATEGORIES = ['sarpras'];

    protected $fillable = ['title', 'body', 'audience', 'category', 'file_path', 'file_name', 'file_type', 'created_by'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
