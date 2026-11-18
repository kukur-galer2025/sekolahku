<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\School;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Pengikatan akun ke perangkat.
 *
 * Browser tidak memberi ID perangkat keras, jadi saat login pertama server membuat token acak
 * yang disimpan di cookie perangkat (httpOnly, terenkripsi, hanya untuk subdomain sekolah itu).
 * Di database hanya hash-nya.
 *
 * Aturan (batas per peran di schools.settings.devices, bawaan: siswa 1, guru 2, admin bebas):
 * - Akun yang sudah mencapai batas perangkat tidak bisa login dari perangkat baru.
 * - Satu perangkat tidak bisa dipakai untuk dua akun siswa (menutup "titip absen" lewat satu HP).
 * - Scan QR hanya diterima dari perangkat terdaftar milik siswa itu.
 * - Ganti HP / data browser terhapus → admin atau wali kelas menekan "Reset perangkat".
 */
class DeviceBinding
{
    public const COOKIE = 'mp_device';

    private const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    public function limitFor(School $school, User $user): int
    {
        return match ($user->role) {
            Role::Student => max(1, (int) $school->setting('devices.student', 1)),
            Role::Teacher => max(1, (int) $school->setting('devices.teacher', 2)),
            default => 0, // 0 = tanpa batas
        };
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function tokenFrom(Request $request): ?string
    {
        $t = $request->cookie(self::COOKIE);

        return is_string($t) && strlen($t) >= 32 ? $t : null;
    }

    /**
     * Dipanggil setelah password benar, sebelum sesi dibuat.
     * Mengembalikan perangkat yang dipakai (atau null bila peran ini tanpa batas).
     */
    public function authorizeLogin(School $school, User $user, Request $request): ?UserDevice
    {
        $limit = $this->limitFor($school, $user);
        if ($limit === 0) {
            return null;
        }

        return DB::transaction(function () use ($school, $user, $request, $limit) {
            $token = $this->tokenFrom($request);
            $known = $token ? UserDevice::query()->active()->where('token_hash', $this->hash($token))->lockForUpdate()->first() : null;

            if ($known && $known->user_id === $user->id) {
                $known->forceFill(['last_seen_at' => now(), 'ip' => $request->ip()])->save();
                $this->queueCookie($token);

                return $known;
            }

            if ($known && $user->role === Role::Student && $known->user?->role === Role::Student) {
                throw ValidationException::withMessages(['username' => 'HP ini sudah terdaftar untuk akun siswa lain. Satu HP hanya untuk satu siswa.']);
            }

            $active = UserDevice::query()->active()->where('user_id', $user->id)->lockForUpdate()->count();
            if ($active >= $limit) {
                throw ValidationException::withMessages(['username' => $user->role === Role::Student
                    ? 'Akunmu sudah terdaftar di HP lain. Minta wali kelas atau admin untuk reset perangkat bila kamu ganti HP.'
                    : "Akun ini sudah terdaftar di {$limit} perangkat. Minta admin untuk reset perangkat."]);
            }

            // Token baru bila belum ada, bila milik akun lain (mis. PC ruang guru), atau bila pernah
            // tercatat lalu dicabut (HP lama setelah reset). Token tidak pernah dipakai ulang.
            if (! $token || $known || UserDevice::query()->withoutGlobalScopes()->where('token_hash', $this->hash($token))->exists()) {
                $token = Str::random(64);
            }

            $device = UserDevice::create([
                'user_id' => $user->id,
                'token_hash' => $this->hash($token),
                'name' => $this->describe((string) $request->userAgent()),
                'ip' => $request->ip(),
                'last_seen_at' => now(),
            ]);
            $this->queueCookie($token);

            return $device;
        });
    }

    /** Perangkat aktif milik user ini yang sedang dipakai, atau null. */
    public function current(User $user, Request $request): ?UserDevice
    {
        $token = $this->tokenFrom($request);
        if (! $token) {
            return null;
        }

        return UserDevice::query()->active()->where('user_id', $user->id)->where('token_hash', $this->hash($token))->first();
    }

    /** Wajib untuk scan QR (siswa & guru). */
    public function requireForScan(User $user, Request $request): UserDevice
    {
        $device = $this->current($user, $request);

        if (! $device) {
            $who = $user->role === \App\Enums\Role::Teacher ? 'admin sekolah' : 'wali kelas';
            throw ValidationException::withMessages(['code' => "Scan hanya bisa dari HP yang terdaftar untuk akunmu. Keluar lalu masuk lagi dari HP-mu sendiri, atau minta $who untuk reset perangkat."]);
        }

        $device->forceFill(['last_seen_at' => now(), 'ip' => $request->ip()])->save();

        return $device;
    }

    /** Reset semua perangkat akun (ganti HP). Sesi yang masih berjalan ikut diputus. */
    public function revokeAll(User $user, User $by): int
    {
        $n = UserDevice::query()->active()->where('user_id', $user->id)->update(['revoked_at' => now(), 'revoked_by' => $by->id]);

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        return $n;
    }

    private function queueCookie(string $token): void
    {
        Cookie::queue(Cookie::make(self::COOKIE, $token, self::COOKIE_MINUTES, '/', null, config('session.secure'), true, false, SymfonyCookie::SAMESITE_LAX));
    }

    private function describe(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Perangkat lain',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Browser',
        };

        return $os.' · '.$browser;
    }
}
