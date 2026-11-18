<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Rules\AvailableSubdomain;
use App\Services\Platform\SchoolProvisioner;
use App\Tenancy\Subdomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Halaman depan mypresence.id dan pendaftaran sekolah mandiri (trial). */
class RegistrationController extends Controller
{
    public const TIMEZONES = ['Asia/Jakarta' => 'WIB (Asia/Jakarta)', 'Asia/Makassar' => 'WITA (Asia/Makassar)', 'Asia/Jayapura' => 'WIT (Asia/Jayapura)'];

    public function landing(): View
    {
        return view('central.landing');
    }

    /** "Masuk ke sekolah saya": arahkan ke subdomain sekolah. */
    public function go(Request $request): RedirectResponse
    {
        $sub = Subdomain::normalize((string) $request->input('subdomain'));
        $sub = Str::before(Str::after($sub, '://'), '.');
        $school = School::query()->where('subdomain', $sub)->first();

        return $school
            ? redirect()->away($school->url('masuk'))
            : back()->withInput()->withErrors(['subdomain' => 'Link portal "'.$sub.'" belum terdaftar.']);
    }

    public function form(): View
    {
        return view('central.register', ['timezones' => self::TIMEZONES]);
    }

    /** Cek ketersediaan subdomain saat mengetik (dipakai halaman daftar). */
    public function check(Request $request): JsonResponse
    {
        $v = Validator::make(['subdomain' => (string) $request->query('subdomain')], ['subdomain' => [new AvailableSubdomain]]);

        return response()->json(['available' => $v->passes(), 'message' => $v->errors()->first('subdomain') ?: 'Link tersedia.']);
    }

    public function store(Request $request, SchoolProvisioner $provisioner): RedirectResponse
    {
        // Jebakan bot: kolom tersembunyi harus kosong.
        abort_if(filled($request->input('website')), 422);

        $request->merge(['subdomain' => Subdomain::normalize((string) $request->input('subdomain'))]);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'npsn' => ['nullable', 'digits:8'],
            'address' => ['nullable', 'string', 'max:255'],
            'level' => ['nullable', Rule::in(array_keys(\App\Support\SchoolLevel::LABELS))],
            'subdomain' => ['required', new AvailableSubdomain],
            'timezone' => ['required', Rule::in(array_keys(self::TIMEZONES))],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'regex:/^\+?[0-9\s-]{8,16}$/'],
            'admin_password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'agree' => ['accepted'],
        ], [
            'agree.accepted' => 'Centang persetujuan syarat layanan & kebijakan privasi untuk melanjutkan.',
            'contact_phone.regex' => 'Nomor HP harus 8–16 digit.',
        ], [
            'name' => 'nama sekolah', 'subdomain' => 'link portal', 'address' => 'alamat sekolah', 'admin_name' => 'nama lengkap admin', 'admin_email' => 'email admin',
            'contact_phone' => 'nomor HP', 'admin_password' => 'password',
        ]);

        $out = $provisioner->create($d, 'self');
        foreach (['terms', 'privacy'] as $doc) {
            \App\Services\Platform\Legal::record($doc, $out['school'], $out['admin']);
        }

        return redirect()->route('central.registered')->with('registered', [
            'name' => $out['school']->name, 'url' => $out['school']->url('masuk'), 'email' => $out['admin']->email, 'code' => $out['school']->access_code,
        ]);
    }

    public function registered(Request $request): View|RedirectResponse
    {
        $r = $request->session()->get('registered');

        return $r ? view('central.registered', ['r' => $r]) : redirect()->route('central.register');
    }
}
