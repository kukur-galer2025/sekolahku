<?php

namespace App\Services\Platform;

use App\Models\School;
use App\Models\User;
use App\Tenancy\TenantFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Hapus sekolah permanen: semua baris database (cascade dari schools), seluruh berkas,
 * dan seluruh backup sekolah itu. Yang tersisa hanya satu catatan di log platform.
 */
class SchoolDeletion
{
    public function __construct(private AuditLogger $audit) {}

    public function delete(School $school, User $by): void
    {
        $summary = "{$school->name} ({$school->subdomain}, id {$school->id})";
        $userIds = DB::table('users')->where('school_id', $school->id)->pluck('id');

        DB::transaction(function () use ($school, $userIds) {
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            $school->delete(); // foreign key cascade ke semua tabel ber-school_id
        });

        // Data database sudah terhapus; kegagalan menghapus berkas tidak boleh membatalkan pencatatan audit.
        $pending = array_values(array_filter([
            $this->deleteDirectory(TenantFiles::diskName(), 'school_'.$school->id),
            $this->deleteDirectory((string) config('tenancy.backup_disk', 'local'), 'backups/school_'.$school->id),
        ]));

        $this->audit->log('school.deleted', "Sekolah dihapus permanen beserta data, berkas, dan backup-nya: $summary"
            .($pending ? ' · SEBAGIAN BERKAS GAGAL DIHAPUS, jalankan: php artisan platform:bersihkan --hapus' : ''), null, $by,
            ['school_id' => $school->id, 'subdomain' => $school->subdomain, 'deletion_reason' => $school->deletion_reason] + ($pending ? ['files_pending' => $pending] : []));
    }

    /**
     * Hapus folder, dicoba ulang sampai ±8 dtk (di Windows berkas baru bisa terkunci sesaat, mis. dipindai antivirus).
     * @return string|null jalur yang gagal dihapus, atau null bila berhasil
     */
    private function deleteDirectory(string $disk, string $dir): ?string
    {
        try {
            // Terukur: Windows Defender memegang ZIP terenkripsi baru ±5 dtk. Di Linux berhasil pada percobaan pertama.
            retry(20, fn () => Storage::disk($disk)->deleteDirectory($dir), 400);

            return null;
        } catch (\Throwable $e) {
            Log::error('Gagal menghapus folder sekolah yang dihapus permanen', ['disk' => $disk, 'dir' => $dir, 'error' => $e->getMessage()]);

            return "$disk:$dir";
        }
    }
}
