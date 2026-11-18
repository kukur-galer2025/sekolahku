<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\Platform\SchoolBackup;
use App\Tenancy\SchoolScope;
use App\Tenancy\TenantCache;
use App\Tenancy\TenantFiles;
use App\Tenancy\TenantViolation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class DataProtectionTest extends TestCase
{
    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['tenancy.backup_password' => 'sandi-backup-uji']);
        $this->a = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->b = $this->makeSchool(['subdomain' => 'smkn2']);
    }

    private function jpegWithExif(): UploadedFile
    {
        // JPEG asli + segmen APP1/EXIF palsu berisi "GPS" agar terlihat apakah metadata terbuang
        $im = imagecreatetruecolor(900, 600);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200));
        ob_start(); imagejpeg($im); $jpg = ob_get_clean();
        $exif = "\xFF\xE1".pack('n', 2 + 14)."Exif\0\0GPS-LOK";
        $path = tempnam(sys_get_temp_dir(), 'im').'.jpg';
        file_put_contents($path, substr($jpg, 0, 2).$exif.substr($jpg, 2));

        return new UploadedFile($path, 'foto.jpg', 'image/jpeg', null, true);
    }

    // ---------- awalan tenant ----------

    public function test_jalur_berkas_selalu_di_bawah_folder_sekolah_aktif(): void
    {
        $this->tenant()->run($this->a['school'], function () {
            $f = app(TenantFiles::class);
            $this->assertSame('school_'.$this->a['school']->id.'/photos/x.webp', $f->path('photos/x.webp'));
            $this->assertFalse($f->owns('school_'.$this->b['school']->id.'/photos/x.webp'));
            foreach (['../school_2/x', '/etc/passwd', 'a/../../b', 'foto saya.jpg'] as $bad) {
                try { $f->path($bad); $this->fail("$bad seharusnya ditolak"); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
            }
        });
        $this->tenant()->forget();
        $this->expectException(TenantViolation::class);
        app(TenantFiles::class)->path('x');
    }

    public function test_cache_terpisah_per_sekolah(): void
    {
        $this->tenant()->run($this->a['school'], fn () => app(TenantCache::class)->put('rekap', 'A', 60));
        $this->tenant()->run($this->b['school'], fn () => $this->assertNull(app(TenantCache::class)->get('rekap')));
        $this->tenant()->run($this->a['school'], fn () => $this->assertSame('A', app(TenantCache::class)->get('rekap')));
    }

    public function test_sesi_dari_sekolah_lain_dibatalkan(): void
    {
        $this->actingAs($this->a['admin'])->withSession(['tenant_id' => $this->b['school']->id])
            ->get($this->at($this->a['school'], 'dashboard'))->assertRedirect($this->at($this->a['school'], 'masuk'));
    }

    // ---------- unggah gambar ----------

    public function test_foto_profil_diencode_ulang_tanpa_exif_dan_hanya_bisa_dibuka_lewat_url_bertanda_tangan(): void
    {
        $res = $this->actingAs($this->a['teacher']->user)->post($this->at($this->a['school'], 'api/me/photo'), ['photo' => $this->jpegWithExif()], ['Accept' => 'application/json'])->assertOk();
        $url = $res->json('photo_url');
        $user = $this->tenant()->run($this->a['school'], fn () => User::find($this->a['teacher']->user->id));

        $this->assertStringStartsWith('school_'.$this->a['school']->id.'/photos/', $user->photo_path);
        $bin = Storage::disk('local')->get($user->photo_path);
        $this->assertSame('RIFF', substr($bin, 0, 4), 'disimpan sebagai WebP');
        $this->assertStringNotContainsString('GPS-LOK', $bin, 'metadata EXIF terbuang');
        [$w, $h] = getimagesizefromstring($bin);
        $this->assertSame([384, 384], [$w, $h], 'dipotong persegi & diperkecil');

        // Foto disajikan lewat /api/avatar/{user}?v=… : hanya untuk pengguna yang masuk di sekolah yang sama.
        $this->assertMatchesRegularExpression('#^/api/avatar/\d+\?v=[0-9a-f]{10}$#', $url);
        $avatar = $this->at($this->a['school'], ltrim($url, '/'));
        $this->actingAs($this->a['admin'])->get($avatar)->assertOk()->assertHeader('Content-Type', 'image/webp');

        auth()->logout();
        $this->flushSession();
        $this->getJson($avatar)->assertUnauthorized();

        // Sekolah lain tidak bisa membuka foto ini walau tahu id penggunanya.
        $this->actingAs($this->b['admin'])->withSession(['tenant_id' => $this->b['school']->id])
            ->getJson($this->at($this->b['school'], ltrim($url, '/')))->assertNotFound();
    }

    public function test_berkas_yang_menyamar_sebagai_gambar_ditolak(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'x').'.jpg';
        file_put_contents($path, "<?php echo 'hai'; ?>");
        $this->actingAs($this->a['teacher']->user)->post($this->at($this->a['school'], 'api/me/photo'),
            ['photo' => new UploadedFile($path, 'foto.jpg', 'image/jpeg', null, true)], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_logo_sekolah(): void
    {
        $this->actingAs($this->a['admin'])->post($this->at($this->a['school'], 'profil-sekolah'), ['logo' => $this->jpegWithExif(), 'address' => 'Jl. Merdeka 1'])->assertSessionHas('ok');
        $s = School::find($this->a['school']->id);
        $this->assertStringStartsWith('school_'.$s->id.'/logo/', $s->logo_path);
        $this->getJson($this->at($this->a['school'], 'api/school'))->assertJsonPath('logo_url', fn ($u) => str_contains((string) $u, '/berkas/logo/'));
    }

    // ---------- backup & pemulihan ----------

    public function test_backup_lalu_pulihkan_hanya_mengembalikan_sekolah_itu(): void
    {
        $this->tenant()->run($this->a['school'], fn () => app(TenantFiles::class)->put('photos/x.webp', 'GAMBAR-A'));
        $backup = app(SchoolBackup::class)->create($this->a['school']);

        // isi zip terenkripsi: tanpa sandi tidak bisa dibaca
        $zip = new \ZipArchive; $tmp = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($tmp, Storage::disk('local')->get($backup['path']));
        $zip->open($tmp);
        $this->assertFalse($zip->getFromName('db/students.json'), 'tanpa sandi tidak bisa dibuka');
        $zip->setPassword('sandi-backup-uji');
        $this->assertStringContainsString('Aulia Rahma', $zip->getFromName('db/students.json'));
        $this->assertStringNotContainsString((string) $this->b['school']->id.',"user_id"', $zip->getFromName('db/students.json'));

        // bencana: data sekolah A rusak/terhapus, sekolah B tetap bekerja
        $this->tenant()->run($this->a['school'], function () {
            Student::query()->delete();
            app(TenantFiles::class)->put('photos/x.webp', 'RUSAK');
        });
        $this->tenant()->run($this->b['school'], fn () => Student::first()->update(['name' => 'Perubahan di B']));

        $this->artisan('school:restore', ['subdomain' => 'smkn1', 'backup' => $backup['name'], '--force' => true])->assertSuccessful();

        $this->tenant()->run($this->a['school'], function () {
            $this->assertSame('Aulia Rahma', Student::first()?->name);
            $this->assertSame('GAMBAR-A', Storage::disk('local')->get('school_'.$this->a['school']->id.'/photos/x.webp'));
        });
        $this->tenant()->run($this->b['school'], fn () => $this->assertSame('Perubahan di B', Student::first()->name));
        $this->assertTrue(AuditLog::query()->withoutGlobalScope(SchoolScope::class)->where('action', 'backup.restored')->where('school_id', $this->a['school']->id)->exists());

        // setelah pulih, siswa tetap bisa login
        $this->postJson($this->at($this->a['school'], 'api/auth/login'), ['username' => 'admin', 'password' => 'rahasia123'])->assertOk();
    }

    public function test_backup_sekolah_lain_tidak_bisa_dipulihkan_ke_sekolah_ini_dan_rotasi(): void
    {
        $b = app(SchoolBackup::class)->create($this->b['school']);
        Storage::disk('local')->copy($b['path'], 'backups/school_'.$this->a['school']->id.'/'.$b['name']);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-[a-z0-9]{4}\.zip$/', $b['name']);
        $this->expectExceptionMessage('bukan milik sekolah ini');
        app(SchoolBackup::class)->restore($this->a['school'], $b['name']);
    }

    public function test_rotasi_backup(): void
    {
        config(['tenancy.backup_keep' => 2]);
        foreach (range(1, 4) as $i) {
            $this->travel(1)->minutes();
            app(SchoolBackup::class)->create($this->a['school']);
        }
        $this->assertCount(2, app(SchoolBackup::class)->list($this->a['school']));
    }

    // ---------- ekspor, persetujuan ortu, hapus ----------

    public function test_ekspor_seluruh_data_sekolah_ke_excel(): void
    {
        $this->tenant()->run($this->a['school'], fn () => Attendance::create(['student_id' => $this->a['student']->id, 'date' => '2026-11-03', 'status' => 'H', 'method' => 'qr', 'classroom_id' => $this->a['classroom']->id]));
        $this->actingAs($this->a['admin'])->post($this->at($this->a['school'], 'data-privasi/ekspor'))->assertSessionHas('ok');
        $e = $this->tenant()->run($this->a['school'], fn () => DataExport::first());
        $this->assertSame('done', $e->status, (string) $e->error);

        $res = $this->actingAs($this->a['admin'])->get($this->at($this->a['school'], 'data-privasi/ekspor/'.$e->id))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'e').'.xlsx';
        file_put_contents($path, $res->streamedContent());
        $r = new Reader; $r->open($path); $sheets = [];
        foreach ($r->getSheetIterator() as $sh) { $rows = []; foreach ($sh->getRowIterator() as $row) { $rows[] = $row->toArray(); } $sheets[$sh->getName()] = $rows; }
        $this->assertSame(['Siswa', 'Guru', 'Kelas', 'Jam KBM', 'Jadwal', 'Presensi', 'Jurnal', 'Tugas', 'Agenda & Informasi', 'Log akses'], array_keys($sheets));
        $this->assertSame('Aulia Rahma', $sheets['Siswa'][1][2]);
        $this->assertSame('Hadir', $sheets['Presensi'][1][4]);

        // sekolah lain tidak bisa mengunduh ekspor ini
        $this->actingAs($this->b['admin'])->get($this->at($this->b['school'], 'data-privasi/ekspor/'.$e->id))->assertNotFound();
    }

    public function test_catat_persetujuan_orang_tua_hanya_untuk_siswa_sekolah_sendiri(): void
    {
        $this->actingAs($this->a['admin'])->post($this->at($this->a['school'], 'data-privasi/persetujuan-ortu'), [
            'student_ids' => [$this->a['student']->id, $this->b['student']->id], 'method' => 'kertas', 'ref' => 'Formulir PPDB no. 12',
            'consent_date' => '2026-07-10', 'action' => 'record',
        ])->assertRedirect();

        $this->tenant()->run($this->a['school'], fn () => $this->assertSame('2026-07-10', Student::find($this->a['student']->id)->parent_consent_at->toDateString()));
        $this->tenant()->run($this->b['school'], fn () => $this->assertNull(Student::find($this->b['student']->id)->parent_consent_at));
        $this->actingAs($this->a['admin'])->get($this->at($this->a['school'], 'data-privasi'))->assertOk()->assertSee('1');
    }

    public function test_permintaan_hapus_dan_hapus_permanen_dari_panel(): void
    {
        $this->actingAs($this->a['admin'])->post($this->at($this->a['school'], 'data-privasi/hapus'), ['reason' => 'Pindah sistem', 'confirm' => '1'])->assertSessionHas('ok');
        $this->assertNotNull(School::find($this->a['school']->id)->deletion_requested_at);

        $this->tenant()->forget();
        $super = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'su']);
        $super->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $this->tenant()->run($this->a['school'], fn () => app(TenantFiles::class)->put('photos/a.webp', 'x'));
        app(SchoolBackup::class)->create($this->a['school']);
        $panel = 'http://'.config('tenancy.central_domain').'/panel/sekolah/'.$this->a['school']->id;

        $this->actingAs($super)->delete($panel, ['confirm' => 'smkn1'])->assertStatus(422); // belum ditangguhkan
        $this->a['school']->update(['status' => 'suspended']);
        $this->actingAs($super)->delete($panel, ['confirm' => 'salah'])->assertSessionHasErrors('confirm');
        $this->actingAs($super)->delete($panel, ['confirm' => 'smkn1'])->assertRedirect();

        $id = $this->a['school']->id;
        $this->assertNull(School::find($id));
        foreach (['users', 'students', 'teachers', 'classrooms', 'audit_logs', 'legal_acceptances', 'user_devices'] as $t) {
            $this->assertSame(0, DB::table($t)->where('school_id', $id)->count(), "$t harus kosong");
        }
        $this->assertSame([], Storage::disk('local')->allFiles('school_'.$id));
        $this->assertSame([], Storage::disk('local')->allFiles('backups/school_'.$id));
        $this->assertSame(1, DB::table('students')->where('school_id', $this->b['school']->id)->count(), 'sekolah lain utuh');
        $this->assertTrue(AuditLog::query()->withoutGlobalScope(SchoolScope::class)->whereNull('school_id')->where('action', 'school.deleted')->exists());
    }

    // ---------- dokumen hukum ----------

    public function test_dokumen_hukum_tampil_dan_api_admin_menunggu_persetujuan_dpa(): void
    {
        foreach (['syarat-layanan' => 'Syarat Layanan', 'kebijakan-privasi' => 'persetujuan orang tua', 'perjanjian-pemrosesan-data' => '3×24 jam'] as $slug => $see) {
            $this->get('http://'.config('tenancy.central_domain').'/'.$slug)->assertOk()->assertSee($see);
        }

        $this->tenant()->run($this->a['school'], fn () => \App\Models\LegalAcceptance::query()->delete());
        $this->actingAs($this->a['admin'])->getJson($this->at($this->a['school'], 'api/students'))->assertForbidden();
        $this->actingAs($this->a['admin'])->get($this->at($this->a['school'], 'data-siswa'))->assertRedirect($this->at($this->a['school'], 'persetujuan-dpa'));
    }
}
