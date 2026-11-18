<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 2FA super-admin. Password benar → (sudah terdaftar) masukkan kode 6 digit; (belum) daftarkan aplikasi authenticator.
 * Kode cadangan sekali pakai untuk HP hilang.
 */
class TwoFactorController extends Controller
{
    public const PENDING = 'panel.2fa.pending';

    public function __construct(private Totp $totp, private AuditLogger $audit) {}

    public function challenge(Request $request): View|RedirectResponse
    {
        return $request->session()->has(self::PENDING) ? view('panel.2fa-challenge') : redirect()->route('panel.login');
    }

    public function verify(Request $request): RedirectResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => 'kode']);
        $id = $request->session()->get(self::PENDING);
        $u = $id ? User::query()->where('role', Role::SuperAdmin->value)->find($id) : null;
        if (! $u || ! $u->two_factor_confirmed_at) {
            return redirect()->route('panel.login');
        }

        $code = strtolower(trim($d['code']));
        $step = $this->totp->verify((string) $u->two_factor_secret, $code, $u->two_factor_last_step);
        $recovery = $u->two_factor_recovery_codes ?? [];
        $usedRecovery = false;

        if ($step !== null) {
            $u->forceFill(['two_factor_last_step' => $step])->save();
        } elseif (in_array($code, $recovery, true)) {
            $u->forceFill(['two_factor_recovery_codes' => array_values(array_diff($recovery, [$code]))])->save();
            $usedRecovery = true;
        } else {
            return back()->withErrors(['code' => 'Kode salah atau sudah dipakai. Masukkan kode terbaru dari aplikasi authenticator.']);
        }

        $request->session()->forget(self::PENDING);
        Auth::login($u);
        $request->session()->regenerate();
        $u->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('panel.login', "{$u->name} masuk ke panel super-admin (2FA".($usedRecovery ? ', kode cadangan' : '').')', null, $u);

        return redirect()->intended(route('panel.schools'))
            ->with('ok', $usedRecovery ? 'Kamu masuk dengan kode cadangan. Sisa kode cadangan: '.count($u->two_factor_recovery_codes).'.' : null);
    }

    public function setup(Request $request): View|RedirectResponse
    {
        $u = $request->user();
        if ($u->two_factor_confirmed_at) {
            return redirect()->route('panel.schools');
        }
        $secret = $request->session()->get('panel.2fa.secret') ?? $this->totp->secret();
        $request->session()->put('panel.2fa.secret', $secret);
        $uri = $this->totp->uri($u->email ?: $u->username, $secret, 'MyPresence Panel');

        return view('panel.2fa-setup', ['secret' => trim(chunk_split($secret, 4, ' ')), 'qr' => $this->totp->qrSvg($uri)]);
    }

    public function confirm(Request $request): RedirectResponse|View
    {
        $request->validate(['code' => ['required', 'string', 'max:10']], [], ['code' => 'kode']);
        $secret = $request->session()->get('panel.2fa.secret');
        $step = $secret ? $this->totp->verify($secret, $request->input('code')) : null;
        if ($step === null) {
            return back()->withErrors(['code' => 'Kode tidak cocok. Pastikan jam HP otomatis, lalu masukkan kode terbaru.']);
        }

        $codes = $this->totp->recoveryCodes();
        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step, 'two_factor_recovery_codes' => $codes])->save();
        $request->session()->forget('panel.2fa.secret');
        $this->audit->log('2fa.enabled', "{$request->user()->name} mengaktifkan 2FA panel");

        return view('panel.2fa-recovery', ['codes' => $codes]);
    }
}
