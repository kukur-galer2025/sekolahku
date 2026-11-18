<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\LoginService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function form(Request $request, TenantContext $tenant): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.login', ['school' => $tenant->get()]);
    }

    public function login(Request $request, LoginService $login): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
        ], [], ['username' => 'Username/NIK/NIS', 'password' => 'Password']);

        try {
            $user = $login->attempt($request, $data['username'], $data['password']);
        } catch (\App\Services\TwoFactorRequired) {
            return redirect()->route('portal.2fa');
        }

        return $this->afterLogin($user);
    }

    public function twoFactorForm(Request $request, TenantContext $tenant): View|RedirectResponse
    {
        return $request->session()->has(LoginService::PENDING)
            ? view('portal.2fa-challenge', ['school' => $tenant->get()])
            : redirect()->route('portal.login');
    }

    public function twoFactor(Request $request, LoginService $login): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => 'kode']);

        return $this->afterLogin($login->completeTwoFactor($request, $data['code']));
    }

    private function afterLogin(\App\Models\User $user): RedirectResponse
    {
        // Guru & siswa memakai aplikasi (PWA); portal Blade untuk tugas administrasi admin.
        if ($user->role !== \App\Enums\Role::Admin) {
            return redirect('/'.\App\Http\Controllers\Portal\PwaController::appForRole($user->role->value));
        }

        return redirect()->intended(route('portal.dashboard'));
    }

    public function logout(Request $request, LoginService $login): RedirectResponse
    {
        $login->logout($request);

        return redirect()->route('portal.login')->with('ok', 'Kamu sudah keluar.');
    }
}
