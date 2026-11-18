<?php

namespace Tests\Feature;

use App\Models\Announcement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Informasi per bidang (Kepegawaian, Kesiswaan, Kurikulum, Humas, Sarpras): ditulis koordinator pilihan admin; lampiran denah Sarpras; ubah nama admin. */
class InformasiTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->tenant()->forget();
    }

    private function api(string $p): string
    {
        return $this->at($this->s['school'], 'api/'.$p);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('denah-ruang-kelas.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    public function test_hanya_koordinator_bidang_yang_menulis_informasi(): void
    {
        $admin = $this->s['admin'];
        $t = $this->s['teacher'];
        $post = fn ($u, array $d, $file = null) => $this->actingAs($u)->post($this->api('announcements'), $d + ($file ? ['file' => $file] : []), ['Accept' => 'application/json']);
        $sarpras = ['title' => 'Denah kelas baru', 'body' => 'Lihat lampiran.', 'audience' => 'semua', 'category' => 'sarpras'];

        // Admin & guru biasa tidak bisa menulis; siswa tidak punya akses.
        $post($admin, $sarpras)->assertForbidden();
        $post($t->user, $sarpras)->assertForbidden();
        $post($this->s['student']->user, $sarpras)->assertForbidden();

        // Admin menunjuk guru sebagai koordinator Sarpras & Kepegawaian.
        $this->actingAs($admin)->putJson($this->api('info-coordinators/sarpras'), ['teacher_id' => $t->id])->assertOk();
        $this->actingAs($admin)->putJson($this->api('info-coordinators/kepegawaian'), ['teacher_id' => $t->id])->assertOk();
        $this->actingAs($t->user)->getJson($this->api('info-coordinators'))->assertJsonPath('mine', ['kepegawaian', 'sarpras'])->assertJsonPath('sarpras.name', $t->name);
        $this->actingAs($this->s['student']->user)->getJson($this->api('info-coordinators'))->assertOk()->assertJsonPath('mine', []);

        $post($t->user, $sarpras, $this->pdf())->assertCreated();
        $post($t->user, ['title' => 'Batas berkas PAK', 'body' => 'Jumat.', 'audience' => 'guru', 'category' => 'kepegawaian'])->assertCreated();
        $post($t->user, ['title' => 'X', 'body' => 'Y', 'audience' => 'semua', 'category' => 'humas'])->assertForbidden(); // bukan koordinator Humas
        $post($t->user, ['title' => 'X', 'body' => 'Y', 'audience' => 'semua', 'category' => 'creo'])->assertForbidden(); // bukan koordinator CREO (informasi CREO ditulis dari menu Pembiasaan)
        $post($t->user, ['title' => 'X', 'body' => 'Y', 'audience' => 'semua', 'category' => 'kepegawaian'], $this->pdf())->assertUnprocessable()->assertJsonValidationErrors('file'); // lampiran hanya Sarpras

        $row = $this->actingAs($admin)->getJson($this->api('announcements?category=sarpras'))->assertOk()->assertJsonCount(1)->json('0');
        $this->assertSame('PDF', $row['file_type']);
        $this->assertFalse($row['can_delete']); // admin hanya membaca
        $this->actingAs($this->s['student']->user)->getJson($this->api('announcements'))->assertJsonCount(1); // info "guru saja" tidak tampil ke siswa
        $this->actingAs($admin)->getJson($this->api('announcements?category=kepegawaian'))->assertJsonPath('0.category_label', 'Kepegawaian')->assertJsonPath('0.coordinator', $t->name);

        // Hapus: admin ditolak; koordinator boleh, berkas ikut terhapus.
        $path = $this->tenant()->run($this->s['school'], fn () => Announcement::query()->where('category', 'sarpras')->value('file_path'));
        $this->actingAs($admin)->deleteJson($this->api('announcements/'.$row['id']))->assertForbidden();
        Storage::disk('local')->assertExists($path);
        $this->actingAs($t->user)->deleteJson($this->api('announcements/'.$row['id']))->assertNoContent();
        Storage::disk('local')->assertMissing($path);

        // Bidang tak dikenal / guru sekolah lain ditolak; guru tidak bisa mengatur koordinator.
        $this->actingAs($admin)->putJson($this->api('info-coordinators/umum'), ['teacher_id' => $t->id])->assertNotFound();
        $this->actingAs($admin)->putJson($this->api('info-coordinators/humas'), ['teacher_id' => 999999])->assertNotFound();
        $this->actingAs($t->user)->putJson($this->api('info-coordinators/humas'), ['teacher_id' => $t->id])->assertForbidden();
    }

    public function test_admin_bisa_mengubah_nama_sendiri_guru_tidak(): void
    {
        $this->actingAs($this->s['admin'])->putJson($this->api('me/name'), ['name' => '  Didik   Nasrul '])->assertOk()->assertJsonPath('user.name', 'Didik Nasrul');
        $this->actingAs($this->s['admin'])->putJson($this->api('me/name'), ['name' => '12345'])->assertUnprocessable();
        $this->actingAs($this->s['teacher']->user)->putJson($this->api('me/name'), ['name' => 'Guru Baru'])->assertForbidden();
    }

    public function test_koordinator_melihat_dan_mengedit_postingan_sendiri(): void
    {
        $t = $this->s['teacher'];
        $this->actingAs($this->s['admin'])->putJson($this->api('info-coordinators/kesiswaan'), ['teacher_id' => $t->id])->assertOk();
        $this->actingAs($t->user)->postJson($this->api('announcements'), ['title' => 'Lomba', 'body' => 'Pendaftaran *dibuka*', 'audience' => 'semua', 'category' => 'kesiswaan'])->assertCreated();
        $a = $this->actingAs($t->user)->getJson($this->api('announcements?category=kesiswaan'))->assertJsonPath('0.mine', true)->assertJsonPath('0.can_edit', true)->json('0');

        $this->actingAs($t->user)->putJson($this->api('announcements/'.$a['id']), ['title' => 'Lomba 17-an', 'body' => 'Pendaftaran _ditutup_ Jumat', 'audience' => 'siswa'])->assertOk();
        $this->actingAs($this->s['student']->user)->getJson($this->api('announcements?category=kesiswaan'))
            ->assertJsonPath('0.title', 'Lomba 17-an')->assertJsonPath('0.can_edit', false)->assertJsonPath('0.mine', false);
        $this->actingAs($this->s['student']->user)->putJson($this->api('announcements/'.$a['id']), ['title' => 'X', 'body' => 'Y', 'audience' => 'semua'])->assertForbidden();
        $this->actingAs($this->s['admin'])->putJson($this->api('announcements/'.$a['id']), ['title' => 'X', 'body' => 'Y', 'audience' => 'semua'])->assertForbidden(); // admin hanya membaca

        // Agenda: guru koordinator agenda bisa mengubah isi, bukan koordinatornya.
        $this->actingAs($this->s['admin'])->putJson($this->api('agenda/sekolah'), ['title' => 'Agenda', 'body' => 'Senin', 'coordinator_id' => $t->id])->assertOk();
        $this->actingAs($t->user)->getJson($this->api('agenda'))->assertJsonPath('sekolah.can_edit', true)->assertJsonPath('briefing.can_edit', false);
        $this->actingAs($t->user)->putJson($this->api('agenda/sekolah'), ['title' => 'Agenda Nov', 'body' => 'Selasa rapat', 'coordinator_id' => null])->assertOk();
        $this->actingAs($t->user)->getJson($this->api('agenda'))->assertJsonPath('sekolah.page.title', 'Agenda Nov')->assertJsonPath('sekolah.page.coordinator.id', $t->id);
        $this->actingAs($t->user)->putJson($this->api('agenda/briefing'), ['title' => 'B', 'body' => 'C'])->assertForbidden();
    }
}
