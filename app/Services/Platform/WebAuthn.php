<?php

namespace App\Services\Platform;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Verifikasi WebAuthn (passkey: sidik jari / Face ID / PIN layar HP) tanpa pustaka tambahan.
 * Didukung: ES256 (-7, hampir semua Android & iPhone) dan RS256 (-257, Windows Hello).
 * Attestation "none": kita tidak memeriksa merek authenticator, hanya kunci publik + tanda tangan.
 */
class WebAuthn
{
    public const ALGS = [-7, -257];

    private const FLAG_UP = 0x01; // user present

    private const FLAG_UV = 0x04; // user verified (sidik jari / wajah / PIN)

    private const FLAG_AT = 0x40; // ada data kredensial baru

    public static function rpId(Request $request): string
    {
        return $request->getHost();
    }

    public static function origin(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    public static function challenge(): string
    {
        return self::b64url(random_bytes(32));
    }

    /**
     * Pendaftaran: periksa clientDataJSON & attestationObject.
     * @return array{credential_id:string, public_key:string, alg:int, sign_count:int}
     */
    public function verifyRegistration(Request $request, string $challenge, string $clientDataB64, string $attestationB64): array
    {
        $this->checkClientData(self::unb64url($clientDataB64), 'webauthn.create', $challenge, self::origin($request));
        $att = $this->cbor(self::unb64url($attestationB64));
        if (! is_array($att) || ! isset($att['authData']) || ! is_string($att['authData'])) {
            $this->fail('Data pendaftaran sidik jari tidak lengkap.');
        }
        $auth = $this->authData($att['authData'], self::rpId($request));
        if (! ($auth['flags'] & self::FLAG_AT) || $auth['credential_id'] === null) {
            $this->fail('HP tidak mengirim kunci baru. Coba lagi.');
        }
        [$pem, $alg] = $this->coseToPem($auth['cose']);

        return ['credential_id' => self::b64url($auth['credential_id']), 'public_key' => $pem, 'alg' => $alg, 'sign_count' => $auth['sign_count']];
    }

    /**
     * Masuk: periksa tanda tangan HP atas tantangan server.
     * @return int sign_count baru
     */
    public function verifyAssertion(Request $request, string $challenge, string $clientDataB64, string $authDataB64, string $signatureB64, string $pem, int $storedCount): int
    {
        $clientData = self::unb64url($clientDataB64);
        $authData = self::unb64url($authDataB64);
        $this->checkClientData($clientData, 'webauthn.get', $challenge, self::origin($request));
        $auth = $this->authData($authData, self::rpId($request));

        $ok = openssl_verify($authData.hash('sha256', $clientData, true), self::unb64url($signatureB64), $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            $this->fail('Verifikasi sidik jari gagal. Coba lagi atau masuk dengan password.');
        }
        // Penghitung yang mundur = kemungkinan kunci disalin. (Banyak passkey selalu 0 → dilewati.)
        if ($auth['sign_count'] > 0 && $storedCount > 0 && $auth['sign_count'] <= $storedCount) {
            $this->fail('Kunci sidik jari HP ini tidak valid lagi. Masuk dengan password lalu daftarkan ulang.');
        }

        return $auth['sign_count'];
    }

    // ── bagian dalam ────────────────────────────────────────────────

    private function checkClientData(string $json, string $type, string $challenge, string $origin): void
    {
        $c = json_decode($json, true);
        if (! is_array($c) || ($c['type'] ?? '') !== $type) {
            $this->fail('Data verifikasi tidak valid.');
        }
        if (! hash_equals($challenge, (string) ($c['challenge'] ?? ''))) {
            $this->fail('Sesi verifikasi berakhir. Coba lagi.');
        }
        if (($c['origin'] ?? '') !== $origin) {
            $this->fail('Sidik jari terdaftar untuk alamat lain. Buka aplikasi dari alamat sekolahmu.');
        }
    }

    /** @return array{flags:int, sign_count:int, credential_id:?string, cose:?array} */
    private function authData(string $bin, string $rpId): array
    {
        if (strlen($bin) < 37) {
            $this->fail('Data authenticator terlalu pendek.');
        }
        if (! hash_equals(hash('sha256', $rpId, true), substr($bin, 0, 32))) {
            $this->fail('Sidik jari terdaftar untuk sekolah lain.');
        }
        $flags = ord($bin[32]);
        if (! ($flags & self::FLAG_UP) || ! ($flags & self::FLAG_UV)) {
            $this->fail('HP tidak memverifikasi sidik jari / kunci layar. Pastikan kunci layar HP aktif.');
        }
        $count = unpack('N', substr($bin, 33, 4))[1];
        $credId = null;
        $cose = null;
        if ($flags & self::FLAG_AT) {
            $len = unpack('n', substr($bin, 53, 2))[1];
            $credId = substr($bin, 55, $len);
            $offset = 55 + $len;
            $cose = $this->cborAt($bin, $offset);
        }

        return ['flags' => $flags, 'sign_count' => $count, 'credential_id' => $credId, 'cose' => $cose];
    }

    /** @return array{0:string,1:int} [PEM, alg] */
    private function coseToPem(mixed $k): array
    {
        if (! is_array($k)) {
            $this->fail('Kunci publik tidak terbaca.');
        }
        $kty = $k[1] ?? null;
        $alg = $k[3] ?? null;
        if ($kty === 2 && $alg === -7 && ($k[-1] ?? null) === 1 && strlen($k[-2] ?? '') === 32 && strlen($k[-3] ?? '') === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').chr(4).$k[-2].$k[-3];

            return [$this->pem($der), -7];
        }
        if ($kty === 3 && $alg === -257 && is_string($k[-1] ?? null) && is_string($k[-2] ?? null)) {
            $rsa = $this->derSeq($this->derInt($k[-1]).$this->derInt($k[-2]));
            $algId = hex2bin('300d06092a864886f70d0101010500');
            $der = $this->derSeq($algId.chr(0x03).$this->derLen(strlen($rsa) + 1)."\0".$rsa);

            return [$this->pem($der), -257];
        }
        $this->fail('Jenis kunci HP ini belum didukung.');
    }

    private function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function derLen(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\0");

        return chr(0x80 | strlen($b)).$b;
    }

    private function derSeq(string $body): string
    {
        return chr(0x30).$this->derLen(strlen($body)).$body;
    }

    private function derInt(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || ord($bytes[0]) & 0x80) {
            $bytes = "\0".$bytes;
        }

        return chr(0x02).$this->derLen(strlen($bytes)).$bytes;
    }

    /** Dekode CBOR penuh (RFC 8949, bagian yang dipakai WebAuthn). */
    private function cbor(string $bin): mixed
    {
        $o = 0;

        return $this->cborAt($bin, $o);
    }

    private function cborAt(string $b, int &$o): mixed
    {
        if ($o >= strlen($b)) {
            $this->fail('Data CBOR terpotong.');
        }
        $ib = ord($b[$o++]);
        $major = $ib >> 5;
        $info = $ib & 0x1F;
        $len = match (true) {
            $info < 24 => $info,
            $info === 24 => ord($b[$o++]),
            $info === 25 => unpack('n', substr($b, ($o += 2) - 2, 2))[1],
            $info === 26 => unpack('N', substr($b, ($o += 4) - 4, 4))[1],
            $info === 27 => unpack('J', substr($b, ($o += 8) - 8, 8))[1],
            default => $this->fail('Format CBOR tidak didukung.'),
        };

        switch ($major) {
            case 0: return $len;
            case 1: return -1 - $len;
            case 2:
            case 3:
                $s = substr($b, $o, $len);
                $o += $len;

                return $s;
            case 4:
                $a = [];
                for ($i = 0; $i < $len; $i++) {
                    $a[] = $this->cborAt($b, $o);
                }

                return $a;
            case 5:
                $m = [];
                for ($i = 0; $i < $len; $i++) {
                    $key = $this->cborAt($b, $o);
                    $m[$key] = $this->cborAt($b, $o);
                }

                return $m;
            case 7:
                return match ($info) { 20 => false, 21 => true, 22, 23 => null, default => $this->fail('Nilai CBOR tidak didukung.') };
            default:
                $this->fail('Tipe CBOR tidak didukung.');
        }
    }

    private function fail(string $msg): never
    {
        throw ValidationException::withMessages(['passkey' => $msg]);
    }

    public static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64url(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($d === false) {
            throw ValidationException::withMessages(['passkey' => 'Data verifikasi rusak.']);
        }

        return $d;
    }
}
