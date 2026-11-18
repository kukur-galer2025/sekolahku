<?php

namespace Tests\Feature\Programs;

use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HabitLibraryTest extends TestCase
{
    private array $s;

    private Student $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->s = $this->makeSchool(['subdomain' => 'smkn1']);
        $this->other = $this->tenant()->run($this->s['school'], function () {
            $c = Classroom::create(['name' => 'X-FKK 2']);
            $u = User::factory()->create(['role' => \App\Enums\Role::Student, 'username' => '2001']);

            return Student::create(['user_id' => $u->id, 'nis' => '2001', 'name' => 'Bima', 'classroom_id' => $c->id])->setRelation('user', $u);
        });
        $this->tenant()->forget();
    }

    private function api(string $p): string
    {
        return $this->at($this->s['school'], 'api/'.$p);
    }

    private function pdf(string $name = 'resensi.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    public function test_hanya_koordinator_pilihan_admin_yang_mengisi_pembiasaan(): void
    {
        $t = $this->s['teacher'];
        // Belum ada koordinator: siswa, guru, dan admin tidak bisa mengunggah.
        $this->actingAs($this->s['student']->user)->post($this->api('habits'), ['kind' => 'creo', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($t->user)->post($this->api('habits'), ['kind' => 'creo', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->s['admin'])->post($this->api('habits'), ['kind' => 'creo', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();

        // Admin menentukan koordinator CREO; hanya dia yang mengunggah, dan hanya untuk CREO.
        $this->actingAs($this->s['admin'])->putJson($this->api('info-coordinators/creo'), ['teacher_id' => $t->id])->assertOk();
        $this->actingAs($t->user)->post($this->api('habits'), ['kind' => 'creo', 'title' => 'Dokumentasi CREO', 'file' => UploadedFile::fake()->image('poster.jpg', 1200, 800)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('item.coordinator', $t->name)->assertJsonPath('item.file_type', 'JPG');
        $this->actingAs($t->user)->post($this->api('habits'), ['kind' => 'literasi', 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($t->user)->post($this->api('habits'), ['kind' => 'creo', 'file' => UploadedFile::fake()->createWithContent('x.pdf', 'bukan pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($t->user)->getJson($this->api('habit-coordinators'))->assertJsonPath('creo.name', $t->name)->assertJsonPath('literasi', null)->assertJsonPath('mine', ['creo']);
        // Kolom informasi CREO di menu Pembiasaan: hanya koordinator CREO yang menulis, semua peran membaca.
        $this->actingAs($t->user)->postJson($this->api('announcements'), ['title' => 'Jadwal CREO', 'body' => 'Senin pagi *apel*.', 'audience' => 'semua', 'category' => 'creo'])->assertCreated();
        $this->actingAs($t->user)->postJson($this->api('announcements'), ['title' => 'X', 'body' => 'Y', 'audience' => 'semua', 'category' => 'literasi'])->assertForbidden();
        $this->actingAs($this->s['student']->user)->getJson($this->api('announcements?category=creo'))->assertJsonCount(1)->assertJsonPath('0.can_delete', false);

        // Semua peran melihat dokumentasi koordinator.
        $this->actingAs($this->s['student']->user)->getJson($this->api('habits?kind=creo'))->assertJsonCount(1)->assertJsonPath('0.can_delete', false);
        $this->actingAs($this->other->user)->getJson($this->api('habit-coordinators'))->assertOk()->assertJsonPath('mine', []);
        $url = $this->actingAs($this->s['admin'])->getJson($this->api('habits?kind=creo'))->assertJsonCount(1)->assertJsonPath('0.can_delete', false)->json('0.url');
        $this->assertStringContainsString('/berkas/pembiasaan/creo/', $url);
        $this->get($url)->assertOk();
    }

    public function test_hapus_hanya_koordinator_atau_pengunggah(): void
    {
        $this->actingAs($this->s['admin'])->putJson($this->api('info-coordinators/creo'), ['teacher_id' => $this->s['teacher']->id])->assertOk();
        $id = $this->actingAs($this->s['teacher']->user)->post($this->api('habits'), ['kind' => 'creo', 'file' => $this->pdf()], ['Accept' => 'application/json'])->json('item.id');
        $this->actingAs($this->other->user)->deleteJson($this->api('habits/'.$id))->assertForbidden();
        $this->actingAs($this->s['admin'])->deleteJson($this->api('habits/'.$id))->assertForbidden();
        $this->actingAs($this->s['teacher']->user)->deleteJson($this->api('habits/'.$id))->assertOk();
        $this->assertSame([], Storage::disk('local')->allFiles('school_'.$this->s['school']->id.'/pembiasaan/creo'));
    }

    public function test_mp_library_koleksi_per_kelas_dibuka_dan_favorit(): void
    {
        $t = fn () => $this->actingAs($this->s['teacher']->user);
        $t()->post($this->api('library'), ['title' => 'Modul Farmakologi', 'category' => 'Modul Ajar', 'subject' => 'Farmakologi', 'classroom_id' => $this->s['classroom']->id, 'file' => $this->pdf('modul.pdf')], ['Accept' => 'application/json'])->assertCreated();
        $t()->postJson($this->api('library'), ['title' => 'KBBI Daring', 'category' => 'Referensi', 'url' => 'https://kbbi.kemdikbud.go.id'])->assertCreated();
        $t()->postJson($this->api('library'), ['title' => 'Tautan jahat', 'category' => 'Referensi', 'url' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('url');
        $t()->postJson($this->api('library'), ['title' => 'Kosong', 'category' => 'Referensi'])->assertStatus(422)->assertJsonValidationErrors('file');

        // Siswa X-FKK 1 melihat 2 koleksi; siswa kelas lain hanya yang "semua kelas".
        $mine = $this->actingAs($this->s['student']->user)->getJson($this->api('library'))->assertOk()->json();
        $this->assertSame(2, $mine['stats']['total']);
        $this->actingAs($this->other->user)->getJson($this->api('library'))->assertJsonPath('stats.total', 1)->assertJsonPath('items.0.title', 'KBBI Daring');

        $modul = collect($mine['items'])->firstWhere('title', 'Modul Farmakologi');
        // Siswa kelas lain tidak bisa membuka koleksi khusus X-FKK 1.
        $this->actingAs($this->other->user)->postJson($this->api('library/'.$modul['id'].'/open'))->assertNotFound();

        $open = $this->actingAs($this->s['student']->user)->postJson($this->api('library/'.$modul['id'].'/open'))->assertOk()->json();
        $this->assertSame('pdf', $open['type']);
        $this->assertArrayNotHasKey('url', $open); // berkas hanya lewat penampil terlindung (tiket sekali pakai)
        $bin = $this->actingAs($this->s['student']->user)->get($this->at($this->s['school'], ltrim($open['stream'], '/')))->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF', $bin ^ str_repeat($open['key'], intdiv(strlen($bin), 32) + 1)); // tersamar XOR per tiket
        $this->actingAs($this->s['student']->user)->postJson($this->api('library/'.$modul['id'].'/favorite'))->assertJsonPath('favorite', true);
        $this->actingAs($this->s['student']->user)->getJson($this->api('library'))->assertJsonPath('stats.opened', 1)->assertJsonPath('stats.favorites', 1);

        // Guru melihat statistik dibuka; siswa tidak bisa menambah/menghapus.
        $this->actingAs($this->s['teacher']->user)->getJson($this->api('library'))->assertJsonPath('stats.reads', 1)->assertJsonPath('stats.active_readers', 1);
        $this->actingAs($this->s['student']->user)->postJson($this->api('library'), ['title' => 'x', 'category' => 'Lainnya', 'url' => 'https://x.id'])->assertForbidden();
        $this->actingAs($this->s['student']->user)->deleteJson($this->api('library/'.$modul['id']))->assertForbidden();
        $this->actingAs($this->s['admin'])->deleteJson($this->api('library/'.$modul['id']))->assertOk();
    }

    public function test_koleksi_sekolah_lain_tidak_terlihat(): void
    {
        $this->actingAs($this->s['teacher']->user)->postJson($this->api('library'), ['title' => 'Rahasia SMKN1', 'category' => 'Referensi', 'url' => 'https://a.id'])->assertCreated();
        $b = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->tenant()->forget();
        $this->actingAs($b['student']->user)->withSession(['tenant_id' => $b['school']->id])->getJson($this->at($b['school'], 'api/library'))->assertJsonPath('stats.total', 0);
    }
}
