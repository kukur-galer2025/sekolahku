<?php

use App\Http\Controllers\Central\RegistrationController;
use App\Http\Controllers\Panel\AuthController as PanelAuth;
use App\Http\Controllers\Panel\BillingController as PanelBilling;
use App\Http\Controllers\Panel\SchoolController;
use App\Http\Controllers\Panel\TwoFactorController as TwoFactor;
use Illuminate\Support\Facades\Route;

/*
| Domain pusat: mypresence.id
| Halaman depan, pendaftaran sekolah mandiri, dan panel super-admin Parama Tech.
*/

Route::get('/', [RegistrationController::class, 'landing'])->name('central.home');

// Monitor uptime: /health?token=… (HEALTH_TOKEN di .env). Tanpa token yang benar → 404.
Route::get('health', function (\Illuminate\Http\Request $r, \App\Services\Ops\HealthCheck $h) {
    $token = (string) config('app.health_token');
    abort_unless($token !== '' && hash_equals($token, (string) $r->query('token')), 404);
    $res = $h->run();

    return response()->json($res, $res['status'] === 'ok' ? 200 : 503)->header('Cache-Control', 'no-store');
})->middleware('throttle:30,1')->name('health');
Route::post('masuk-sekolah', [RegistrationController::class, 'go'])->name('central.go');

// Satu pintu masuk guru & siswa: kode sekolah → aplikasi sekolah itu → NIK/NIS + password.
// Batas 120/menit per IP: satu kelas di WiFi sekolah (satu IP publik) bisa masuk bersamaan.
Route::get('{app}', [\App\Http\Controllers\Central\SchoolCodeController::class, 'form'])
    ->whereIn('app', ['app', 'siswa', 'guru', 'admin'])->name('central.code');
Route::post('kode-sekolah', [\App\Http\Controllers\Central\SchoolCodeController::class, 'go'])
    ->middleware('throttle:120,1')->name('central.code.go');
Route::get('daftar', [RegistrationController::class, 'form'])->name('central.register');
Route::post('daftar', [RegistrationController::class, 'store'])->middleware('throttle:5,10');
Route::get('daftar/cek', [RegistrationController::class, 'check'])->middleware('throttle:60,1')->name('central.register.check');
Route::get('daftar/selesai', [RegistrationController::class, 'registered'])->name('central.registered');

// Notifikasi pembayaran gateway (tanpa CSRF; keaslian dicek lewat tanda tangan/token + cek ulang status ke API gateway).
Route::post('webhooks/{gateway}', \App\Http\Controllers\Central\PaymentWebhookController::class)
    ->whereIn('gateway', ['midtrans', 'xendit'])->middleware('throttle:120,1')->name('webhooks.payment');

Route::get('{slug}', [\App\Http\Controllers\Central\LegalController::class, 'show'])
    ->whereIn('slug', ['syarat-layanan', 'kebijakan-privasi', 'perjanjian-pemrosesan-data'])->name('legal.show');

Route::prefix('panel')->middleware('panel.ip')->group(function () {
    // PWA panel (tanpa login: manifest, service worker, halaman offline)
    Route::get('manifest.webmanifest', [\App\Http\Controllers\Panel\PwaController::class, 'manifest'])->name('panel.pwa.manifest');
    Route::get('sw.js', [\App\Http\Controllers\Panel\PwaController::class, 'serviceWorker'])->name('panel.pwa.sw');
    Route::get('offline', [\App\Http\Controllers\Panel\PwaController::class, 'offline'])->name('panel.pwa.offline');
    Route::get('masuk', [PanelAuth::class, 'form'])->name('panel.login');
    Route::post('masuk', [PanelAuth::class, 'login'])->middleware('throttle:login');
    Route::get('2fa', [TwoFactor::class, 'challenge'])->name('panel.2fa');
    Route::post('2fa', [TwoFactor::class, 'verify'])->middleware('throttle:6,1');

    Route::middleware('role:superadmin')->group(function () {
        Route::post('keluar', [PanelAuth::class, 'logout'])->name('panel.logout');
        Route::get('2fa/aktifkan', [TwoFactor::class, 'setup'])->name('panel.2fa.setup');
        Route::post('2fa/aktifkan', [TwoFactor::class, 'confirm'])->middleware('throttle:6,1');
    });

    Route::middleware(['role:superadmin', 'panel.2fa'])->group(function () {
        Route::get('/', [SchoolController::class, 'index'])->name('panel.schools');
        Route::get('sekolah/baru', [SchoolController::class, 'create'])->name('panel.schools.create');
        Route::post('sekolah', [SchoolController::class, 'store'])->name('panel.schools.store');
        Route::get('sekolah/{school}', [SchoolController::class, 'show'])->whereNumber('school')->name('panel.schools.show');
        Route::put('sekolah/{school}', [SchoolController::class, 'update'])->whereNumber('school')->name('panel.schools.update');
        Route::post('sekolah/{school}/kode', [SchoolController::class, 'code'])->whereNumber('school')->middleware('throttle:20,1')->name('panel.schools.code');
        Route::post('sekolah/{school}/verifikasi', [SchoolController::class, 'verify'])->whereNumber('school')->name('panel.schools.verify');
        Route::post('sekolah/{school}/tangguhkan', [SchoolController::class, 'suspend'])->whereNumber('school')->name('panel.schools.suspend');
        Route::post('sekolah/{school}/aktifkan', [SchoolController::class, 'activate'])->whereNumber('school')->name('panel.schools.activate');
        Route::post('sekolah/{school}/reset-admin', [SchoolController::class, 'resetAdmin'])->whereNumber('school')->name('panel.schools.reset-admin');
        Route::post('sekolah/{school}/bantuan', [SchoolController::class, 'impersonate'])->whereNumber('school')->middleware('throttle:10,1')->name('panel.schools.impersonate');
        Route::get('log', [SchoolController::class, 'logs'])->name('panel.logs');
        Route::get('log/unduh', [SchoolController::class, 'exportLogs'])->name('panel.logs.export');
        // Tahap 7 — operasional
        Route::get('operasional', [\App\Http\Controllers\Panel\OpsController::class, 'index'])->name('panel.ops');
        Route::post('operasional/backup', [\App\Http\Controllers\Panel\OpsController::class, 'backupNow'])->middleware('throttle:3,10')->name('panel.ops.backup');
        Route::get('operasional/backup/{name}', [\App\Http\Controllers\Panel\OpsController::class, 'download'])->where('name', 'db-\d{8}-\d{6}\.zip')->name('panel.ops.download');
        Route::post('operasional/ssl', [\App\Http\Controllers\Panel\OpsController::class, 'checkSsl'])->middleware('throttle:6,1')->name('panel.ops.ssl');
        Route::post('sekolah/{school}/backup', [SchoolController::class, 'backup'])->whereNumber('school')->middleware('throttle:6,1')->name('panel.schools.backup');
        Route::get('sekolah/{school}/backup/{name}', [SchoolController::class, 'downloadBackup'])->whereNumber('school')->where('name', '\d{8}-\d{6}-[a-z0-9]{4}\.zip')->name('panel.schools.backup.download');
        Route::get('paket', [\App\Http\Controllers\Panel\PlanController::class, 'index'])->name('panel.plans');
        Route::put('paket', [\App\Http\Controllers\Panel\PlanController::class, 'update'])->middleware('throttle:20,1')->name('panel.plans.update');
        Route::get('paket/template-menu', [\App\Http\Controllers\Panel\MenuController::class, 'templates'])->name('panel.menu.templates');
        Route::post('paket/template-menu', [\App\Http\Controllers\Panel\MenuController::class, 'saveTemplate'])->middleware('throttle:30,1')->name('panel.menu.templates.store');
        Route::put('paket/template-menu/{template}', [\App\Http\Controllers\Panel\MenuController::class, 'saveTemplate'])->whereNumber('template')->middleware('throttle:30,1')->name('panel.menu.templates.update');
        Route::delete('paket/template-menu/{template}', [\App\Http\Controllers\Panel\MenuController::class, 'destroyTemplate'])->whereNumber('template')->name('panel.menu.templates.destroy');
        Route::put('sekolah/{school}/menu', [\App\Http\Controllers\Panel\MenuController::class, 'saveSchool'])->whereNumber('school')->middleware('throttle:30,1')->name('panel.schools.menu');
        Route::put('paket/fitur', [\App\Http\Controllers\Panel\PlanController::class, 'updateFeatures'])->middleware('throttle:20,1')->name('panel.plans.features');
        Route::get('tagihan', [PanelBilling::class, 'index'])->name('panel.billing');
        Route::get('tagihan/{invoice}', [PanelBilling::class, 'show'])->whereNumber('invoice')->name('panel.billing.show');
        Route::get('tagihan/{invoice}/cetak', [PanelBilling::class, 'print'])->whereNumber('invoice')->name('panel.billing.print');
        Route::post('tagihan/{invoice}/lunas', [PanelBilling::class, 'markPaid'])->whereNumber('invoice')->name('panel.billing.paid');
        Route::post('tagihan/{invoice}/pembayaran', [PanelBilling::class, 'storePayment'])->whereNumber('invoice')->middleware('throttle:30,1')->name('panel.billing.payments.store');
        Route::delete('tagihan/{invoice}/pembayaran/{payment}', [PanelBilling::class, 'destroyPayment'])->whereNumber(['invoice', 'payment'])->name('panel.billing.payments.destroy');
        Route::post('tagihan/{invoice}/batal', [PanelBilling::class, 'void'])->whereNumber('invoice')->name('panel.billing.void');
        Route::post('tagihan/{invoice}/cek', [PanelBilling::class, 'check'])->whereNumber('invoice')->middleware('throttle:10,1')->name('panel.billing.check');
        Route::post('sekolah/{school}/tagihan', [PanelBilling::class, 'store'])->whereNumber('school')->name('panel.billing.store');
        // Profil super-admin
        Route::get('profil', [\App\Http\Controllers\Panel\ProfileController::class, 'show'])->name('panel.profile');
        Route::put('profil', [\App\Http\Controllers\Panel\ProfileController::class, 'update'])->middleware('throttle:10,1')->name('panel.profile.update');
        Route::put('profil/password', [\App\Http\Controllers\Panel\ProfileController::class, 'password'])->middleware('throttle:6,1')->name('panel.profile.password');
        Route::post('profil/2fa/kode-cadangan', [\App\Http\Controllers\Panel\ProfileController::class, 'recoveryCodes'])->middleware('throttle:6,1')->name('panel.profile.recovery');
        Route::post('profil/2fa/atur-ulang', [\App\Http\Controllers\Panel\ProfileController::class, 'resetTwoFactor'])->middleware('throttle:6,1')->name('panel.profile.2fa_reset');
        Route::post('profil/foto', [\App\Http\Controllers\Panel\ProfileController::class, 'uploadPhoto'])->middleware('throttle:10,1')->name('panel.profile.photo.upload');
        Route::delete('profil/foto', [\App\Http\Controllers\Panel\ProfileController::class, 'deletePhoto'])->name('panel.profile.photo.delete');
        Route::get('foto/{user}', [\App\Http\Controllers\Panel\ProfileController::class, 'photo'])->whereNumber('user')->name('panel.profile.photo');
        Route::post('profil/sesi/keluarkan', [\App\Http\Controllers\Panel\ProfileController::class, 'logoutOtherSessions'])->middleware('throttle:6,1')->name('panel.profile.sessions');
        // Keuangan: ringkasan, buku kas, mutasi bank
        Route::get('keuangan', [\App\Http\Controllers\Panel\FinanceController::class, 'dashboard'])->name('panel.finance');
        Route::get('keuangan/kas', [\App\Http\Controllers\Panel\FinanceController::class, 'ledger'])->name('panel.finance.ledger');
        Route::get('keuangan/kas/unduh', [\App\Http\Controllers\Panel\FinanceController::class, 'exportLedger'])->name('panel.finance.ledger.export');
        Route::post('keuangan/kas', [\App\Http\Controllers\Panel\FinanceController::class, 'storeEntry'])->middleware('throttle:60,1')->name('panel.finance.entries.store');
        Route::put('keuangan/kas/{entry}', [\App\Http\Controllers\Panel\FinanceController::class, 'storeEntry'])->whereNumber('entry')->middleware('throttle:60,1')->name('panel.finance.entries.update');
        Route::delete('keuangan/kas/{entry}', [\App\Http\Controllers\Panel\FinanceController::class, 'destroyEntry'])->whereNumber('entry')->name('panel.finance.entries.destroy');
        Route::get('keuangan/mutasi', [\App\Http\Controllers\Panel\FinanceController::class, 'mutations'])->name('panel.finance.mutations');
        Route::post('keuangan/mutasi/impor', [\App\Http\Controllers\Panel\FinanceController::class, 'importMutations'])->middleware('throttle:10,1')->name('panel.finance.mutations.import');
        Route::post('keuangan/mutasi/cocokkan-semua', [\App\Http\Controllers\Panel\FinanceController::class, 'matchAll'])->middleware('throttle:10,1')->name('panel.finance.mutations.match_all');
        Route::post('keuangan/mutasi/{mutation}/cocokkan', [\App\Http\Controllers\Panel\FinanceController::class, 'matchMutation'])->whereNumber('mutation')->middleware('throttle:60,1')->name('panel.finance.mutations.match');
        Route::post('keuangan/mutasi/{mutation}/catat', [\App\Http\Controllers\Panel\FinanceController::class, 'recordMutation'])->whereNumber('mutation')->middleware('throttle:60,1')->name('panel.finance.mutations.record');
        Route::post('keuangan/mutasi/{mutation}/abaikan', [\App\Http\Controllers\Panel\FinanceController::class, 'ignoreMutation'])->whereNumber('mutation')->name('panel.finance.mutations.ignore');
        // Penggajian karyawan ParamaEdu.Tech
        Route::get('penggajian', [\App\Http\Controllers\Panel\PayrollController::class, 'index'])->name('panel.payroll');
        Route::post('penggajian', [\App\Http\Controllers\Panel\PayrollController::class, 'store'])->middleware('throttle:20,1')->name('panel.payroll.store');
        Route::get('penggajian/karyawan', [\App\Http\Controllers\Panel\PayrollController::class, 'employees'])->name('panel.payroll.employees');
        Route::post('penggajian/karyawan', [\App\Http\Controllers\Panel\PayrollController::class, 'saveEmployee'])->middleware('throttle:60,1')->name('panel.payroll.employees.store');
        Route::put('penggajian/karyawan/{employee}', [\App\Http\Controllers\Panel\PayrollController::class, 'saveEmployee'])->whereNumber('employee')->middleware('throttle:60,1')->name('panel.payroll.employees.update');
        Route::delete('penggajian/karyawan/{employee}', [\App\Http\Controllers\Panel\PayrollController::class, 'destroyEmployee'])->whereNumber('employee')->name('panel.payroll.employees.destroy');
        Route::get('penggajian/{run}', [\App\Http\Controllers\Panel\PayrollController::class, 'show'])->whereNumber('run')->name('panel.payroll.show');
        Route::put('penggajian/{run}', [\App\Http\Controllers\Panel\PayrollController::class, 'update'])->whereNumber('run')->middleware('throttle:60,1')->name('panel.payroll.update');
        Route::delete('penggajian/{run}', [\App\Http\Controllers\Panel\PayrollController::class, 'destroy'])->whereNumber('run')->name('panel.payroll.destroy');
        Route::post('penggajian/{run}/karyawan', [\App\Http\Controllers\Panel\PayrollController::class, 'addItems'])->whereNumber('run')->name('panel.payroll.items.add');
        Route::delete('penggajian/{run}/baris/{item}', [\App\Http\Controllers\Panel\PayrollController::class, 'removeItem'])->whereNumber(['run', 'item'])->name('panel.payroll.items.remove');
        Route::post('penggajian/{run}/impor', [\App\Http\Controllers\Panel\PayrollController::class, 'import'])->whereNumber('run')->middleware('throttle:10,1')->name('panel.payroll.import');
        Route::get('penggajian/{run}/unduh', [\App\Http\Controllers\Panel\PayrollController::class, 'export'])->whereNumber('run')->name('panel.payroll.export');
        Route::get('penggajian/{run}/slip/{item?}', [\App\Http\Controllers\Panel\PayrollController::class, 'slip'])->whereNumber(['run', 'item'])->name('panel.payroll.slip');
        Route::delete('sekolah/{school}', [SchoolController::class, 'destroy'])->whereNumber('school')->name('panel.schools.destroy');
    });
});
