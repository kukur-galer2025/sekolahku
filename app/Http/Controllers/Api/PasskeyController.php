<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\PwaController;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\LoginService;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\WebAuthn;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Login sidik jari / Face ID (passkey WebAuthn) untuk admin, guru, dan siswa.
 * Daftar dari Profil (sudah login) → masuk dari layar login tanpa password.
 */
class PasskeyController extends Controller
{
    private const MAX_PER_USER = 5;

    private const CHALLENGE = 'passkey.challenge';

    public function __construct(private WebAuthn $webauthn, private TenantContext $tenant, private AuditLogger $audit) {}

    /** GET /api/passkeys — HP yang sudah didaftarkan akun ini. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(WebauthnCredential::query()->where('user_id', $request->user()->id)->latest()->get()
            ->map(fn ($c) => ['id' => $c->id, 'credential_id' => $c->credential_id, 'name' => $c->name, 'created_at' => $c->created_at?->toIso8601String(), 'last_used_at' => $c->last_used_at?->toIso8601String()]));
    }

    /** POST /api/passkeys/options — tantangan pendaftaran. */
    public function registerOptions(Request $request): JsonResponse
    {
        $u = $request->user();
        $existing = WebauthnCredential::query()->where('user_id', $u->id)->get(['credential_id', 'user_handle']);
        if ($existing->count() >= self::MAX_PER_USER) {
            throw ValidationException::withMessages(['passkey' => 'Maksimal '.self::MAX_PER_USER.' HP. Hapus HP lama dulu di daftar.']);
        }
        $challenge = WebAuthn::challenge();
        $handle = $existing->first()?->user_handle ?? WebAuthn::b64url(random_bytes(16));
        $request->session()->put(self::CHALLENGE, ['c' => $challenge, 'at' => time(), 'handle' => $handle, 'kind' => 'create']);
        $school = $this->tenant->get();

        return response()->json(['publicKey' => [
            'challenge' => $challenge,
            'rp' => ['id' => WebAuthn::rpId($request), 'name' => 'MyPresence · '.$school->name],
            'user' => ['id' => $handle, 'name' => $u->username ?: (string) $u->id, 'displayName' => $u->name],
            'pubKeyCredParams' => array_map(fn ($a) => ['type' => 'public-key', 'alg' => $a], WebAuthn::ALGS),
            'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'preferred'],
            'attestation' => 'none',
            'timeout' => 60000,
            'excludeCredentials' => $existing->map(fn ($c) => ['type' => 'public-key', 'id' => $c->credential_id])->values(),
        ]]);
    }

    /** POST /api/passkeys — simpan kunci publik HP ini. */
    public function register(Request $request): JsonResponse
    {
        $d = $request->validate([
            'clientDataJSON' => ['required', 'string', 'max:4096'],
            'attestationObject' => ['required', 'string', 'max:16384'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);
        $ch = $this->takeChallenge($request, 'create');
        $r = $this->webauthn->verifyRegistration($request, $ch['c'], $d['clientDataJSON'], $d['attestationObject']);
        if (WebauthnCredential::query()->withoutGlobalScopes()->where('credential_hash', WebauthnCredential::hashId($r['credential_id']))->exists()) {
            throw ValidationException::withMessages(['passkey' => 'Sidik jari HP ini sudah terdaftar.']);
        }
        $u = $request->user();
        $cred = WebauthnCredential::create([
            'user_id' => $u->id, 'credential_id' => $r['credential_id'], 'credential_hash' => WebauthnCredential::hashId($r['credential_id']),
            'public_key' => $r['public_key'], 'alg' => $r['alg'], 'sign_count' => $r['sign_count'], 'user_handle' => $ch['handle'],
            'name' => ($d['name'] ?? null) ?: 'HP', 'last_used_at' => now(),
        ]);
        $this->audit->log('passkey.registered', "{$u->name} mendaftarkan login sidik jari ({$cred->name})", $this->tenant->get(), $u);

        return response()->json(['message' => 'Login sidik jari aktif di HP ini.', 'id' => $cred->id, 'credential_id' => $cred->credential_id], 201);
    }

    /** DELETE /api/passkeys/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $c = WebauthnCredential::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $c->delete();
        $this->audit->log('passkey.removed', "{$request->user()->name} menghapus login sidik jari ({$c->name})", $this->tenant->get(), $request->user());

        return response()->json(['message' => 'Login sidik jari dihapus dari daftar.']);
    }

    /** POST /api/auth/passkey/options (belum login) — tantangan masuk. */
    public function loginOptions(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['nullable', 'array', 'max:10'], 'ids.*' => ['string', 'max:512']]);
        $challenge = WebAuthn::challenge();
        $request->session()->put(self::CHALLENGE, ['c' => $challenge, 'at' => time(), 'kind' => 'get']);

        return response()->json(['publicKey' => [
            'challenge' => $challenge,
            'rpId' => WebAuthn::rpId($request),
            'userVerification' => 'required',
            'timeout' => 60000,
            'allowCredentials' => collect($d['ids'] ?? [])->map(fn ($id) => ['type' => 'public-key', 'id' => $id, 'transports' => ['internal', 'hybrid']])->values(),
        ]]);
    }

    /** POST /api/auth/passkey (belum login) — verifikasi tanda tangan lalu buat sesi. */
    public function login(Request $request, LoginService $login): JsonResponse
    {
        $d = $request->validate([
            'id' => ['required', 'string', 'max:512'],
            'clientDataJSON' => ['required', 'string', 'max:4096'],
            'authenticatorData' => ['required', 'string', 'max:4096'],
            'signature' => ['required', 'string', 'max:2048'],
            'userHandle' => ['nullable', 'string', 'max:256'],
            'app' => ['nullable', 'string', Rule::in(array_keys(PwaController::APPS))],
        ]);
        $ch = $this->takeChallenge($request, 'get');
        // Terfilter sekolah ini (global scope): passkey sekolah lain tidak dikenali.
        $cred = WebauthnCredential::query()->where('credential_hash', WebauthnCredential::hashId($d['id']))->first();
        if (! $cred || ! hash_equals($cred->credential_id, $d['id'])) {
            throw ValidationException::withMessages(['passkey' => 'Sidik jari ini belum terdaftar di sekolah ini. Masuk dengan password, lalu aktifkan di Profil.']);
        }
        if (! empty($d['userHandle']) && ! hash_equals($cred->user_handle, $d['userHandle'])) {
            throw ValidationException::withMessages(['passkey' => 'Data akun tidak cocok.']);
        }
        $count = $this->webauthn->verifyAssertion($request, $ch['c'], $d['clientDataJSON'], $d['authenticatorData'], $d['signature'], $cred->public_key, $cred->sign_count);
        $user = User::query()->find($cred->user_id);
        if (! $user) {
            throw ValidationException::withMessages(['passkey' => 'Akun tidak ditemukan.']);
        }
        $roles = ! empty($d['app']) ? PwaController::APPS[$d['app']]['roles'] : null;
        $user = $login->attemptPasskey($request, $user, $roles);
        $cred->forceFill(['sign_count' => $count, 'last_used_at' => now()])->save();

        return response()->json(['user' => app(AuthController::class)->presentUser($user)]);
    }

    /** Tantangan sekali pakai, berlaku 5 menit. */
    private function takeChallenge(Request $request, string $kind): array
    {
        $ch = $request->session()->pull(self::CHALLENGE);
        if (! is_array($ch) || ($ch['kind'] ?? '') !== $kind || time() - (int) ($ch['at'] ?? 0) > 300) {
            throw ValidationException::withMessages(['passkey' => 'Sesi verifikasi berakhir. Coba lagi.']);
        }

        return $ch;
    }
}
