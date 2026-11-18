<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\MenuTemplate;
use App\Models\User;
use App\Support\SchoolMenu;
use Tests\TestCase;

class SchoolMenuTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'menuku', 'plan' => 'pro']);
        $this->tenant()->forget();
    }

    private function central(string $path): string
    {
        return 'http://'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    private function superadmin(): User
    {
        $u = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'superadmin', 'password' => 'SuperRahasia1']);
        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    public function test_clean_hanya_menerima_rute_peran_dikenal_dan_menu_fitur(): void
    {
        $c = SchoolMenu::clean([
            'names' => ['wali' => ['admin' => '  Musyrif  ', 'teacher' => '<b>Musyrif</b>', 'student' => 'X'], 'jurnal' => ['teacher' => 'Jurnal'],
                'hack' => ['admin' => 'x'], 'kas' => ['student' => str_repeat('a', 40)]],
            'hidden' => ['piket', 'dash', 'tidakada'],
        ]);
        $this->assertSame(['admin' => 'Musyrif', 'teacher' => 'Musyrif'], $c['names']['wali']); // siswa tidak punya menu Wali
        $this->assertArrayNotHasKey('jurnal', $c['names']);                                       // sama dengan bawaan → dibuang
        $this->assertArrayNotHasKey('hack', $c['names']);
        $this->assertSame(24, mb_strlen($c['names']['kas']['student']));
        $this->assertSame(['piket'], $c['hidden']);                                                // menu inti tidak bisa disembunyikan
    }

    public function test_panel_menyimpan_menu_sekolah_dan_portal_memakainya(): void
    {
        $super = $this->superadmin();
        $id = $this->s['school']->id;
        $this->actingAs($super)->get($this->central('panel/sekolah/'.$id))->assertOk()->assertSee('Menu &amp; istilah sekolah ini', false);

        $show = array_values(array_diff(array_keys(array_filter(SchoolMenu::CATALOG, fn ($c) => ! $c[1])), ['piket']));
        $this->actingAs($super)->put($this->central('panel/sekolah/'.$id.'/menu'), [
            'names' => ['wali' => ['admin' => 'Musyrif', 'teacher' => 'Musyrif'], 'info' => ['student' => 'Info Pondok'], 'jadwal' => ['admin' => 'Jadwal KBM']],
            'show' => $show,
        ])->assertRedirect()->assertSessionHas('ok');

        $menu = SchoolMenu::of($this->s['school']->fresh());
        $this->assertSame('Musyrif', $menu['names']['wali']['teacher']);
        $this->assertSame('Info Pondok', $menu['names']['info']['student']);
        $this->assertArrayNotHasKey('jadwal', $menu['names']);
        $this->assertSame(['piket'], $menu['hidden']);
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('action', 'school.menu_updated')->where('school_id', $id)->exists());

        // Shell PWA membawa pengaturan menu; sidebar portal admin (Blade) memakai nama baru.
        $html = $this->get($this->at($this->s['school'], 'siswa'))->getContent();
        $this->assertStringContainsString('"Info Pondok"', $html);
        $this->assertStringContainsString('"hidden":["piket"]', $html);
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'langganan'))->assertOk()->assertSee('Musyrif');

        // Kembalikan ke bawaan: semua kosong & semua tampil → pengaturan dihapus.
        $this->actingAs($super)->put($this->central('panel/sekolah/'.$id.'/menu'), ['names' => [], 'show' => array_keys(SchoolMenu::CATALOG)])->assertSessionHas('ok');
        $this->assertArrayNotHasKey('menu', (array) $this->s['school']->fresh()->settings);
    }

    public function test_template_dibuat_manual_dan_tersedia_di_detail_sekolah(): void
    {
        $super = $this->superadmin();
        $all = array_keys(SchoolMenu::CATALOG);
        $this->actingAs($super)->post($this->central('panel/paket/template-menu'), ['name' => 'Pesantren', 'description' => 'Sekolah berasrama',
            'names' => ['kas' => ['student' => 'Kas Asrama'], 'pembiasaan' => ['admin' => 'Tahfidz & Adab', 'teacher' => 'Tahfidz & Adab', 'student' => 'Tahfidz & Adab']],
            'show' => array_values(array_diff($all, ['piket']))])->assertRedirect()->assertSessionHas('ok');
        $t = MenuTemplate::where('name', 'Pesantren')->firstOrFail();
        $this->assertSame('Kas Asrama', $t->menu()['names']['kas']['student']);
        $this->assertSame(['piket'], $t->menu()['hidden']);
        $this->assertSame(5, $t->changeCount());

        // Nama unik.
        $this->actingAs($super)->post($this->central('panel/paket/template-menu'), ['name' => 'Pesantren'])->assertSessionHasErrors('name');

        $this->actingAs($super)->get($this->central('panel/paket/template-menu'))->assertOk()->assertSee('Pesantren');
        $this->actingAs($super)->get($this->central('panel/sekolah/'.$this->s['school']->id))->assertOk()->assertSee('Kas Asrama'); // data template untuk "isi cepat"

        $this->actingAs($super)->delete($this->central('panel/paket/template-menu/'.$t->id))->assertSessionHas('ok');
        $this->assertSame(0, MenuTemplate::count());
    }

    public function test_hanya_superadmin(): void
    {
        $this->actingAs($this->s['admin'])->get($this->central('panel/paket/template-menu'))->assertStatus(403);
        $this->actingAs($this->s['admin'])->put($this->central('panel/sekolah/'.$this->s['school']->id.'/menu'), [])->assertStatus(403);
    }
}
