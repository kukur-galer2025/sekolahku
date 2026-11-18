<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function form(Request $request): View|RedirectResponse
    {
        return $request->user()?->role === Role::SuperAdmin ? redirect()->route('panel.schools') : view('panel.login');
    }

    public function login(Request $request, AuditLogger $audit): RedirectResponse
    {
        $d = $request->validate(['username' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);

        // Di domain pusat tidak ada tenant: User::query() hanya melihat akun tanpa sekolah (super-admin).
        $u = User::query()->where('role', Role::SuperAdmin->value)
            ->where(fn ($q) => $q->where('username', $d['username'])->orWhere('email', strtolower($d['username'])))->first();

        if (! $u) {
            Hash::make($d['password']); // samakan waktu respons: username super-admin tidak bisa ditebak dari lamanya jawaban
        }
        if (! $u || ! $u->is_active || ! Hash::check($d['password'], $u->password)) {
            throw ValidationException::withMessages(['username' => 'Username atau password salah.']);
        }

        // 2FA: kode dari aplikasi authenticator diminta sebelum sesi panel dibuat.
        if ($u->two_factor_confirmed_at) {
            $request->session()->regenerate();
            $request->session()->put(TwoFactorController::PENDING, $u->id);

            return redirect()->route('panel.2fa');
        }

        Auth::login($u);
        $request->session()->regenerate();
        $u->forceFill(['last_login_at' => now()])->save();
        $audit->log('panel.login', "{$u->name} masuk ke panel super-admin".(config('tenancy.superadmin_2fa') ? ' (perlu aktivasi 2FA)' : ''), null, $u);

        return redirect()->intended(route('panel.schools'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login');
    }
}
