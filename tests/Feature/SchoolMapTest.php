<?php

namespace Tests\Feature;

use App\Models\Classroom;
use App\Models\SchoolRoom;
use App\Services\Platform\Impersonation;
use Tests\TestCase;

/** Denah interaktif: per sekolah, lihat untuk semua, ubah hanya admin All Service (atau tim Parama Tech). */
class SchoolMapTest extends TestCase
{
    private array $s;

    private array $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'plan' => 'all_service']);
        $this->other = $this->makeSchool(['subdomain' => 'smkn2', 'plan' => 'all_service']);
        $this->tenant()->forget();
    }

    private function api(array $s, string $p = 'school-map'): string
    {
        return $this->at($s['school'], 'api/'.$p);
    }

    private function room(array $extra = []): array
    {
        // $extra di kiri: operator + mempertahankan kunci array kiri, jadi nilai pengganti harus di depan.
        return $extra + ['building' => 'A', 'floor' => 1, 'name' => 'X LKPC 1', 'category' => 'kelas', 'grade' => null, 'capacity' => 36, 'x' => 35, 'y' => 120, 'width' => 170, 'height' => 105];
    }

    public function test_admin_all_service_menyimpan_denah_dan_terpisah_per_sekolah(): void
    {
        $classId = $this->tenant()->run($this->s['school'], fn () => Classroom::query()->value('id'));
        $this->actingAs($this->s['admin'])->putJson($this->api($this->s), ['rooms' => [
            $this->room(['classroom_id' => $classId]),
            $this->room(['name' => 'LAB KOM 1', 'category' => 'lab', 'building' => 'Gedung C', 'floor' => 2]),
        ]])->assertOk()->assertJsonCount(2, 'rooms');

        // Sekolah lain tidak melihat denah ini.
        $this->actingAs($this->other['admin'])->withSession(['tenant_id' => $this->other['school']->id])
            ->getJson($this->api($this->other))->assertOk()->assertJsonCount(0, 'rooms');
        $this->flushSession();

        // Siswa melihat, tidak bisa mengubah.
        $this->actingAs($this->s['student']->user)->getJson($this->api($this->s))->assertOk()->assertJsonCount(2, 'rooms')->assertJsonPath('can_edit', false);
        $this->actingAs($this->s['student']->user)->putJson($this->api($this->s), ['rooms' => []])->assertForbidden();

        // Simpan ulang: ruang yang tidak dikirim terhapus; id milik sekolah lain tidak bisa disentuh.
        $id = $this->tenant()->run($this->s['school'], fn () => SchoolRoom::query()->where('category', 'lab')->value('id'));
        $this->actingAs($this->s['admin'])->putJson($this->api($this->s), ['rooms' => [$this->room(['id' => $id, 'name' => 'LAB KOM 1', 'category' => 'lab'])]])->assertOk();
        $this->assertSame(1, $this->tenant()->run($this->s['school'], fn () => SchoolRoom::query()->count()));
        $this->actingAs($this->other['admin'])->withSession(['tenant_id' => $this->other['school']->id])
            ->putJson($this->api($this->other), ['rooms' => [$this->room(['id' => $id, 'name' => 'Bajakan'])]])->assertOk();
        $this->assertSame('LAB KOM 1', $this->tenant()->run($this->s['school'], fn () => SchoolRoom::query()->find($id)->name));
    }

    public function test_kelas_sekolah_lain_tidak_bisa_ditautkan(): void
    {
        $foreign = $this->tenant()->run($this->other['school'], fn () => Classroom::query()->value('id'));
        $this->actingAs($this->s['admin'])->putJson($this->api($this->s), ['rooms' => [$this->room(['classroom_id' => $foreign])]])->assertUnprocessable();
    }

    public function test_starter_hanya_melihat_kecuali_tim_parama_tech(): void
    {
        $this->s['school']->forceFill(['plan' => 'starter'])->save();
        $this->actingAs($this->s['admin'])->getJson($this->api($this->s))->assertJsonPath('can_edit', false)->assertJsonPath('editor_plan', 'All Service');
        $this->actingAs($this->s['admin'])->putJson($this->api($this->s), ['rooms' => [$this->room()]])->assertForbidden();

        // Jasa pembuatan denah: tim Parama Tech lewat "Masuk sebagai sekolah".
        $this->actingAs($this->s['admin'])->withSession([Impersonation::SESSION_KEY => ['id' => 1, 'name' => 'Parama Tech']])
            ->putJson($this->api($this->s), ['rooms' => [$this->room()]])->assertOk();
    }

    public function test_perintah_pasang_denah_dari_json(): void
    {
        $this->artisan('mypresence:denah', ['subdomain' => 'smkn1', 'file' => 'database/data/denah-smk-citra-bangsa.json'])->assertSuccessful();
        $this->tenant()->run($this->s['school'], function () {
            $this->assertSame(58, SchoolRoom::query()->count());
            $this->assertSame('lab', SchoolRoom::query()->where('name', 'LAB KOM 1')->value('category'));
            $this->assertSame('XII', SchoolRoom::query()->where('name', 'XII LKPC 4')->value('grade'));
        });
        // Tidak menimpa tanpa --force.
        $this->artisan('mypresence:denah', ['subdomain' => 'smkn1', 'file' => 'database/data/denah-smk-citra-bangsa.json'])->assertFailed();
        $this->tenant()->run($this->other['school'], fn () => $this->assertSame(0, SchoolRoom::query()->count()));
    }
}
