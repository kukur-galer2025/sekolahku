<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Semua gambar unggahan di-encode ulang di server:
 * - hanya JPEG/PNG/WebP yang benar-benar bisa dibuka sebagai gambar (berkas "menyamar" ditolak),
 * - metadata EXIF (termasuk lokasi GPS dari foto HP) ikut terbuang,
 * - diperkecil ke sisi maksimum dan disimpan sebagai WebP.
 */
class ImageSanitizer
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function webp(UploadedFile $file, int $maxSide = 512, bool $square = false, int $maxBytes = self::MAX_BYTES): string
    {
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Ukuran gambar maksimal '.intdiv($maxBytes, 1024 * 1024).' MB.']);
        }

        $info = @getimagesize($file->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 16 || $info[1] < 16 || $info[0] * $info[1] > 40_000_000) {
            throw ValidationException::withMessages(['file' => 'Berkas harus gambar JPG, PNG, atau WebP.']);
        }

        $src = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $src) {
            throw ValidationException::withMessages(['file' => 'Gambar tidak bisa dibaca.']);
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $sx = $sy = 0;
        if ($square) {
            $side = min($w, $h);
            $sx = intdiv($w - $side, 2);
            $sy = intdiv($h - $side, 2);
            $w = $h = $side;
        }
        $k = min(1, $maxSide / max($w, $h));
        $dw = max(1, (int) round($w * $k));
        $dh = max(1, (int) round($h * $k));

        $dst = imagecreatetruecolor($dw, $dh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $dw, $dh, $w, $h);

        ob_start();
        imagewebp($dst, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $out;
    }

    /**
     * Khusus JPG: hanya menerima JPEG asli, memutar sesuai orientasi EXIF (foto HP), memotong persegi di tengah,
     * memperkecil ke $side px, lalu di-encode ulang sebagai JPEG (metadata EXIF/GPS terbuang).
     */
    public function jpeg(UploadedFile $file, int $side = 512, int $maxBytes = self::MAX_BYTES): string
    {
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Ukuran foto maksimal '.round($maxBytes / 1048576, 1).' MB.']);
        }
        $path = $file->getRealPath();
        $info = @getimagesize($path);
        if (! $info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 64 || $info[1] < 64 || $info[0] * $info[1] > 40_000_000) {
            throw ValidationException::withMessages(['file' => 'Foto harus berformat JPG/JPEG (minimal 64×64 piksel).']);
        }
        $src = @imagecreatefromjpeg($path);
        if (! $src) {
            throw ValidationException::withMessages(['file' => 'Foto JPG tidak bisa dibaca.']);
        }
        $o = function_exists('exif_read_data') ? (int) (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) {
            $r = imagerotate($src, $rot, 0);
            imagedestroy($src);
            $src = $r;
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $c = min($w, $h);
        $d = min($side, $c);
        $dst = imagecreatetruecolor($d, $d);
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $c, 2), intdiv($h - $c, 2), $d, $d, $c, $c);
        imageinterlace($dst, true);

        ob_start();
        imagejpeg($dst, null, 86);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $out;
    }
}
