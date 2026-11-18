<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Platform\SchoolReset;
use Illuminate\Console\Command;

/**
 * Reset pabrik data sekolah (selesai piloting). Tanpa --konfirmasi hanya menampilkan ringkasan (aman).
 *   php artisan mypresence:reset-data smkcbmpwt
 *   php artisan mypresence:reset-data smkcbmpwt --konfirmasi=smkcbmpwt
 */
class ResetSchoolData extends Command
{
    protected $signature = 'mypresence:reset-data {subdomain} {--konfirmasi= : Ketik ulang subdomain untuk benar-benar menghapus}';

    protected $description = 'Kosongkan semua data sekolah (guru, siswa, kelas, jadwal, aktivitas), pengaturan & akun admin tetap.';

    private const LABELS = [
        'users' => 'Akun guru & siswa', 'teachers' => 'Guru', 'students' => 'Siswa', 'classrooms' => 'Kelas', 'schedules' => 'Jadwal',
        'attendances' => 'Presensi siswa', 'teacher_attendances' => 'Kehadiran guru', 'teacher_class_entries' => 'Masuk kelas guru',
        'journals' => 'Jurnal', 'assignments' => 'Tugas', 'announcements' => 'Informasi', 'agenda_pages' => 'Agenda',
        'cbt_banks' => 'Paket soal CBT', 'cbt_exams' => 'Ujian CBT', 'library_items' => 'Koleksi MP-Library', 'habit_submissions' => 'Pembiasaan',
        'grade_books' => 'Daftar nilai', 'leger_entries' => 'Leger', 'audit_logs' => 'Log aktivitas', 'user_notifications' => 'Notifikasi',
    ];

    public function handle(SchoolReset $reset): int
    {
        $school = School::query()->where('subdomain', $this->argument('subdomain'))->first();
        if (! $school) {
            $this->error('Sekolah tidak ditemukan.');

            return self::FAILURE;
        }

        $p = $reset->preview($school);
        $this->info("Sekolah: {$school->name} ({$school->subdomain})");
        $this->table(['Data yang akan DIHAPUS', 'Jumlah'], collect($p['rows'])->map(fn ($n, $t) => [self::LABELS[$t] ?? $t, number_format($n, 0, ',', '.')])->values()->all());
        $this->line('Berkas unggahan dihapus : '.$p['files']);
        $this->line('TETAP disimpan          : pengaturan sekolah, logo, paket & tagihan, '.$p['admins'].' akun admin, '
            .$p['kept']['lesson_periods'].' jam pelajaran, denah ('.$p['kept']['school_rooms'].' ruang, '.$p['kept_files'].' berkas)');
        $this->line('Koordinator bidang & pembiasaan dikosongkan. Backup otomatis dibuat sebelum menghapus.');

        if ($this->option('konfirmasi') !== $school->subdomain) {
            $this->warn("Belum ada yang dihapus. Untuk menjalankan: php artisan mypresence:reset-data {$school->subdomain} --konfirmasi={$school->subdomain}");

            return self::SUCCESS;
        }

        $r = $reset->run($school, 'Terminal (reset data)');
        $this->info('Selesai: '.number_format(array_sum($r['rows']), 0, ',', '.').' baris & '.$r['files'].' berkas dihapus.');
        $this->info('Backup sebelum reset: '.$r['backup'].'. Pulihkan bila perlu: php artisan school:restore '.$school->subdomain.' '.$r['backup']);

        return self::SUCCESS;
    }
}
