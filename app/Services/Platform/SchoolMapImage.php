<?php

namespace App\Services\Platform;

use App\Models\School;
use App\Tenancy\TenantFiles;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gambar denah sekolah (JPG) — tampilan resmi denah untuk admin, guru, dan siswa.
 * Disusun & diunggah guru koordinator Sarpras. Disimpan di settings.denah_image sekolah.
 * Gambar selalu di-encode ulang ke JPEG (metadata/EXIF terbuang, sisi terpanjang maks. 3600 px).
 */
class SchoolMapImage
{
    public const MAX_SIDE = 3600;

    public const MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(private TenantFiles $files) {}

    public function get(School $school): ?array
    {
        $d = $school->settings['denah_image'] ?? null;

        return is_array($d) && ! empty($d['path']) && $this->files->owns($d['path']) ? $d : null;
    }

    public function present(School $school, int $minutes = 180): ?array
    {
        $d = $this->get($school);

        return $d ? [
            'url' => $this->files->url($d['path'], $minutes),
            'download' => $this->files->url($d['path'], $minutes, 'Denah '.$school->name),
            'width' => $d['width'] ?? null, 'height' => $d['height'] ?? null, 'size' => $d['size'] ?? null,
            'updated_at' => $d['updated_at'] ?? null, 'updated_by' => $d['updated_by'] ?? null,
        ] : null;
    }

    /** Simpan dari isi berkas (JPG/PNG/WebP). */
    public function store(School $school, string $bytes, ?string $by): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Gambar denah maksimal 15 MB.']);
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 60_000_000) {
            throw ValidationException::withMessages(['file' => 'Denah harus gambar JPG atau PNG.']);
        }
        $src = @imagecreatefromstring($bytes) ?: throw ValidationException::withMessages(['file' => 'Gambar denah tidak bisa dibaca.']);
        [$w, $h] = [imagesx($src), imagesy($src)];
        $k = min(1, self::MAX_SIDE / max($w, $h));
        [$dw, $dh] = [max(1, (int) round($w * $k)), max(1, (int) round($h * $k))];
        $dst = imagecreatetruecolor($dw, $dh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // PNG transparan → latar putih
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);
        imageinterlace($dst, true);
        ob_start();
        imagejpeg($dst, null, 88);
        $jpg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        $old = $this->get($school);
        $path = $this->files->put('denah/denah-'.Str::random(12).'.jpg', $jpg);
        $settings = $school->settings ?? [];
        $settings['denah_image'] = ['path' => $path, 'width' => $dw, 'height' => $dh, 'size' => strlen($jpg), 'updated_at' => now()->toIso8601String(), 'updated_by' => $by];
        $school->forceFill(['settings' => $settings])->save();
        if ($old) {
            $this->files->delete($old['path']);
        }

        return $settings['denah_image'];
    }

    public function delete(School $school): void
    {
        if ($old = $this->get($school)) {
            $this->files->delete($old['path']);
        }
        $settings = $school->settings ?? [];
        unset($settings['denah_image']);
        $school->forceFill(['settings' => $settings])->save();
    }
}
