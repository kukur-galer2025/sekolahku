<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use App\Services\ImageSanitizer;
use App\Services\Platform\Totp;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Tenancy\SchoolScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Profil super-admin di panel: data akun, ganti password, 2FA (kode cadangan / ganti HP),
 * sesi login aktif, dan riwayat aktivitas sendiri. Perubahan sensitif selalu meminta password saat ini.
 */
class ProfileController extends Controller
{
    public function __construct(private AuditLogger $audit, private Totp $totp) {}

    public function show(Request $request): View
    {
        $u = $request->user();

        return view('panel.profile', [
            'u' => $u,
            'sessions' => $this->sessions($request),
            'recoveryLeft' => count($u->two_factor_recovery_codes ?? []),
            'activity' => AuditLog::query()->withoutGlobalScope(SchoolScope::class)->with('school:id,name')->where('actor_id', $u->id)
                ->latest('created_at')->latest('id')->limit(12)->get(),
            'admins' => User::query()->withoutGlobalScopes()->whereNull('school_id')->where('role', Role::SuperAdmin->value)->orderBy('name')
                ->get(['id', 'name', 'username', 'photo_path', 'last_login_at', 'two_factor_confirmed_at', 'is_active']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $u = $request->user();
        $request->merge(['email' => strtolower(trim((string) $request->input('email'))) ?: null, 'username' => trim((string) $request->input('username'))]);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:4', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+ ()-]*$/'],
            'current_password' => ['nullable', 'string'],
        ], ['username.regex' => 'Username hanya huruf, angka, titik, garis bawah, atau strip.'],
            ['name' => 'nama', 'phone' => 'no. HP', 'current_password' => 'password saat ini']);

        // Username & email dipakai untuk login panel → harus unik di antara akun super-admin dan butuh konfirmasi password.
        $loginChanged = $d['username'] !== $u->username || ($d['email'] ?? null) !== ($u->email ?: null);
        if ($loginChanged) {
            $this->confirmPassword($request);
            $others = User::query()->withoutGlobalScopes()->whereNull('school_id')->where('role', Role::SuperAdmin->value)->where('id', '!=', $u->id);
            if ((clone $others)->where('username', $d['username'])->exists()) {
                throw ValidationException::withMessages(['username' => 'Username sudah dipakai super-admin lain.']);
            }
            if ($d['email'] && (clone $others)->where('email', $d['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'Email sudah dipakai super-admin lain.']);
            }
        }

        $old = $u->only(['name', 'username', 'email', 'phone']);
        $u->forceFill(['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'], 'phone' => ($d['phone'] ?? null) ?: null]);
        if ($u->isDirty('email')) {
            $u->email_verified_at = null;
        }
        $changed = array_keys($u->getDirty());
        $u->save();
        if ($changed) {
            $this->audit->log('account.profile_updated', "{$u->name} mengubah profil panel (".implode(', ', array_intersect(['name', 'username', 'email', 'phone'], $changed)).')',
                null, $u, ['before' => array_intersect_key($old, array_flip($changed))]);
        }

        return back()->with('ok', $changed ? 'Profil disimpan.'.($loginChanged ? ' Gunakan username/email baru saat login berikutnya.' : '') : 'Tidak ada perubahan.');
    }

    public function password(Request $request): RedirectResponse
    {
        $u = $request->user();
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], [], ['current_password' => 'password saat ini', 'password' => 'password baru']);
        $this->confirmPassword($request);
        if (Hash::check($request->input('password'), $u->password)) {
            throw ValidationException::withMessages(['password' => 'Password baru harus berbeda dari password sekarang.']);
        }

        $u->forceFill(['password' => $request->input('password'), 'must_change_password' => false])->save();
        $n = $this->logoutOthers($request);
        $this->audit->log('password.changed', "{$u->name} mengganti password panel".($n ? " · $n sesi lain dikeluarkan" : ''), null, $u);

        return back()->with('ok', 'Password diganti.'.($n ? " $n perangkat lain otomatis dikeluarkan." : ''));
    }

    /** Buat ulang 10 kode cadangan 2FA (kode lama tidak berlaku). */
    public function recoveryCodes(Request $request): View
    {
        $u = $request->user();
        abort_unless($u->two_factor_confirmed_at, 422, '2FA belum aktif.');
        $request->validate(['current_password' => ['required', 'string']], [], ['current_password' => 'password saat ini']);
        $this->confirmPassword($request);
        $codes = $this->totp->recoveryCodes(10); // sesuai janji di halaman profil: "10 kode baru"
        $u->forceFill(['two_factor_recovery_codes' => $codes])->save();
        $this->audit->log('2fa.recovery_regenerated', "{$u->name} membuat ulang kode cadangan 2FA panel", null, $u);

        return view('panel.2fa-recovery', ['codes' => $codes, 'regenerated' => true]);
    }

    /** Ganti HP / aplikasi authenticator: matikan 2FA lama lalu langsung ke halaman aktivasi. */
    public function resetTwoFactor(Request $request): RedirectResponse
    {
        $u = $request->user();
        $request->validate(['current_password' => ['required', 'string'], 'code' => ['required', 'string', 'max:20']], [],
            ['current_password' => 'password saat ini', 'code' => 'kode 2FA']);
        $this->confirmPassword($request);
        $code = strtolower(trim((string) $request->input('code')));
        $ok = $u->two_factor_confirmed_at && ($this->totp->verify((string) $u->two_factor_secret, $code, $u->two_factor_last_step) !== null
            || in_array($code, $u->two_factor_recovery_codes ?? [], true));
        if (! $ok) {
            throw ValidationException::withMessages(['code' => 'Kode 2FA salah. Pakai kode terbaru dari aplikasi lama, atau salah satu kode cadangan.']);
        }

        $u->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        $this->audit->log('2fa.reset', "{$u->name} mengatur ulang 2FA panel (ganti perangkat authenticator)", null, $u);

        return redirect()->route('panel.2fa.setup')->with('ok', '2FA lama dimatikan. Pindai QR berikut dengan aplikasi authenticator di HP baru.');
    }

    public function logoutOtherSessions(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string']], [], ['current_password' => 'password saat ini']);
        $this->confirmPassword($request);
        $n = $this->logoutOthers($request);
        $this->audit->log('panel.sessions_revoked', "{$request->user()->name} mengeluarkan $n sesi panel di perangkat lain", null, $request->user());

        return back()->with('ok', $n ? "$n perangkat lain dikeluarkan dari panel." : 'Tidak ada sesi lain yang aktif.');
    }

    /** Unggah foto profil (JPG saja). Disimpan privat di storage pusat, bukan folder sekolah. */
    public function uploadPhoto(Request $request, ImageSanitizer $images): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:2048']], ['photo.max' => 'Ukuran foto maksimal 2 MB.'], ['photo' => 'foto']);
        $ext = strtolower($request->file('photo')->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg'], true)) {
            throw ValidationException::withMessages(['photo' => 'Foto harus berformat JPG/JPEG.']);
        }
        try {
            $jpg = $images->jpeg($request->file('photo'), 512, 2 * 1024 * 1024);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['photo' => collect($e->errors())->flatten()->first()]);
        }

        $u = $request->user();
        $old = $u->photo_path;
        $path = 'panel/avatars/'.$u->id.'-'.Str::random(12).'.jpg';
        Storage::disk('local')->put($path, $jpg);
        $u->forceFill(['photo_path' => $path])->save();
        if ($old && str_starts_with($old, 'panel/avatars/')) {
            Storage::disk('local')->delete($old);
        }
        $this->audit->log('account.photo_updated', "{$u->name} mengganti foto profil panel", null, $u);

        return back()->with('ok', 'Foto profil diperbarui.');
    }

    public function deletePhoto(Request $request): RedirectResponse
    {
        $u = $request->user();
        if ($u->photo_path && str_starts_with($u->photo_path, 'panel/avatars/')) {
            Storage::disk('local')->delete($u->photo_path);
        }
        $u->forceFill(['photo_path' => null])->save();
        $this->audit->log('account.photo_deleted', "{$u->name} menghapus foto profil panel", null, $u);

        return back()->with('ok', 'Foto profil dihapus.');
    }

    /** Foto super-admin (hanya untuk super-admin yang login). */
    public function photo(int $user)
    {
        $u = User::query()->withoutGlobalScopes()->whereNull('school_id')->where('role', Role::SuperAdmin->value)->findOrFail($user);
        abort_unless($u->photo_path && str_starts_with($u->photo_path, 'panel/avatars/') && Storage::disk('local')->exists($u->photo_path), 404);

        return response()->file(Storage::disk('local')->path($u->photo_path), ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** URL foto dengan penanda versi (berubah tiap ganti foto) atau null. */
    public static function photoUrl(?User $u): ?string
    {
        return $u && $u->photo_path && str_starts_with($u->photo_path, 'panel/avatars/')
            ? route('panel.profile.photo', $u->id).'?v='.substr(md5($u->photo_path), 0, 8) : null;
    }

    // ── bantuan ──

    private function confirmPassword(Request $request): void
    {
        if (! Hash::check((string) $request->input('current_password'), $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini salah.']);
        }
    }

    /** Sesi tersimpan di tabel sessions (SESSION_DRIVER=database). Driver lain → daftar kosong. */
    private function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable(config('session.table', 'sessions'))) {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)->orderByDesc('last_activity')->limit(10)->get()
            ->map(fn ($s) => ['current' => $s->id === $request->session()->getId(), 'ip' => $s->ip_address, 'device' => self::device((string) $s->user_agent),
                'last' => \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity)->timezone('Asia/Jakarta')])->all();
    }

    private function logoutOthers(Request $request): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }
        $n = DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->user()->forceFill(['remember_token' => \Illuminate\Support\Str::random(60)])->save();

        return $n;
    }

    /** "Chrome · Windows" dari user agent (cukup untuk mengenali perangkat). */
    public static function device(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet', str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => 'perangkat lain',
        };

        return "$browser · $os";
    }
}
