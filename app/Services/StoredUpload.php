<?php

namespace App\Services;

use App\Tenancy\TenantFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Unggahan dokumen sekolah (Pembiasaan, MP-Library, berkas tugas):
 * - Foto JPG/PNG/WebP di-encode ulang ke WebP (EXIF/GPS terbuang, diperkecil), sehingga foto HP 10 MB jadi ±300 KB.
 * - PDF dicek tanda tangan berkasnya (%PDF-), bukan hanya ekstensinya.
 * Disimpan di bawah school_{id}/{folder}/ — tidak bisa dibaca sekolah lain.
 */
class StoredUpload
{
    public const IMAGE_MAX = 12 * 1024 * 1024;

    public function __construct(private TenantFiles $files, private ImageSanitizer $images) {}

    /**
     * @param  bool  $allowWord  izinkan dokumen Word (.docx/.doc) — dipakai untuk pengumpulan tugas
     * @return array{path:string,name:string,type:string,size:int} type: PDF | JPG | DOCX | DOC
     */
    public function store(UploadedFile $file, string $folder, int $pdfMaxBytes = 10 * 1024 * 1024, int $imageSide = 1600, bool $allowWord = false): array
    {
        $name = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', $file->getClientOriginalName()) ?: 'berkas', 0, 150);
        $ext = strtolower($file->getClientOriginalExtension());

        if ($allowWord && in_array($ext, ['docx', 'doc'], true)) {
            return $this->storeWord($file, $folder, $ext, $name, $pdfMaxBytes);
        }

        if ($ext === 'pdf' || $file->getMimeType() === 'application/pdf') {
            if ($file->getSize() > $pdfMaxBytes) {
                throw ValidationException::withMessages(['file' => 'PDF maksimal '.round($pdfMaxBytes / 1048576).' MB. Untuk berkas besar, pakai tautan (mis. Google Drive).']);
            }
            if (! str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-')) {
                throw ValidationException::withMessages(['file' => 'Berkas PDF tidak valid.']);
            }
            $path = $this->files->put($folder.'/'.Str::random(16).'.pdf', (string) file_get_contents($file->getRealPath()));

            return ['path' => $path, 'name' => $name, 'type' => 'PDF', 'size' => (int) $file->getSize()];
        }

        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw ValidationException::withMessages(['file' => $allowWord ? 'Format hanya Word (.docx/.doc), PDF, JPG, atau PNG.' : 'Format hanya JPG, PNG, atau PDF.']);
        }
        $data = $this->images->webp($file, $imageSide, false, self::IMAGE_MAX);
        $path = $this->files->put($folder.'/'.Str::random(16).'.webp', $data);

        return ['path' => $path, 'name' => preg_replace('/\.(jpe?g|png|webp)$/i', '', $name).'.webp', 'type' => 'JPG', 'size' => strlen($data)];
    }

    /**
     * Dokumen Word: dicek isi berkasnya, bukan hanya ekstensinya.
     * .docx = ZIP berisi word/document.xml dan tanpa makro (vbaProject.bin); .doc = berkas OLE (Word 97–2003).
     * Selalu diunduh (bukan dibuka di browser) oleh FileController.
     */
    private function storeWord(UploadedFile $file, string $folder, string $ext, string $name, int $maxBytes): array
    {
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Dokumen Word maksimal '.round($maxBytes / 1048576).' MB. Simpan ulang tanpa gambar besar, atau ekspor ke PDF.']);
        }
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 8);
        if ($ext === 'docx') {
            $ok = str_starts_with($head, "PK\x03\x04");
            if ($ok && class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive;
                $ok = $zip->open($file->getRealPath()) === true;
                if ($ok) {
                    $ok = $zip->locateName('word/document.xml') !== false && $zip->locateName('word/vbaProject.bin') === false;
                    $zip->close();
                }
            }
        } else {
            $ok = $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        }
        if (! $ok) {
            throw ValidationException::withMessages(['file' => 'Dokumen Word tidak valid (atau berisi makro). Simpan ulang sebagai .docx biasa, atau ekspor ke PDF.']);
        }
        $path = $this->files->put($folder.'/'.Str::random(16).'.'.$ext, (string) file_get_contents($file->getRealPath()));

        return ['path' => $path, 'name' => $name, 'type' => strtoupper($ext), 'size' => (int) $file->getSize()];
    }
}
