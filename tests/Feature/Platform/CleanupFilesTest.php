<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupFilesTest extends TestCase
{
    public function test_cek_dulu_lalu_hapus_folder_sekolah_yatim_saja(): void
    {
        Storage::fake('local');
        $s = $this->makeSchool(['subdomain' => 'aktif']);
        $this->tenant()->forget();
        $disk = Storage::disk('local');
        $disk->put('school_'.$s['school']->id.'/foto.webp', 'x');      // sekolah aktif → tetap
        $disk->put('school_99999/foto.webp', str_repeat('y', 2048));   // sekolah sudah dihapus → bersihkan
        $disk->put('backups/school_99999/a.zip', 'z');
        $disk->put('panel/avatars/1-lama.jpg', 'j');                     // foto tak dipakai

        $this->artisan('platform:bersihkan')->expectsOutputToContain('Mode CEK')->assertSuccessful();
        $disk->assertExists('school_99999/foto.webp');                   // mode cek tidak menghapus

        $this->artisan('platform:bersihkan', ['--hapus' => true])->assertSuccessful();
        $disk->assertMissing('school_99999/foto.webp');
        $disk->assertMissing('backups/school_99999/a.zip');
        $disk->assertMissing('panel/avatars/1-lama.jpg');
        $disk->assertExists('school_'.$s['school']->id.'/foto.webp');
    }
}
