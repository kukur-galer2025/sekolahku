<?php

namespace App\Services\Platform;

use App\Models\School;
use App\Tenancy\TenantFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Backup & pemulihan PER SEKOLAH (bukan hanya seluruh database).
 *
 * Isi ZIP (terenkripsi AES-256 bila BACKUP_PASSWORD diisi):
 *   manifest.json        versi format, sekolah, waktu, jumlah baris per tabel
 *   db/{tabel}.json      semua baris milik sekolah (school_id = id)
 *   files/…              semua berkas di bawah school_{id}/ pada disk berkas tenant
 *
 * Disimpan di disk backup: backups/school_{id}/{Ymd-His}.zip, dirotasi (BACKUP_KEEP, bawaan 14).
 * Pemulihan mengganti SELURUH data sekolah itu saja; sekolah lain tidak tersentuh.
 */
class SchoolBackup
{
    public const FORMAT = 1;

    /** Urutan sesuai ketergantungan foreign key (induk dulu). */
    public const TABLES = [
        'users', 'teachers', 'classrooms', 'students', 'student_profiles', 'student_enrollments', 'lesson_periods', 'schedules',
        'user_devices', 'attendances', 'journals', 'assignments', 'assignment_done', 'agenda_pages', 'announcements', 'school_rooms',
        'teacher_attendances', 'teacher_class_entries', 'habit_submissions', 'library_items', 'library_activity', 'library_access_logs',
        'duty_rosters', 'class_notes', 'class_cash_entries', 'class_cash_settings', 'assignment_files', 'leger_distributions', 'leger_entries', 'grade_books', 'grade_entries', 'cbt_banks', 'cbt_questions',
        'cbt_exams', 'cbt_exam_questions', 'cbt_exam_classes', 'cbt_attempts', 'cbt_answers', 'cbt_exam_logs',
        'data_imports', 'data_exports', 'legal_acceptances', 'audit_logs', 'user_notifications', 'academic_year_closures',
    ];

    /**
     * Tabel ber-school_id yang sengaja TIDAK ikut backup sekolah:
     * data tagihan milik platform Parama Tech, dan token sementara (bantuan, reset password) yang tidak boleh hidup lagi.
     */
    public const EXCLUDED_TABLES = ['invoices', 'payment_events', 'impersonation_tokens', 'password_reset_requests', 'push_subscriptions', 'webauthn_credentials'];

    /** Kolom sekolah yang dipulihkan (bukan identitas tenant: subdomain/status tetap seperti sekarang). */
    private const SCHOOL_FIELDS = ['name', 'npsn', 'level', 'address', 'contact_name', 'contact_phone', 'logo_path', 'timezone', 'settings'];

    public function __construct(private AuditLogger $audit) {}

    private function disk()
    {
        return Storage::disk(config('tenancy.backup_disk', 'local'));
    }

    public function dir(School $school): string
    {
        return 'backups/school_'.$school->id;
    }

    public function create(School $school, string $by = 'Sistem'): array
    {
        // tempnam() membuat berkas kosong; nama .zip dipakai untuk arsip. Keduanya dihapus setelah selesai.
        $base = tempnam(sys_get_temp_dir(), 'bk');
        $tmp = $base.'.zip';
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($base);
            throw new RuntimeException('Tidak bisa membuat arsip backup.');
        }
        $password = (string) config('tenancy.backup_password');
        if ($password !== '') {
            $zip->setPassword($password);
        }
        $add = function (string $name, string $contents) use ($zip, $password) {
            $zip->addFromString($name, $contents);
            if ($password !== '') {
                $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
            }
        };

        $counts = [];
        foreach (self::TABLES as $t) {
            $rows = DB::table($t)->where('school_id', $school->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            $counts[$t] = count($rows);
            $add("db/$t.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $files = Storage::disk(TenantFiles::diskName());
        $prefix = 'school_'.$school->id.'/';
        $fileCount = 0;
        foreach ($files->allFiles(rtrim($prefix, '/')) as $f) {
            if (str_starts_with($f, $prefix.'exports/')) {
                continue; // hasil ekspor bersifat sementara
            }
            $add('files/'.substr($f, strlen($prefix)), (string) $files->get($f));
            $fileCount++;
        }

        $manifest = [
            'format' => self::FORMAT, 'app' => config('app.name'), 'created_at' => now()->toIso8601String(),
            'school' => ['id' => $school->id, 'subdomain' => $school->subdomain] + $school->only(self::SCHOOL_FIELDS),
            'counts' => $counts, 'files' => $fileCount, 'encrypted' => $password !== '',
        ];
        $add('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        // Nama unik (detik + akhiran acak): backup pengaman sebelum pemulihan tidak boleh menimpa backup yang dipulihkan.
        do {
            $name = now()->format('Ymd-His').'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4)).'.zip';
            $path = $this->dir($school).'/'.$name;
        } while ($this->disk()->exists($path));
        $in = fopen($tmp, 'r');
        try {
            $this->disk()->put($path, $in);
        } finally {
            // Tutup dulu: di Windows berkas yang masih terbuka tidak bisa dihapus (menumpuk di folder temp).
            if (is_resource($in)) {
                fclose($in);
            }
        }
        $size = filesize($tmp);
        @unlink($tmp);
        @unlink($base);

        $this->rotate($school);
        $this->audit->log('backup.created', "Backup dibuat ($name, ".array_sum($counts).' baris, '.$fileCount.' berkas) oleh '.$by, $school, null, ['path' => $path, 'size' => $size], $by);

        return ['path' => $path, 'name' => $name, 'size' => $size, 'counts' => $counts, 'files' => $fileCount];
    }

    /** @return list<array{name:string,path:string,size:int,time:int}> terbaru dulu */
    public function list(School $school): array
    {
        return collect($this->disk()->files($this->dir($school)))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->map(fn ($f) => ['name' => basename($f), 'path' => $f, 'size' => $this->disk()->size($f), 'time' => $this->disk()->lastModified($f)])
            ->sortByDesc('name')->values()->all();
    }

    public function rotate(School $school): int
    {
        $old = array_slice($this->list($school), max(1, (int) config('tenancy.backup_keep', 14)));
        foreach ($old as $b) {
            $this->disk()->delete($b['path']);
        }

        return count($old);
    }

    /** Pulihkan sekolah dari backup miliknya sendiri. Semua data sekolah ini saat ini diganti. */
    public function restore(School $school, string $name, string $by = 'Sistem'): array
    {
        $path = $this->dir($school).'/'.basename($name);
        if (! $this->disk()->exists($path)) {
            throw new RuntimeException("Backup $name tidak ditemukan untuk sekolah ini.");
        }

        $base = tempnam(sys_get_temp_dir(), 'rs');
        $tmp = $base.'.zip';
        file_put_contents($tmp, $this->disk()->get($path));
        $zip = new ZipArchive;
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            @unlink($base);
            throw new RuntimeException('Arsip backup rusak.');
        }

        try {
            return $this->restoreFromZip($school, $zip, $name, $by);
        } finally {
            // Berkas sementara selalu dibersihkan, termasuk bila pemulihan dibatalkan (sandi salah, sekolah lain, ...).
            $zip->close();
            @unlink($tmp);
            @unlink($base);
        }
    }

    private function restoreFromZip(School $school, ZipArchive $zip, string $name, string $by): array
    {
        if ((string) config('tenancy.backup_password') !== '') {
            $zip->setPassword((string) config('tenancy.backup_password'));
        }
        $read = function (string $n) use ($zip) {
            $c = $zip->getFromName($n);
            if ($c === false) {
                throw new RuntimeException("Isi backup tidak lengkap atau sandi salah ($n).");
            }

            return $c;
        };

        $manifest = json_decode($read('manifest.json'), true);
        if (($manifest['format'] ?? null) !== self::FORMAT || (int) ($manifest['school']['id'] ?? 0) !== $school->id) {
            throw new RuntimeException('Backup ini bukan milik sekolah ini atau formatnya tidak dikenal.');
        }

        $data = [];
        foreach (self::TABLES as $t) {
            $data[$t] = json_decode($read("db/$t.json"), true) ?? [];
            foreach ($data[$t] as $row) {
                if ((int) ($row['school_id'] ?? 0) !== $school->id) {
                    throw new RuntimeException("Baris $t dengan school_id lain ditemukan; pemulihan dibatalkan.");
                }
            }
        }

        // Foreign key dimatikan di luar transaksi (SQLite mengabaikan PRAGMA di dalam transaksi).
        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($school, $data, $manifest) {
                foreach (array_reverse(self::TABLES) as $t) {
                    DB::table($t)->where('school_id', $school->id)->delete();
                }
                DB::table('sessions')->whereIn('user_id', array_column($data['users'], 'id'))->delete();
                foreach (self::TABLES as $t) {
                    foreach (array_chunk($data[$t], 500) as $chunk) {
                        DB::table($t)->insert($chunk);
                    }
                }
                $fields = array_intersect_key($manifest['school'], array_flip(self::SCHOOL_FIELDS));
                if (isset($fields['settings']) && is_array($fields['settings'])) {
                    $fields['settings'] = json_encode($fields['settings']);
                }
                DB::table('schools')->where('id', $school->id)->update($fields + ['updated_at' => now()]);
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Berkas: kosongkan folder sekolah lalu ekstrak dari backup.
        $files = Storage::disk(TenantFiles::diskName());
        $prefix = 'school_'.$school->id;
        $files->deleteDirectory($prefix);
        $restoredFiles = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (str_starts_with($n, 'files/') && ! str_contains($n, '..')) {
                $files->put($prefix.'/'.substr($n, 6), $read($n));
                $restoredFiles++;
            }
        }

        $school->refresh();
        $this->audit->log('backup.restored', "Data sekolah dipulihkan dari backup {$name} oleh {$by}", $school, null, ['counts' => $manifest['counts']], $by);

        return ['counts' => array_map('count', $data), 'files' => $restoredFiles, 'created_at' => $manifest['created_at']];
    }

    public function download(School $school, string $name)
    {
        $path = $this->dir($school).'/'.basename($name);
        abort_unless($this->disk()->exists($path), 404);

        return $this->disk()->download($path, $school->subdomain.'-'.basename($name));
    }
}
