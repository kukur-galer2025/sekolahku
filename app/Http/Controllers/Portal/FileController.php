<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Tenancy\TenantFiles;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Menyajikan berkas privat sekolah lewat URL bertanda tangan berumur pendek (disk lokal). */
class FileController extends Controller
{
    public function __invoke(string $path, TenantFiles $files): StreamedResponse
    {
        $full = $files->path($path); // selalu di bawah school_{id}/ sekolah aktif
        abort_unless($files->disk()->exists($full), 404);

        $mime = $files->disk()->mimeType($full) ?: 'application/octet-stream';
        $pdf = $mime === 'application/pdf';
        $inline = str_starts_with($mime, 'image/') || $pdf; // PDF dibaca langsung di browser (MP-Library, Pembiasaan)

        // Nama unduhan asli (ditandatangani bersama URL), ekstensi tetap mengikuti berkas tersimpan.
        $name = basename($full);
        if ($n = request()->query('n')) {
            $base = trim(preg_replace('/[^\pL\pN ._()-]+/u', '_', pathinfo((string) $n, PATHINFO_FILENAME)), ' ._') ?: 'berkas';
            $name = mb_substr($base, 0, 120).'.'.pathinfo($full, PATHINFO_EXTENSION);
        }

        return $files->disk()->response($full, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
            // sandbox membuat penampil PDF Chrome menolak berkas, jadi khusus PDF tanpa sandbox (skrip tetap dilarang).
            'Content-Security-Policy' => $pdf ? "default-src 'none'; style-src 'unsafe-inline'" : "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}
