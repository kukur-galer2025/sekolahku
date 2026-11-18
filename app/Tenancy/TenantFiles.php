<?php

namespace App\Tenancy;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * Semua berkas sekolah disimpan di bawah awalan school_{id}/ pada disk berkas tenant
 * (lokal untuk pengembangan, S3-compatible untuk produksi: IDCloudHost, Wasabi, Cloudflare R2, MinIO).
 *
 *   school_12/imports/…   school_12/photos/…   school_12/logo/…   school_12/exports/…
 *
 * Jalur selalu dibentuk dari tenant aktif dan diperiksa ulang, jadi kode sekolah A tidak bisa
 * membaca/menulis berkas sekolah B walaupun jalurnya dikirim dari luar.
 */
class TenantFiles
{
    public function __construct(private TenantContext $tenant) {}

    public static function diskName(): string
    {
        return (string) config('tenancy.files_disk', 'local');
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public function prefix(?int $schoolId = null): string
    {
        $id = $schoolId ?? $this->tenant->id();
        if (! $id) {
            throw new TenantViolation('Tidak ada sekolah aktif untuk berkas.');
        }

        return 'school_'.$id.'/';
    }

    /** Jalur lengkap dari jalur relatif; menolak "..", jalur absolut, dan karakter aneh. */
    public function path(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..') || ! preg_match('#^[A-Za-z0-9._/-]+$#', $relative)) {
            throw new InvalidArgumentException('Nama berkas tidak valid.');
        }

        return $this->prefix().$relative;
    }

    /** Apakah jalur lengkap ini milik sekolah aktif? */
    public function owns(?string $fullPath): bool
    {
        return $fullPath !== null && $fullPath !== '' && ! str_contains($fullPath, '..')
            && $this->tenant->check() && str_starts_with($fullPath, $this->prefix());
    }

    public function putFile(UploadedFile $file, string $dir, string $name): string
    {
        return $this->disk()->putFileAs(rtrim($this->path($dir), '/'), $file, $name) ?: throw new \RuntimeException('Gagal menyimpan berkas.');
    }

    public function put(string $relative, string $contents): string
    {
        $path = $this->path($relative);
        $this->disk()->put($path, $contents);

        return $path;
    }

    public function delete(?string $fullPath): void
    {
        if ($this->owns($fullPath)) {
            $this->disk()->delete($fullPath);
        }
    }

    /**
     * URL sementara untuk berkas privat: presigned URL (S3) atau rute bertanda tangan aplikasi (lokal).
     */
    /** $downloadName: nama berkas saat diunduh (mis. dokumen Word tugas siswa); ikut ditandatangani. */
    public function url(?string $fullPath, int $minutes = 30, ?string $downloadName = null): ?string
    {
        if (! $this->owns($fullPath)) {
            return null;
        }
        $disk = $this->disk();
        if (method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls() && self::diskName() !== 'local') {
            return $disk->temporaryUrl($fullPath, now()->addMinutes($minutes));
        }

        return URL::temporarySignedRoute('portal.file', now()->addMinutes($minutes), [
            'tenant' => $this->tenant->get()->subdomain, 'path' => substr($fullPath, strlen($this->prefix())),
        ] + ($downloadName ? ['n' => $downloadName] : []));
    }

    /** Jalur lokal untuk dibaca pustaka lain (mis. pembaca Excel). Disk awan disalin ke berkas sementara. */
    public function localCopy(string $fullPath): string
    {
        if (! $this->owns($fullPath)) {
            throw new TenantViolation('Berkas bukan milik sekolah ini.');
        }
        $disk = $this->disk();
        if (self::diskName() === 'local' || method_exists($disk, 'path') && is_file($p = $disk->path($fullPath))) {
            return $disk->path($fullPath);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mp').'.'.pathinfo($fullPath, PATHINFO_EXTENSION);
        file_put_contents($tmp, $disk->get($fullPath));

        return $tmp;
    }

    /** Ukuran total & jumlah berkas satu sekolah (panel super-admin; boleh tanpa tenant). */
    public static function usage(int $schoolId): array
    {
        $disk = Storage::disk(self::diskName());
        $files = $disk->allFiles('school_'.$schoolId);

        return ['files' => count($files), 'bytes' => array_sum(array_map(fn ($f) => $disk->size($f), $files))];
    }
}
