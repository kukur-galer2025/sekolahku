<?php

namespace Tests\Feature;

use App\Models\LibraryAccessLog;
use App\Models\LibraryItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Proteksi MP-Library: tiket sekali pakai, watermark, pembatasan unduh, dan log audit. */
class LibraryProtectionTest extends TestCase
{
    private function setUpLibrary(): array
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $this->actingAs($s['teacher']->user)->post($api('library'), [
            'title' => 'Modul Anatomi', 'category' => 'Modul Ajar', 'file' => UploadedFile::fake()->image('hal.png', 900, 1200),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('item.allow_download', false);

        return [$s, $api, LibraryItem::query()->withoutGlobalScopes()->firstOrFail()];
    }

    public function test_buka_memberi_tiket_sekali_pakai_bukan_alamat_berkas(): void
    {
        [$s, $api, $item] = $this->setUpLibrary();
        $student = fn () => $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user);

        $r = $student()->postJson($api("library/{$item->id}/open"))->assertOk()
            ->assertJsonPath('type', 'image')->assertJsonPath('policy.download.allowed', false)->json();
        $this->assertArrayNotHasKey('url', $r);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $r['trace']);
        $this->assertStringContainsString('NIS 1001', $r['watermark'][0]);
        $this->assertStringContainsString('#'.$r['trace'], $r['watermark'][2]);

        $stream = $this->at($s['school'], ltrim($r['stream'], '/'));
        $res = $student()->get($stream)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('SAMEORIGIN', $res->headers->get('X-Frame-Options'));
        $this->assertNotFalse(imagecreatefromstring($res->getContent()), 'gambar ber-watermark tetap JPEG sah');

        // Tiket sudah terpakai → ditolak dan dicatat.
        $student()->getJson($stream)->assertForbidden();
        // Tiket orang lain tidak bisa dipakai guru.
        $r2 = $student()->postJson($api("library/{$item->id}/open"))->json();
        $this->actingAs($s['teacher']->user)->getJson($this->at($s['school'], ltrim($r2['stream'], '/')))->assertForbidden();

        $this->assertSame(2, LibraryAccessLog::query()->withoutGlobalScopes()->where('event', 'ticket_invalid')->count());
        $this->assertSame(2, LibraryAccessLog::query()->withoutGlobalScopes()->where('event', 'open')->where('user_id', $s['student']->user_id)->count());
    }

    public function test_unduhan_dibatasi_kebijakan_dan_jatah_harian(): void
    {
        [$s, $api, $item] = $this->setUpLibrary();
        $student = fn () => $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user);

        $student()->postJson($api("library/{$item->id}/download"))->assertForbidden();
        $this->assertSame(1, LibraryAccessLog::query()->withoutGlobalScopes()->where('event', 'blocked_download')->count());

        // Hanya pengunggah/admin yang mengatur kebijakan.
        $student()->putJson($api("library/{$item->id}/policy"), ['allow_download' => true, 'download_limit' => 2])->assertForbidden();
        $this->actingAs($s['teacher']->user)->putJson($api("library/{$item->id}/policy"), ['allow_download' => true, 'download_limit' => 2])->assertOk();

        $d = $student()->postJson($api("library/{$item->id}/download"))->assertOk()->assertJsonPath('left', 1)->json();
        $student()->get($this->at($s['school'], ltrim($d['stream'], '/')))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Modul Anatomi.jpg"');
        $student()->postJson($api("library/{$item->id}/download"))->assertOk()->assertJsonPath('left', 0);
        $student()->postJson($api("library/{$item->id}/download"))->assertForbidden();

        // Pengunggah tidak dibatasi.
        $this->actingAs($s['teacher']->user)->postJson($api("library/{$item->id}/download"))->assertOk()->assertJsonPath('left', null);
    }

    public function test_peristiwa_penampil_dan_laporan_audit(): void
    {
        [$s, $api, $item] = $this->setUpLibrary();
        $student = fn () => $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user);
        $trace = $student()->postJson($api("library/{$item->id}/open"))->json('trace');

        $student()->postJson($api("library/{$item->id}/event"), ['event' => 'screenshot', 'trace' => $trace, 'detail' => 'PrintScreen'])->assertOk();
        $student()->postJson($api("library/{$item->id}/event"), ['event' => 'hapus_data'])->assertStatus(422);
        // Kode jejak milik orang lain tidak diterima (disimpan kosong).
        $student()->postJson($api("library/{$item->id}/event"), ['event' => 'copy', 'trace' => 'ZZZZZZZZ'])->assertOk();
        $this->assertNull(LibraryAccessLog::query()->withoutGlobalScopes()->where('event', 'copy')->value('trace'));

        // Siswa tidak bisa membaca log audit.
        $student()->getJson($api('library-audit'))->assertForbidden();
        $a = $this->actingAs($s['admin'])->getJson($api('library-audit?trace='.$trace))->assertOk()->json();
        $this->assertCount(2, $a['logs']); // open + screenshot
        $this->assertGreaterThanOrEqual(2, $this->actingAs($s['admin'])->getJson($api('library-audit?suspicious=1'))->json('summary.suspicious_7d'));
        $this->actingAs($s['teacher']->user)->getJson($api('library-audit'))->assertOk()->assertJsonPath('logs.0.ip', null);
        $this->actingAs($s['admin'])->get($api('library-audit/export'))->assertOk();
    }

    public function test_siswa_kelas_lain_tidak_bisa_membuka(): void
    {
        [$s, $api, $item] = $this->setUpLibrary();
        $other = app(\App\Tenancy\TenantContext::class)->run($s['school'], fn () => \App\Models\Classroom::create(['name' => 'XI-A']));
        $item->forceFill(['classroom_id' => $other->id])->save();
        $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user)->postJson($api("library/{$item->id}/open"))->assertNotFound();
    }
}
