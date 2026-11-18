<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Services\Platform\SchoolMapImage;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Memasang GAMBAR denah (JPG/PNG) ke satu sekolah — jasa pembuatan denah oleh Parama Tech.
 * Contoh: php artisan mypresence:denah-gambar smkcbmpwt database/data/denah-smk-citra-bangsa.jpg
 * Setelah dipasang, koordinator Sarpras tetap bisa menggantinya dari aplikasi.
 */
class InstallSchoolMapImage extends Command
{
    protected $signature = 'mypresence:denah-gambar {subdomain} {file} {--layout= : Berkas JSON data Penyusun Denah (agar koordinator bisa mengedit)}';

    protected $description = 'Pasang gambar denah sekolah (JPG/PNG) ke satu sekolah';

    public function handle(TenantContext $tenant): int
    {
        $school = School::query()->where('subdomain', $this->argument('subdomain'))->first();
        if (! $school) {
            $this->error('Sekolah dengan link portal "'.$this->argument('subdomain').'" tidak ditemukan.');

            return self::FAILURE;
        }
        $path = $this->argument('file');
        $path = is_file($path) ? $path : base_path($path);
        if (! is_file($path)) {
            $this->error("Berkas tidak ditemukan: $path");

            return self::FAILURE;
        }

        $layout = null;
        if ($lf = $this->option('layout')) {
            $lf = is_file($lf) ? $lf : base_path($lf);
            $layout = is_file($lf) ? json_decode((string) file_get_contents($lf), true) : null;
            if (! is_array($layout) || ! isset($layout['buildings'])) {
                $this->error("Berkas layout tidak terbaca: $lf");

                return self::FAILURE;
            }
        }

        return $tenant->run($school, function () use ($school, $path, $layout) {
            $d = app(SchoolMapImage::class)->store($school, (string) file_get_contents($path), 'Parama Tech');
            if ($layout) {
                $settings = $school->fresh()->settings ?? [];
                $settings['denah_layout'] = $layout;
                $school->forceFill(['settings' => $settings])->save();
                $this->info('Data Penyusun Denah ikut dipasang ('.count($layout['buildings']).' gedung).');
            }
            $this->info("Denah {$school->name} terpasang: {$d['width']}×{$d['height']} px, ".round($d['size'] / 1024).' KB.');

            return self::SUCCESS;
        });
    }
}
