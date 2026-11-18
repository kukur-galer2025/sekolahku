<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Denah sekolah sebagai gambar JPG: diunggah koordinator Sarpras, peran lain hanya melihat. */
class SchoolMapImageTest extends TestCase
{
    public function test_hanya_koordinator_sarpras_yang_mengunggah_denah(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'smkn1']);
        $api = fn ($p) => $this->at($s['school'], 'api/'.$p);
        $png = fn () => UploadedFile::fake()->image('denah.png', 1600, 1000);

        $this->actingAs($s['admin'])->post($api('school-map/image'), ['file' => $png()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($s['teacher']->user)->post($api('school-map/image'), ['file' => $png()], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs($s['admin'])->putJson($api('info-coordinators/sarpras'), ['teacher_id' => $s['teacher']->id])->assertOk();
        $this->actingAs($s['teacher']->user)->getJson($api('school-map/image'))->assertJsonPath('can_edit', true)->assertJsonPath('image', null);
        $this->actingAs($s['teacher']->user)->post($api('school-map/image'), ['file' => UploadedFile::fake()->createWithContent('x.jpg', 'bukan gambar')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($s['teacher']->user)->post($api('school-map/image'), ['file' => $png()], ['Accept' => 'application/json'])->assertOk();

        $img = $this->fromDevice($s['deviceToken'])->actingAs($s['student']->user)->getJson($api('school-map/image'))->assertOk()->assertJsonPath('can_edit', false)->json('image');
        $this->assertSame(1600, $img['width']);
        $this->get($img['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($s['admin'])->deleteJson($api('school-map/image'))->assertForbidden();
        $this->actingAs($s['teacher']->user)->deleteJson($api('school-map/image'))->assertOk();
        $this->actingAs($s['admin'])->getJson($api('school-map/image'))->assertJsonPath('image', null);

        // Data Penyusun Denah: hanya koordinator.
        $layout = ['title' => 'DENAH', 'buildings' => [['name' => 'GEDUNG A', 'span' => 'penuh', 'sections' => [['label' => '1', 'rows' => [['size' => 'besar', 'rooms' => [
            ['num' => '1', 'name' => 'X LKPC 1', 'kind' => 'x', 'cap' => 36, 'w' => 1], ['num' => '', 'name' => '', 'kind' => 'tangga', 'cap' => 0, 'w' => 0.3]]]]]]]]];
        $this->actingAs($s['admin'])->putJson($api('school-map/layout'), ['layout' => $layout])->assertForbidden();
        $this->actingAs($s['teacher']->user)->putJson($api('school-map/layout'), ['layout' => $layout])->assertOk();
        $this->actingAs($s['teacher']->user)->getJson($api('school-map/layout'))->assertJsonPath('layout.buildings.0.sections.0.rows.0.rooms.0.name', 'X LKPC 1');
        $bad = $layout; $bad['buildings'][0]['sections'][0]['rows'][0]['rooms'][0]['kind'] = 'hacker';
        $this->actingAs($s['teacher']->user)->putJson($api('school-map/layout'), ['layout' => $bad])->assertStatus(422);
    }
}
