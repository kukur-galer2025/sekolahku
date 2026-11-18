<?php

namespace App\Support;

/**
 * Kode sekolah untuk masuk lewat satu pintu {domain pusat}/app tanpa perlu tahu subdomain sekolah.
 * Disusun oleh super-admin (mis. SMPN1KSG) atau dibuat acak 6 karakter (mis. A7K2QM), lalu dibagikan
 * ke admin sekolah. Kode bukan rahasia (dicetak di poster), hanya penunjuk sekolah; login tetap
 * NIK/NIS + password di dalam sekolah itu saja.
 *
 * Kode acak tidak memakai huruf/angka yang mudah tertukar (0/O, 1/I/L). Input diterima tanpa peduli
 * huruf besar/kecil, spasi, atau tanda hubung.
 */
class SchoolCode
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 6;

    /** Panjang kode buatan super-admin. */
    public const MIN = 4;

    public const MAX = 12;

    public static function generate(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s\-_.]+/', '', $value));
    }

    public static function isValidFormat(string $value): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{'.self::MIN.','.self::MAX.'}$/', $value);
    }

    /** Tampilan mudah dibaca/didikte: kode acak 6 karakter → A7K-2QM; kode susunan super-admin apa adanya. */
    public static function format(?string $code): string
    {
        if (! $code) {
            return '-';
        }

        return strlen($code) === self::LENGTH ? substr($code, 0, 3).'-'.substr($code, 3) : $code;
    }
}
