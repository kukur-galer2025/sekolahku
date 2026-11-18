<?php

namespace App\Services\Platform;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * TOTP (RFC 6238) untuk 2FA: kode 6 digit berganti tiap 30 detik, cocok dengan
 * Google Authenticator, Microsoft Authenticator, Authy, 1Password, dll.
 */
class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($c) => self::B32[bindec($c)], str_split($bits, 5)));
    }

    public function step(?int $time = null): int
    {
        return intdiv($time ?? time(), 30);
    }

    public function code(string $secret, int $step): string
    {
        $key = $this->decode($secret);
        $h = hash_hmac('sha1', pack('N*', 0).pack('N*', $step), $key, true);
        $o = ord($h[19]) & 0xF;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** @return int|null langkah waktu yang cocok (±1 langkah); null bila salah atau sudah dipakai. */
    public function verify(string $secret, string $code, ?int $lastStep = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return null;
        }
        $now = $this->step();
        foreach ([$now, $now - 1, $now + 1] as $s) {
            if (hash_equals($this->code($secret, $s), $code) && ($lastStep === null || $s > $lastStep)) {
                return $s;
            }
        }

        return null;
    }

    public function uri(string $label, string $secret, string $issuer = 'MyPresence'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$label).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public function qrSvg(string $data): string
    {
        $o = new QROptions(['outputType' => QRCode::OUTPUT_MARKUP_SVG, 'outputBase64' => false, 'eccLevel' => QRCode::ECC_M, 'addQuietzone' => true, 'svgUseFillAttributes' => true]);

        return (new QRCode($o))->render($data);
    }

    /** @return list<string> */
    public function recoveryCodes(int $n = 8): array
    {
        return array_map(fn () => strtolower(bin2hex(random_bytes(3)).'-'.bin2hex(random_bytes(3))), range(1, $n));
    }

    private function decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper($b32)) as $c) {
            $i = strpos(self::B32, $c);
            if ($i !== false) {
                $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
            }
        }

        return implode('', array_map(fn ($b) => chr(bindec(str_pad($b, 8, '0'))), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }
}
