<?php

namespace Tests\Support;

use App\Services\Platform\WebAuthn;

/** Authenticator palsu (seperti sensor sidik jari HP) untuk menguji alur passkey dengan kunci P-256 sungguhan. */
class FakeAuthenticator
{
    private \OpenSSLAsymmetricKey $key;

    public string $credentialId;

    public int $counter = 0;

    public function __construct(private string $host, private string $origin)
    {
        $this->key = openssl_pkey_new(\App\Services\Notify\WebPush::ecKeyOptions());
        $this->credentialId = random_bytes(32);
    }

    /** Tanggapan navigator.credentials.create() untuk tantangan pendaftaran. */
    public function register(string $challenge): array
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $cose = self::map([1 => 2, 3 => -7, -1 => 1, -2 => self::bytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)), -3 => self::bytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))]);
        $auth = hash('sha256', $this->host, true).chr(0x45).pack('N', 0).str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$cose;
        $att = self::map([self::text('fmt') => self::text('none'), self::text('attStmt') => self::map([]), self::text('authData') => self::bytes($auth)], true);

        return ['clientDataJSON' => WebAuthn::b64url($this->clientData('webauthn.create', $challenge)), 'attestationObject' => WebAuthn::b64url($att)];
    }

    /** Tanggapan navigator.credentials.get() untuk tantangan masuk. */
    public function assert(string $challenge, bool $verified = true): array
    {
        $cd = $this->clientData('webauthn.get', $challenge);
        $auth = hash('sha256', $this->host, true).chr($verified ? 0x05 : 0x01).pack('N', ++$this->counter);
        openssl_sign($auth.hash('sha256', $cd, true), $sig, $this->key, OPENSSL_ALGO_SHA256);

        return ['id' => WebAuthn::b64url($this->credentialId), 'clientDataJSON' => WebAuthn::b64url($cd),
            'authenticatorData' => WebAuthn::b64url($auth), 'signature' => WebAuthn::b64url($sig)];
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $this->origin]);
    }

    // CBOR minimal: kunci/nilai sudah berupa potongan CBOR (int dienkode otomatis).
    private static function head(int $major, int $n): string
    {
        return $n < 24 ? chr(($major << 5) | $n) : ($n < 256 ? chr(($major << 5) | 24).chr($n) : chr(($major << 5) | 25).pack('n', $n));
    }

    private static function int(int $v): string
    {
        return $v >= 0 ? self::head(0, $v) : self::head(1, -1 - $v);
    }

    private static function bytes(string $s): string
    {
        return self::head(2, strlen($s)).$s;
    }

    private static function text(string $s): string
    {
        return self::head(3, strlen($s)).$s;
    }

    private static function map(array $kv, bool $encodedKeys = false): string
    {
        $o = self::head(5, count($kv));
        foreach ($kv as $k => $v) {
            $o .= ($encodedKeys ? $k : self::int($k)).(is_int($v) ? self::int($v) : $v);
        }

        return $o;
    }
}
