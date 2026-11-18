<?php

namespace Tests\Feature\Tenancy;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tes penjaga: gagal begitu ada kode baru yang melanggar aturan tenant,
 * jauh sebelum sampai ke sekolah pengguna.
 */
class TenancyArchitectureTest extends TestCase
{
    /** @return array<string, class-string<Model>> nama tabel → kelas model */
    private function models(): array
    {
        $map = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (is_subclass_of($class, Model::class)) {
                $map[(new $class)->getTable()] = $class;
            }
        }

        return $map;
    }

    public function test_setiap_tabel_ber_school_id_punya_model_yang_memakai_belongs_to_school(): void
    {
        $models = $this->models();
        $tables = collect(Schema::getTables())->pluck('name')
            ->filter(fn ($t) => Schema::hasColumn($t, 'school_id'));

        $this->assertNotEmpty($tables);

        foreach ($tables as $table) {
            $this->assertArrayHasKey($table, $models, "Tabel '$table' punya school_id tetapi tidak punya model. Buat model dengan trait BelongsToSchool.");
            $this->assertContains(BelongsToSchool::class, class_uses_recursive($models[$table]),
                "Model {$models[$table]} (tabel '$table') wajib memakai trait BelongsToSchool agar query otomatis difilter per sekolah.");
        }
    }

    public function test_semua_rute_api_hanya_ada_di_subdomain_sekolah_dan_melewati_middleware_tenant(): void
    {
        $api = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => Str::startsWith($r->uri(), 'api/'));
        $this->assertNotEmpty($api);

        foreach ($api as $route) {
            $label = implode('|', $route->methods()).' '.$route->uri();
            $this->assertStringStartsWith('{tenant}.', (string) $route->getDomain(), "Rute $label harus didaftarkan di routes/tenant.php.");
            $this->assertContains('tenant', $route->gatherMiddleware(), "Rute $label harus melewati middleware tenant.");
        }
    }

    public function test_rute_yang_butuh_login_memeriksa_peran_dan_sekolah(): void
    {
        $public = ['api/school', 'api/auth/login', 'api/auth/2fa', 'api/auth/passkey/options', 'api/auth/passkey']; // 2fa & passkey: langkah login (belum login)
        $api = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => Str::startsWith($r->uri(), 'api/') && ! in_array($r->uri(), $public, true));

        foreach ($api as $route) {
            $hasRole = collect($route->gatherMiddleware())->contains(fn ($m) => $m === 'role' || Str::startsWith($m, 'role:'));
            $this->assertTrue($hasRole, 'Rute '.$route->uri().' wajib memakai middleware role (memastikan akun milik sekolah ini).');
        }
    }

    public function test_setiap_tabel_ber_school_id_ikut_backup_per_sekolah(): void
    {
        $tables = collect(\Illuminate\Support\Facades\Schema::getTableListing())
            ->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
            ->filter(fn ($t) => \Illuminate\Support\Facades\Schema::hasColumn($t, 'school_id'))
            ->reject(fn ($t) => in_array($t, \App\Services\Platform\SchoolBackup::EXCLUDED_TABLES, true))->values();

        $missing = $tables->diff(\App\Services\Platform\SchoolBackup::TABLES)->values()->all();
        $this->assertSame([], $missing, 'Tabel berikut punya school_id tetapi tidak ada di SchoolBackup::TABLES: '.implode(', ', $missing));
    }
}
