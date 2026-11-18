<?php

namespace App\Services;

use App\Models\Classroom;
use Carbon\CarbonImmutable;

/**
 * QR kelas. Dua mode, diatur per sekolah (schools.settings.attendance.qr_mode):
 *
 * - static (bawaan): QR dicetak dan ditempel di kelas. Isi: "MP-{id kelas}-{kode 8 karakter}".
 *   Kode tetap sampai admin menekan "Perbarui QR" (lalu QR dicetak ulang).
 *   Karena QR bisa difoto, keamanannya bergantung pada cek jaringan WiFi sekolah
 *   (lihat SchoolNetwork) — scan dari luar jaringan sekolah ditolak.
 *
 * - dynamic: QR di layar guru, kode 6 digit berganti tiap 30 detik (mirip OTP).
 */
class ClassQrCode
{
    public const STATIC = 'static';

    public const DYNAMIC = 'dynamic';

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // tanpa 0/O/1/I agar mudah diketik

    public function step(?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();

        return intdiv($at->getTimestamp(), $this->stepSeconds());
    }

    public function stepSeconds(): int
    {
        return max(10, (int) config('tenancy.qr_step_seconds', 30));
    }

    private function hmac(Classroom $classroom, string $message): string
    {
        return hash_hmac('sha256', $classroom->school_id.'|'.$classroom->id.'|'.$message, $classroom->qr_secret.'|'.config('app.key'));
    }

    /** Kode dinamis 6 digit untuk satu langkah waktu. */
    public function code(Classroom $classroom, int $step): string
    {
        return str_pad((string) (hexdec(substr($this->hmac($classroom, (string) $step), 0, 8)) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Kode statis 8 karakter; berubah hanya bila qr_secret kelas diganti. */
    public function staticCode(Classroom $classroom): string
    {
        $bytes = hex2bin(substr($this->hmac($classroom, 'static'), 0, 16));
        $out = '';
        foreach (str_split($bytes) as $b) {
            $out .= self::ALPHABET[ord($b) % 32];
        }

        return $out;
    }

    /** Isi QR kelas saat ini. Mode statis tidak punya masa berlaku. */
    public function current(Classroom $classroom, string $mode = self::STATIC, ?CarbonImmutable $at = null): array
    {
        if ($mode === self::STATIC) {
            return ['mode' => self::STATIC, 'payload' => 'MP-'.$classroom->id.'-'.$this->staticCode($classroom), 'expires_in' => null];
        }

        $at ??= CarbonImmutable::now();

        return [
            'mode' => self::DYNAMIC,
            'payload' => 'MP-'.$classroom->id.'-'.$this->code($classroom, $this->step($at)),
            'expires_in' => $this->stepSeconds() - ($at->getTimestamp() % $this->stepSeconds()),
        ];
    }

    /** @return array{0:int,1:string}|null [id kelas, kode] */
    public function parse(string $payload): ?array
    {
        if (! preg_match('/^MP-(\d{1,10})-([A-Z0-9]{6,10})$/', strtoupper(trim($payload)), $m)) {
            return null;
        }

        return [(int) $m[1], $m[2]];
    }

    public function verify(Classroom $classroom, string $code, string $mode = self::STATIC, ?CarbonImmutable $at = null): bool
    {
        if ($mode === self::STATIC) {
            return hash_equals($this->staticCode($classroom), $code);
        }

        // Dinamis: langkah sekarang atau satu langkah sebelumnya (toleransi jam ponsel/jaringan).
        $step = $this->step($at);
        foreach ([$step, $step - 1] as $s) {
            if (hash_equals($this->code($classroom, $s), $code)) {
                return true;
            }
        }

        return false;
    }
}
