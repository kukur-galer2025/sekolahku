<?php

namespace Tests\Feature\Platform;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Reset pabrik data sekolah: data & akun guru/siswa hilang, pengaturan + admin + sekolah lain utuh. */
class SchoolResetTest extends TestCase
{
    public function test_reset_data_sekolah(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $other = $this->makeSchool(['subdomain' => 'smkn2']);
        $school = $s['school'];
        $school->update(['settings' => array_merge((array) $school->settings, ['info_coordinators' => ['sarpras' => $s['teacher']->id], 'academic_year' => '2026/2027'])]);
        $this->tenant()->run($school, fn () => Announcement::create(['title' => 'X', 'body' => 'Y', 'audience' => 'semua', 'category' => 'umum', 'created_by' => $s['admin']->id]));
        $this->tenant()->forget();
        Storage::disk('local')->put('school_'.$school->id.'/tugas/a.pdf', 'x');

        // Tanpa --konfirmasi: hanya ringkasan.
        $this->artisan('mypresence:reset-data', ['subdomain' => 'smkn1'])->assertSuccessful();
        $this->assertSame(1, DB::table('students')->where('school_id', $school->id)->count());

        $this->artisan('mypresence:reset-data', ['subdomain' => 'smkn1', '--konfirmasi' => 'smkn1'])->assertSuccessful();
        foreach (['teachers', 'students', 'classrooms', 'announcements', 'attendances'] as $t) {
            $this->assertSame(0, DB::table($t)->where('school_id', $school->id)->count(), $t);
        }
        // Dibaca langsung dari tabel: di luar request tidak ada sekolah aktif, jadi User::query() (SchoolScope) selalu kosong.
        $this->assertSame(['admin'], DB::table('users')->where('school_id', $school->id)->pluck('role')->unique()->values()->all());
        $fresh = $school->fresh();
        $this->assertSame('2026/2027', $fresh->settings['academic_year']);
        $this->assertArrayNotHasKey('info_coordinators', $fresh->settings);
        $this->assertFalse(Storage::disk('local')->exists('school_'.$school->id.'/tugas/a.pdf'));
        $this->assertNotEmpty(Storage::disk(config('tenancy.backup_disk', 'local'))->files('backups/school_'.$school->id));

        // Sekolah lain tidak tersentuh.
        $this->assertSame(1, DB::table('students')->where('school_id', $other['school']->id)->count());
        $this->assertSame(1, DB::table('teachers')->where('school_id', $other['school']->id)->count());
    }
}
