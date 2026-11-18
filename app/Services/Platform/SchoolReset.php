<?php

namespace App\Services\Platform;

use App\Models\School;
use App\Tenancy\TenantFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * "Reset pabrik" data satu sekolah (mis. selesai tahap piloting).
 *
 * DIHAPUS : semua guru, siswa, wali, kelas, jadwal, dan seluruh aktivitas (presensi, jurnal, tugas, nilai, CBT,
 *           informasi, agenda, pembiasaan, MP-Library, leger, impor/ekspor, notifikasi, log audit) beserta berkas unggahannya.
 * DISIMPAN: data & pengaturan sekolah (nama, logo, zona waktu, paket, tagihan, tahun ajaran, leger, batas presensi),
 *           akun ADMIN sekolah, jam pelajaran (bel), denah sekolah (gambar, layout, ruang), backup.
 * Koordinator bidang/pembiasaan ikut dikosongkan (guru yang ditunjuk sudah terhapus).
 *
 * Backup otomatis dibuat sebelum menghapus; pulihkan dengan php artisan school:restore {subdomain} {backup}.
 */
class SchoolReset
{
    /** Tabel sekolah yang TIDAK dikosongkan (pengaturan). */
    public const KEEP_TABLES = ['lesson_periods', 'school_rooms'];

    public function __construct(private SchoolBackup $backup, private AuditLogger $audit) {}

    /** Ringkasan yang akan dihapus (tanpa mengubah apa pun). */
    public function preview(School $school): array
    {
        $admins = $this->adminIds($school);
        $rows = [];
        foreach (SchoolBackup::TABLES as $t) {
            if (in_array($t, self::KEEP_TABLES, true)) {
                continue;
            }
            $q = DB::table($t)->where('school_id', $school->id);
            if ($t === 'users') {
                $q->whereNotIn('id', $admins);
            } elseif (in_array($t, ['user_devices', 'legal_acceptances'], true) && Schema::hasColumn($t, 'user_id')) {
                $q->where(fn ($w) => $w->whereNotIn('user_id', $admins)->orWhereNull('user_id'));
            }
            $n = $q->count();
            if ($n) {
                $rows[$t] = $n;
            }
        }
        [$del, $keep] = $this->filePlan($school);

        return ['rows' => $rows, 'files' => count($del), 'kept_files' => count($keep), 'admins' => count($admins),
            'kept' => collect(self::KEEP_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->where('school_id', $school->id)->count()])->all()];
    }

    public function run(School $school, string $by): array
    {
        $bk = $this->backup->create($school, $by.' (sebelum reset data)');
        $admins = $this->adminIds($school);
        $gone = DB::table('users')->where('school_id', $school->id)->whereNotIn('id', $admins)->pluck('id')->all();
        $summary = $this->preview($school)['rows'];

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($school, $admins, $gone) {
                foreach (array_reverse(SchoolBackup::TABLES) as $t) {
                    if (in_array($t, self::KEEP_TABLES, true)) {
                        continue;
                    }
                    $q = DB::table($t)->where('school_id', $school->id);
                    if ($t === 'users') {
                        $q->whereNotIn('id', $admins);
                    } elseif (in_array($t, ['user_devices', 'legal_acceptances'], true) && Schema::hasColumn($t, 'user_id')) {
                        $q->where(fn ($w) => $w->whereNotIn('user_id', $admins)->orWhereNull('user_id'));
                    }
                    $q->delete();
                }
                // Token & langganan push milik akun yang dihapus; token bantuan/reset password sekolah ini.
                foreach (['impersonation_tokens', 'password_reset_requests'] as $t) {
                    DB::table($t)->where('school_id', $school->id)->delete();
                }
                foreach (['push_subscriptions', 'webauthn_credentials', 'sessions'] as $t) {
                    if (Schema::hasTable($t) && Schema::hasColumn($t, 'user_id')) {
                        foreach (array_chunk($gone, 500) as $ids) {
                            DB::table($t)->whereIn('user_id', $ids)->delete();
                        }
                    }
                }
                $settings = (array) ($school->fresh()->settings ?? []);
                unset($settings['info_coordinators']);
                DB::table('schools')->where('id', $school->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        [$del] = $this->filePlan($school->fresh());
        $disk = Storage::disk(TenantFiles::diskName());
        foreach (array_chunk($del, 200) as $chunk) {
            $disk->delete($chunk);
        }

        $total = array_sum($summary);
        $this->audit->log('school.reset', "Reset data sekolah: $total baris & ".count($del).' berkas dihapus oleh '.$by.'. Backup: '.$bk['name'], $school, null, ['rows' => $summary, 'backup' => $bk['name']], $by);

        return ['rows' => $summary, 'files' => count($del), 'backup' => $bk['name']];
    }

    /** @return list<int> id akun admin sekolah (dipertahankan) */
    private function adminIds(School $school): array
    {
        return DB::table('users')->where('school_id', $school->id)->where('role', 'admin')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Berkas yang dihapus = semua berkas sekolah, kecuali logo, foto akun admin, dan gambar denah (pengaturan).
     *
     * @return array{0:list<string>,1:list<string>}
     */
    private function filePlan(School $school): array
    {
        $keep = array_filter([$school->logo_path, ((array) ($school->settings['denah_image'] ?? []))['path'] ?? null]);
        $keep = array_merge($keep, DB::table('users')->where('school_id', $school->id)->where('role', 'admin')->whereNotNull('photo_path')->pluck('photo_path')->all());
        $keep = array_values(array_unique(array_filter($keep, 'is_string')));
        $all = Storage::disk(TenantFiles::diskName())->allFiles('school_'.$school->id);

        $del = [];
        $kept = [];
        foreach ($all as $f) {
            in_array($f, $keep, true) ? $kept[] = $f : $del[] = $f;
        }

        return [$del, $kept];
    }
}
