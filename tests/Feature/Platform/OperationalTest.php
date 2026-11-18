<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Csv;
use App\Tenancy\SchoolScope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tahap 7: halaman Operasional, unduh log CSV, pembersihan data menumpuk, mutasi masuk, masa simpan, alumni tidak dikirimi info. */
class OperationalTest extends TestCase
{
    private User $super;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant()->forget();
        $this->super = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'superadmin', 'password' => 'SuperRahasia1']);
        $this->super->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
    }

    private function panel(string $path = ''): string
    {
        return 'http://'.config('tenancy.central_domain').'/panel'.($path ? '/'.ltrim($path, '/') : '');
    }

    public function test_halaman_operasional_dan_unduh_log(): void
    {
        $this->actingAs($this->super)->get($this->panel('operasional'))->assertOk()->assertSee('Backup database penuh')->assertSee('Konfigurasi produksi');
        $this->actingAs($this->super)->get($this->panel('log/unduh'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->actingAs($this->s['admin'])->get($this->panel('operasional'))->assertStatus(403);
    }

    public function test_backup_database_menolak_selain_mysql(): void
    {
        if (in_array(\Illuminate\Support\Facades\DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Tes ini memeriksa penolakan untuk database selain MySQL.');
        }
        $this->expectException(\RuntimeException::class);
        app(\App\Services\Ops\DatabaseBackup::class)->create();
    }

    public function test_csv_menetralkan_rumus(): void
    {
        $this->assertSame("'=HYPERLINK(1)", Csv::cell('=HYPERLINK(1)'));
        $this->assertSame('Budi', Csv::cell('Budi'));
    }

    public function test_log_akses_sekolah_bisa_difilter_dan_diunduh(): void
    {
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'log-akses?q=zzz&from='.now()->toDateString()))->assertOk()->assertSee('Belum ada catatan');
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'log-akses/unduh'))->assertOk();
        $this->assertSame(1, AuditLog::query()->withoutGlobalScope(SchoolScope::class)->where('action', 'audit.exported')->count());
    }

    public function test_prune_notifikasi_lama(): void
    {
        $this->tenant()->run($this->s['school'], function () {
            $old = UserNotification::create(['user_id' => $this->s['admin']->id, 'type' => 'x', 'title' => 'lama']);
            DB::table('user_notifications')->where('id', $old->id)->update(['created_at' => now()->subDays(400)]);
            UserNotification::create(['user_id' => $this->s['admin']->id, 'type' => 'x', 'title' => 'baru']);
        });
        $this->artisan('ops:prune')->assertSuccessful();
        $this->assertSame(['baru'], DB::table('user_notifications')->pluck('title')->all());
    }

    public function test_mutasi_masuk_langsung_ke_kelas_dengan_password_awal(): void
    {
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'data-siswa/pindahan'), [
            'nis' => '7777', 'name' => 'Dewi Pindahan', 'classroom_id' => $this->s['classroom']->id, 'origin' => 'SMKN 2',
        ])->assertRedirect()->assertSessionHas('temp_password');

        $this->tenant()->run($this->s['school'], function () {
            $s = Student::where('nis', '7777')->first();
            $this->assertSame($this->s['classroom']->id, $s->classroom_id);
            $this->assertTrue($s->user->must_change_password);
            $this->assertSame('mutasi_masuk', $s->enrollments()->value('status'));
        });

        // NIS ganda ditolak
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'data-siswa/pindahan'), [
            'nis' => '1001', 'name' => 'X', 'classroom_id' => $this->s['classroom']->id,
        ])->assertSessionHasErrors('nis');
    }

    public function test_masa_simpan_bisa_diatur(): void
    {
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'data-privasi/masa-simpan'), ['days' => 730])->assertRedirect();
        $this->assertSame(730, \App\Console\Commands\LifecycleDaily::retentionDays($this->s['school']->fresh()));
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'data-privasi/masa-simpan'), ['days' => 5])->assertSessionHasErrors('days');
    }

    public function test_alumni_tidak_menerima_informasi_sekolah(): void
    {
        $this->tenant()->run($this->s['school'], fn () => $this->s['student']->forceFill(['status' => 'lulus', 'is_active' => false, 'left_at' => now()->toDateString()])->saveQuietly());
        $this->actingAs($this->s['admin'])->putJson($this->at($this->s['school'], 'api/info-coordinators/kesiswaan'), ['teacher_id' => $this->s['teacher']->id])->assertOk();
        $this->actingAs($this->s['teacher']->user)->postJson($this->at($this->s['school'], 'api/announcements'), ['title' => 'Libur', 'body' => 'Besok libur', 'audience' => 'semua', 'category' => 'kesiswaan'])->assertCreated();
        $this->tenant()->run($this->s['school'], fn () => $this->assertSame(0, UserNotification::where('user_id', $this->s['student']->user->id)->count()));
    }
}
