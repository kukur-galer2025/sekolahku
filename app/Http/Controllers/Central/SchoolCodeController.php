<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Satu pintu masuk guru & siswa di domain pusat: {domain}/app, /guru, /siswa.
 * Pengguna mengetik kode sekolah, lalu diantar ke aplikasi sekolah itu ({subdomain}/guru atau /siswa)
 * untuk login dengan NIK/NIS + password. Pencarian akun tetap hanya di dalam sekolah tersebut,
 * jadi NIK/NIS yang sama di sekolah lain tidak pernah tertukar.
 */
class SchoolCodeController extends Controller
{
    public const APPS = ['siswa' => 'Siswa', 'guru' => 'Guru', 'admin' => 'Admin'];

    /** /app (pilih sendiri), /siswa, /guru, /admin (sudah terpilih). */
    public function form(string $app = 'app'): View
    {
        return view('central.code', ['app' => array_key_exists($app, self::APPS) ? $app : null, 'apps' => self::APPS]);
    }

    public function go(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'app' => ['required', Rule::in(array_keys(self::APPS))],
        ], ['app.required' => 'Pilih masuk sebagai Siswa atau Guru.'], ['code' => 'kode sekolah']);

        $school = School::findByAccessCode($d['code']);
        if (! $school) {
            return back()->withInput()->withErrors(['code' => 'Kode sekolah tidak ditemukan. Periksa lagi kode dari wali kelas atau admin sekolah.']);
        }

        return redirect()->away($school->url($d['app']));
    }
}
