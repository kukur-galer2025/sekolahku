<?php

namespace App\Services\Notify;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;

/**
 * Web Push standar (RFC 8030 + VAPID RFC 8292) TANPA isi pesan.
 * HP hanya dibangunkan; service worker lalu mengambil pemberitahuan terbaru dari /api/notifications/latest.
 * Keuntungannya: tidak ada data sekolah di server push pihak ketiga dan tidak perlu enkripsi payload.
 */
class WebPush
{
    public static function configured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    /** @return array{public:string, private:string} kunci baru: public = base64url titik EC mentah, private = base64 dari PEM */
    public static function generateKeys(): array
    {
        $opts = self::ecKeyOptions();
        $key = openssl_pkey_new($opts) ?: throw new \RuntimeException('OpenSSL gagal membuat kunci EC: '.openssl_error_string());
        openssl_pkey_export($key, $pem, null, $opts);
        $d = openssl_pkey_get_details($key)['ec'];
        $raw = "\x04".str_pad($d['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['y'], 32, "\0", STR_PAD_LEFT);

        return ['public' => self::b64url($raw), 'private' => base64_encode($pem)];
    }

    /**
     * Opsi kunci EC P-256. Di Windows (Laragon/XAMPP) PHP tidak memuat openssl.cnf secara otomatis sehingga
     * openssl_pkey_new() gagal; arahkan ke openssl.cnf bawaan PHP bila OPENSSL_CONF belum diset.
     */
    public static function ecKeyOptions(): array
    {
        $opts = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        if (PHP_OS_FAMILY === 'Windows' && ! getenv('OPENSSL_CONF') && is_file($cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf')) {
            $opts['config'] = $cnf;
        }

        return $opts;
    }

    /**
     * Header dorongan. Urgency "high": tanpa ini Android (mode Doze) menunda dorongan sampai layar dinyalakan,
     * sehingga notifikasi baru muncul saat HP dibuka.
     */
    private function headers(string $endpoint): array
    {
        $p = parse_url($endpoint);

        return [
            'TTL' => '86400',
            'Urgency' => 'high',
            'Authorization' => 'vapid t='.$this->jwt($p['scheme'].'://'.$p['host']).', k='.config('services.webpush.public_key'),
        ];
    }

    private static function validEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);

        return ($p['scheme'] ?? '') === 'https' && ! empty($p['host']);
    }

    /**
     * Kirim ke banyak HP sekaligus (paralel, 20 per gelombang) supaya ratusan siswa tidak menunggu berurutan.
     * @param  iterable<PushSubscription>  $subs
     * @return int jumlah terkirim
     */
    public function sendMany(iterable $subs): int
    {
        $ok = 0;
        foreach (collect($subs)->chunk(20) as $wave) {
            $wave = $wave->filter(function (PushSubscription $s) {
                if (self::validEndpoint($s->endpoint)) {
                    return true;
                }
                $s->delete();

                return false;
            })->values();
            if ($wave->isEmpty()) {
                continue;
            }
            $responses = Http::pool(fn ($pool) => $wave->map(fn (PushSubscription $s) => $pool->timeout(10)
                ->withHeaders($this->headers($s->endpoint))->withBody('', 'application/octet-stream')->post($s->endpoint))->all());
            foreach ($wave as $i => $s) {
                $ok += $this->settle($s, $responses[$i] ?? null) ? 1 : 0;
            }
        }

        return $ok;
    }

    /** Kirim satu dorongan. true = terkirim; false = gagal sementara. Langganan yang sudah mati dihapus. */
    public function send(PushSubscription $sub): bool
    {
        if (! self::validEndpoint($sub->endpoint)) {
            $sub->delete();

            return false;
        }

        return $this->settle($sub, Http::timeout(10)->withHeaders($this->headers($sub->endpoint))
            ->withBody('', 'application/octet-stream')->post($sub->endpoint));
    }

    private function settle(PushSubscription $sub, $res): bool
    {
        if (! $res instanceof \Illuminate\Http\Client\Response) {
            if ($res instanceof \Throwable) {
                report($res);
            }

            return false; // gagal jaringan sementara
        }
        if (in_array($res->status(), [404, 410], true)) {
            $sub->delete(); // HP mencabut izin / aplikasi dihapus

            return false;
        }
        if ($res->successful()) {
            $sub->forceFill(['last_success_at' => now()])->saveQuietly();

            return true;
        }

        return false;
    }

    private function jwt(string $audience): string
    {
        $subject = (string) config('services.webpush.subject') ?: 'mailto:'.config('mail.from.address');
        $data = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'
            .self::b64url(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
        $pem = base64_decode((string) config('services.webpush.private_key'));
        openssl_sign($data, $der, $pem, OPENSSL_ALGO_SHA256);

        return $data.'.'.self::b64url(self::derToRaw($der));
    }

    /** Tanda tangan ECDSA DER → r||s 64 byte (format JWT ES256). */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7F;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        }

        return $out;
    }

    public static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
