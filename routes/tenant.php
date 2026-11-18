<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AssignmentController;
use App\Http\Controllers\Api\AttendanceSettingsController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Portal\AccountController;
use App\Http\Controllers\Portal\AuthController as PortalAuth;
use App\Http\Controllers\Portal\BillingController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DataController;
use App\Http\Controllers\Portal\ImportController;
use App\Http\Controllers\Portal\PrivacyController;
use Illuminate\Support\Facades\Route;

/*
| Rute sekolah: {tenant}.mypresence.id
| Middleware 'tenant' sudah dipasang di bootstrap/app.php untuk seluruh file ini.
*/

/*
| Portal HTML (Blade) — tampilan sementara untuk mengecek portal sebelum PWA (tahap 7).
*/
// PWA (admin, guru, siswa): satu pintu di /app. Portal Blade tetap untuk impor, langganan, privasi.
Route::get('/', fn () => redirect('/app'))->name('tenant.home');
Route::get('app', [\App\Http\Controllers\Portal\PwaController::class, 'shell'])->name('pwa');
Route::get('manifest.webmanifest', [\App\Http\Controllers\Portal\PwaController::class, 'manifest'])->name('pwa.manifest');
// Tiga aplikasi terpisah (ikon & manifest sendiri): /admin, /guru, /siswa.
Route::get('{app}', [\App\Http\Controllers\Portal\PwaController::class, 'shell'])->whereIn('app', ['admin', 'guru', 'siswa'])->name('pwa.app');
Route::get('{app}/manifest.webmanifest', [\App\Http\Controllers\Portal\PwaController::class, 'manifest'])->whereIn('app', ['admin', 'guru', 'siswa'])->name('pwa.app.manifest');
Route::get('masuk', [PortalAuth::class, 'form'])->name('portal.login');
Route::post('masuk', [PortalAuth::class, 'login'])->middleware('throttle:login');
Route::get('masuk/2fa', [PortalAuth::class, 'twoFactorForm'])->name('portal.2fa');
Route::post('masuk/2fa', [PortalAuth::class, 'twoFactor'])->middleware('throttle:6,1');

Route::get('lupa-password', [AccountController::class, 'forgotForm'])->name('portal.password.forgot');
Route::post('lupa-password', [AccountController::class, 'forgotSend'])->middleware('throttle:5,1');
Route::get('reset-password/{token}', [AccountController::class, 'resetForm'])->name('portal.password.reset');
Route::post('reset-password/{token}', [AccountController::class, 'resetSave'])->middleware('throttle:10,1');
Route::get('verifikasi/{id}/{hash}', [AccountController::class, 'verify'])->whereNumber('id')->middleware(['signed', 'throttle:10,1'])->name('portal.verify');
Route::get('berkas/{path}', \App\Http\Controllers\Portal\FileController::class)->where('path', '[A-Za-z0-9._/-]+')->middleware('signed')->name('portal.file');
Route::get('bantuan/{token}', [AccountController::class, 'impersonate'])->middleware('throttle:10,1')->name('portal.impersonate');

Route::middleware('role')->group(function () {
    Route::post('keluar', [PortalAuth::class, 'logout'])->name('portal.logout');
    Route::get('dashboard', DashboardController::class)->middleware('admin.verified')->name('portal.dashboard');
    Route::get('verifikasi', [AccountController::class, 'verifyNotice'])->name('portal.verify.notice');
    Route::post('verifikasi/kirim-ulang', [AccountController::class, 'verifyResend'])->middleware('throttle:3,1')->name('portal.verify.resend');
    Route::post('bantuan/selesai', [AccountController::class, 'impersonateEnd'])->name('portal.impersonate.end');
});

Route::middleware('role:admin')->group(function () {
    Route::get('persetujuan-dpa', [PrivacyController::class, 'dpa'])->name('portal.dpa');
    Route::post('persetujuan-dpa', [PrivacyController::class, 'acceptDpa']);
});

Route::middleware(['role:admin', 'admin.verified'])->group(function () {
    // Langganan & pembayaran (tetap terbuka saat layanan diblokir karena tagihan).
    Route::get('cetak-qr', [\App\Http\Controllers\Portal\PwaController::class, 'printQr'])->name('pwa.print-qr');
    Route::get('langganan', [BillingController::class, 'index'])->name('portal.billing');
    Route::post('langganan/tagihan', [BillingController::class, 'store'])->middleware('throttle:10,1')->name('portal.billing.store');
    Route::get('langganan/tagihan/{invoice}', [BillingController::class, 'show'])->whereNumber('invoice')->name('portal.billing.invoice');
    Route::post('langganan/tagihan/{invoice}/bayar', [BillingController::class, 'pay'])->whereNumber('invoice')->middleware('throttle:10,1')->name('portal.billing.pay');
    Route::post('langganan/tagihan/{invoice}/cek', [BillingController::class, 'check'])->whereNumber('invoice')->middleware('throttle:10,1')->name('portal.billing.check');
    Route::post('langganan/tagihan/{invoice}/bukti', [BillingController::class, 'proof'])->whereNumber('invoice')->middleware('throttle:6,1')->name('portal.billing.proof');
    Route::get('langganan/tagihan/{invoice}/cetak', [BillingController::class, 'print'])->whereNumber('invoice')->name('portal.billing.print');

    Route::get('data-privasi', [PrivacyController::class, 'index'])->name('portal.privacy');
    Route::get('data-privasi/persetujuan-ortu', [PrivacyController::class, 'consentForm'])->name('portal.privacy.consent');
    Route::post('data-privasi/persetujuan-ortu', [PrivacyController::class, 'recordConsent']);
    Route::post('data-privasi/ekspor', [PrivacyController::class, 'export'])->middleware('throttle:3,10')->name('portal.privacy.export');
    Route::get('data-privasi/ekspor/{dataExport}', [PrivacyController::class, 'downloadExport'])->whereNumber('dataExport')->name('portal.privacy.export.download');
    Route::post('data-privasi/hapus', [PrivacyController::class, 'requestDeletion'])->name('portal.privacy.delete');
    Route::post('data-privasi/masa-simpan', [PrivacyController::class, 'retention'])->name('portal.privacy.retention');
    Route::post('data-privasi/hapus/batal', [PrivacyController::class, 'cancelDeletion'])->name('portal.privacy.delete.cancel');
    Route::get('log-akses', [AccountController::class, 'auditLog'])->name('portal.audit');
    Route::get('log-akses/unduh', [AccountController::class, 'exportAuditLog'])->middleware('throttle:6,1')->name('portal.audit.export');
    Route::get('profil-sekolah', [\App\Http\Controllers\Portal\SchoolProfileController::class, 'edit'])->name('portal.school');
    Route::post('profil-sekolah', [\App\Http\Controllers\Portal\SchoolProfileController::class, 'update']);
    Route::post('guru/{teacher}/reset-password', [AccountController::class, 'resetTeacher'])->name('portal.teachers.reset');
    Route::post('siswa/{student}/reset-password', [AccountController::class, 'resetStudent'])->name('portal.students.reset');
    // /guru dan /siswa dipakai aplikasi Guru & Siswa (PWA); halaman data di portal admin pindah ke /data-guru & /data-siswa.
    Route::get('data-guru', [DataController::class, 'teachers'])->name('portal.teachers');
    Route::get('data-siswa', [DataController::class, 'students'])->name('portal.students');
    Route::get('kelas', [DataController::class, 'classrooms'])->name('portal.classrooms');
    Route::post('kelas', [DataController::class, 'storeClassroom'])->name('portal.classrooms.store');
    Route::post('kelas/otomatis', [DataController::class, 'bulkClassrooms'])->name('portal.classrooms.bulk');
    Route::post('kelas/{classroom}/hapus', [DataController::class, 'destroyClassroom'])->whereNumber('classroom')->name('portal.classrooms.destroy');

    Route::get('impor', [ImportController::class, 'index'])->name('portal.imports');
    Route::get('impor/format/{type}', [ImportController::class, 'template'])->name('portal.imports.template');
    Route::post('impor/{type}', [ImportController::class, 'upload'])->whereIn('type', ['teachers', 'students', 'lesson_periods', 'schedules', 'class_schedules'])->name('portal.imports.upload');
    Route::get('impor/{dataImport}', [ImportController::class, 'show'])->whereNumber('dataImport')->name('portal.imports.show');
    Route::post('impor/{dataImport}/konfirmasi', [ImportController::class, 'confirm'])->whereNumber('dataImport')->name('portal.imports.confirm');
    Route::get('impor/{dataImport}/akun.csv', [ImportController::class, 'credentials'])->whereNumber('dataImport')->name('portal.imports.credentials');
    Route::get('impor/{dataImport}/data-guru.xlsx', [ImportController::class, 'teacherSheet'])->whereNumber('dataImport')->name('portal.imports.teacher-sheet');
    Route::get('impor/{dataImport}/kartu', [ImportController::class, 'cards'])->whereNumber('dataImport')->name('portal.imports.cards');
    Route::get('bagikan', [\App\Http\Controllers\Portal\PwaController::class, 'share'])->name('portal.share');

    // Tahap 6 — lifecycle: tutup tahun ajaran, kelulusan, mutasi/keluar, surat keterangan
    Route::get('tahun-ajaran', [\App\Http\Controllers\Portal\LifecycleController::class, 'index'])->name('portal.year');
    Route::post('tahun-ajaran/tutup', [\App\Http\Controllers\Portal\LifecycleController::class, 'close'])->middleware('throttle:5,1')->name('portal.year.close');
    Route::post('tahun-ajaran/{academicYearClosure}/batal', [\App\Http\Controllers\Portal\LifecycleController::class, 'undo'])->whereNumber('academicYearClosure')->middleware('throttle:5,1')->name('portal.year.undo');
    Route::post('data-siswa/pindahan', [\App\Http\Controllers\Portal\LifecycleController::class, 'transferIn'])->middleware('throttle:30,1')->name('portal.students.transfer');
    Route::get('tahun-ajaran/{academicYearClosure}/unduh', [\App\Http\Controllers\Portal\LifecycleController::class, 'exportClosure'])->whereNumber('academicYearClosure')->name('portal.year.export');
    Route::post('data-siswa/{student}/keluar', [\App\Http\Controllers\Portal\LifecycleController::class, 'studentExit'])->whereNumber('student')->name('portal.students.exit');
    Route::post('data-siswa/{student}/aktifkan', [\App\Http\Controllers\Portal\LifecycleController::class, 'studentReactivate'])->whereNumber('student')->name('portal.students.reactivate');
    Route::get('data-siswa/{student}/surat', [\App\Http\Controllers\Portal\LifecycleController::class, 'certificate'])->whereNumber('student')->name('portal.students.certificate');
    Route::post('data-guru/{teacher}/keluar', [\App\Http\Controllers\Portal\LifecycleController::class, 'teacherExit'])->whereNumber('teacher')->name('portal.teachers.exit');
    Route::post('data-guru/{teacher}/aktifkan', [\App\Http\Controllers\Portal\LifecycleController::class, 'teacherReactivate'])->whereNumber('teacher')->name('portal.teachers.reactivate');
    Route::get('bagikan/poster/{app}', [\App\Http\Controllers\Portal\PwaController::class, 'poster'])->whereIn('app', ['siswa', 'guru'])->name('portal.share.poster');
});

Route::prefix('api')->group(function () {
    Route::get('school', [AuthController::class, 'school']);
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/2fa', [AuthController::class, 'twoFactor'])->middleware('throttle:6,1');
    // Login sidik jari / Face ID (passkey): tantangan + verifikasi, sebelum login.
    Route::post('auth/passkey/options', [\App\Http\Controllers\Api\PasskeyController::class, 'loginOptions'])->middleware('throttle:20,1');
    Route::post('auth/passkey', [\App\Http\Controllers\Api\PasskeyController::class, 'login'])->middleware('throttle:login');

    Route::middleware('role')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::get('avatar/{user}', \App\Http\Controllers\Api\AvatarController::class)->whereNumber('user')->middleware('throttle:600,1');
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('me/password', [AuthController::class, 'changePassword']);
        Route::put('me/name', [AuthController::class, 'updateName'])->middleware('throttle:10,1');
        Route::get('passkeys', [\App\Http\Controllers\Api\PasskeyController::class, 'index']);
        Route::post('passkeys/options', [\App\Http\Controllers\Api\PasskeyController::class, 'registerOptions'])->middleware('throttle:10,1');
        Route::post('passkeys', [\App\Http\Controllers\Api\PasskeyController::class, 'register'])->middleware('throttle:10,1');
        Route::delete('passkeys/{id}', [\App\Http\Controllers\Api\PasskeyController::class, 'destroy'])->whereNumber('id');
        Route::get('me/2fa', [AuthController::class, 'twoFactorSetup']);
        // Lonceng & push (semua peran)
        Route::get('notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('notifications/latest', [\App\Http\Controllers\Api\NotificationController::class, 'latest']);
        Route::post('notifications/read', [\App\Http\Controllers\Api\NotificationController::class, 'read']);
        Route::post('push/subscriptions', [\App\Http\Controllers\Api\NotificationController::class, 'subscribe'])->middleware('throttle:10,1');
        Route::delete('push/subscriptions', [\App\Http\Controllers\Api\NotificationController::class, 'unsubscribe']);
        Route::post('me/2fa', [AuthController::class, 'twoFactorConfirm'])->middleware('throttle:6,1');
        Route::post('me/photo', [\App\Http\Controllers\Portal\SchoolProfileController::class, 'storePhoto'])->middleware('throttle:10,1');
        Route::delete('me/photo', [\App\Http\Controllers\Portal\SchoolProfileController::class, 'destroyPhoto']);

        // PWA: dibaca semua peran (isi disaring per peran di controller)
        Route::get('dashboard', \App\Http\Controllers\Api\DashboardController::class);
        Route::get('timetable', \App\Http\Controllers\Api\TimetableController::class);
        Route::get('journals', [JournalController::class, 'index']);
        Route::get('assignments', [AssignmentController::class, 'index']);
        Route::get('agenda', [BoardController::class, 'agenda']);
        Route::get('announcements', [BoardController::class, 'announcements']);
        Route::get('info-coordinators', [BoardController::class, 'infoCoordinators']);
        Route::get('school-map', [\App\Http\Controllers\Api\SchoolMapController::class, 'show']);
        Route::get('school-map/image', [\App\Http\Controllers\Api\SchoolMapController::class, 'image']);
    });

    // Siswa
    Route::middleware('role:student')->group(function () {
        Route::post('attendance/scan', [AttendanceController::class, 'scan'])->middleware('throttle:20,1');
        Route::get('attendance/mine', [AttendanceController::class, 'mine']);
        Route::put('assignments/{assignment}/done', [AssignmentController::class, 'markDone']);
        Route::delete('assignments/{assignment}/done', [AssignmentController::class, 'unmarkDone']);
    });

    // Guru & admin
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('classrooms/{classroom}/qr', [AttendanceController::class, 'qr']);
        Route::get('classrooms/{classroom}/attendance', [AttendanceController::class, 'classroom']);
        Route::put('students/{student}/attendance', [AttendanceController::class, 'correct']);
        Route::get('classrooms', [ClassroomController::class, 'index']);
        Route::get('classrooms/{classroom}/qr.svg', [AttendanceController::class, 'qrSvg']);

        Route::get('journals/prefill', [JournalController::class, 'prefill']);
        Route::post('journals', [JournalController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('journals/{journal}', [JournalController::class, 'destroy']);
        Route::post('assignments', [AssignmentController::class, 'store'])->middleware('throttle:30,1');
        Route::get('assignments/{assignment}', [AssignmentController::class, 'show']);
        Route::put('assignments/{assignment}/grades/{student}', [AssignmentController::class, 'grade'])->whereNumber(['assignment', 'student'])->middleware('throttle:240,1');
        Route::get('assignments/{assignment}/export', [AssignmentController::class, 'export'])->whereNumber('assignment');
        Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy']);
        Route::get('homeroom', [\App\Http\Controllers\Api\HomeroomController::class, 'index']);
        Route::put('students/{student}/class-role', [\App\Http\Controllers\Api\HomeroomController::class, 'setRole']);
        Route::post('students/login-cards', [\App\Http\Controllers\Api\LoginCardController::class, 'store'])->middleware('throttle:20,1');

        // Perangkat terdaftar: admin untuk semua akun, guru hanya untuk siswa perwaliannya
        Route::get('users/{user}/devices', [DeviceController::class, 'index']);
        Route::delete('users/{user}/devices', [DeviceController::class, 'reset']);
    });

    // Admin sekolah
    Route::middleware(['role:admin', 'admin.verified'])->group(function () {
        // QR statis dicetak admin; "Perbarui QR" membatalkan QR yang sudah ditempel, jadi khusus admin.
        Route::post('classrooms/{classroom}/qr/rotate', [AttendanceController::class, 'rotateQr']);

        // Pengaturan presensi: mode QR, batas jam ke-N, jaringan WiFi sekolah
        Route::get('settings/attendance', [AttendanceSettingsController::class, 'show']);
        Route::put('settings/attendance', [AttendanceSettingsController::class, 'update']);
        Route::post('settings/attendance/networks/register-current', [AttendanceSettingsController::class, 'registerCurrent']);

        Route::delete('agenda/{kind}', [BoardController::class, 'deleteAgenda'])->whereIn('kind', ['sekolah', 'briefing']);
        Route::put('info-coordinators/{category}', [BoardController::class, 'saveInfoCoordinator']);
        Route::put('school-map', [\App\Http\Controllers\Api\SchoolMapController::class, 'save'])->middleware('throttle:30,1');

        Route::apiResource('classrooms', ClassroomController::class)->except(['index', 'show']);
        Route::post('teachers/login-cards', [\App\Http\Controllers\Api\LoginCardController::class, 'storeTeachers'])->middleware('throttle:20,1');
        Route::apiResource('teachers', TeacherController::class)->except(['show']);
        Route::apiResource('students', StudentController::class)->except(['show']);
        Route::get('students/{student}/history', [StudentController::class, 'history']);
    });
});

/*
| Menu prototipe yang dilengkapi (absensi guru, pembiasaan, MP-Library, piket, jurnal kelas, leger, CBT)
*/
Route::prefix('api')->group(function () {
    // Semua peran: Pembiasaan & MP-Library (isi disaring per peran di controller)
    Route::middleware('role')->group(function () {
        Route::get('habits', [\App\Http\Controllers\Api\HabitController::class, 'index']);
        Route::get('habit-coordinators', [\App\Http\Controllers\Api\HabitController::class, 'coordinators']);
        Route::post('habits', [\App\Http\Controllers\Api\HabitController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('habits/{habit}', [\App\Http\Controllers\Api\HabitController::class, 'destroy']);
        Route::get('library', [\App\Http\Controllers\Api\LibraryController::class, 'index']);
        Route::get('class-notes', [\App\Http\Controllers\Api\ClassLifeController::class, 'notes']);
        Route::post('library/{item}/open', [\App\Http\Controllers\Api\LibraryController::class, 'open'])->middleware(['library.protect', 'throttle:60,1']);
        // Proteksi MP-Library: isi berkas hanya lewat tiket sekali pakai; unduhan dibatasi; laporan penampil dicatat.
        Route::get('library/{item}/stream', [\App\Http\Controllers\Api\LibraryController::class, 'stream'])->middleware(['library.protect:ticket', 'throttle:30,1']);
        Route::post('library/{item}/download', [\App\Http\Controllers\Api\LibraryController::class, 'download'])->middleware(['library.protect', 'throttle:20,1']);
        Route::post('library/{item}/event', [\App\Http\Controllers\Api\LibraryController::class, 'event'])->middleware('throttle:60,1');
    });
    Route::middleware('role:student')->group(function () {
        Route::get('my-class', [\App\Http\Controllers\Api\ClassLifeController::class, 'myClass']);
        Route::post('class-notes', [\App\Http\Controllers\Api\ClassLifeController::class, 'storeNote'])->middleware('throttle:20,1');
        Route::get('my-folders', [\App\Http\Controllers\Api\TaskFolderController::class, 'index']);
        Route::post('assignment-files', [\App\Http\Controllers\Api\TaskFolderController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('assignment-files/{file}', [\App\Http\Controllers\Api\TaskFolderController::class, 'destroy']);
        Route::post('library/{item}/favorite', [\App\Http\Controllers\Api\LibraryController::class, 'favorite']);
    });
    Route::middleware('role:admin,teacher')->group(function () {
        // Informasi: ditulis guru koordinator bidang (cek di controller); hapus oleh koordinator/penulis.
        Route::post('announcements', [BoardController::class, 'storeAnnouncement'])->middleware('throttle:30,1');
        // Gambar denah sekolah: diunggah koordinator Sarpras (cek di controller).
        Route::post('school-map/image', [\App\Http\Controllers\Api\SchoolMapController::class, 'storeImage'])->middleware('throttle:10,1');
        Route::delete('school-map/image', [\App\Http\Controllers\Api\SchoolMapController::class, 'deleteImage']);
        Route::get('school-map/layout', [\App\Http\Controllers\Api\SchoolMapController::class, 'layout']);
        Route::put('school-map/layout', [\App\Http\Controllers\Api\SchoolMapController::class, 'saveLayout'])->middleware('throttle:60,1');
        Route::delete('announcements/{announcement}', [BoardController::class, 'deleteAnnouncement']);
        Route::put('announcements/{announcement}', [BoardController::class, 'updateAnnouncement'])->middleware('throttle:60,1');
        // Agenda: admin, atau guru koordinator agenda tersebut (cek di controller).
        Route::put('agenda/{kind}', [BoardController::class, 'saveAgenda'])->whereIn('kind', ['sekolah', 'briefing']);
        Route::get('leger', [\App\Http\Controllers\Api\LegerController::class, 'index']);
        Route::get('leger/classes/{classroom}', [\App\Http\Controllers\Api\LegerController::class, 'sheet']);
        Route::put('leger/classes/{classroom}/students/{student}', [\App\Http\Controllers\Api\LegerController::class, 'saveEntry'])->middleware('throttle:240,1');
        Route::post('leger/classes/{classroom}/apply-competency', [\App\Http\Controllers\Api\LegerController::class, 'applyCompetency']);
        Route::get('rosters/{classroom}', [\App\Http\Controllers\Api\ClassLifeController::class, 'roster']);
        Route::put('rosters/{classroom}', [\App\Http\Controllers\Api\ClassLifeController::class, 'saveRoster']);
        Route::post('rosters/{classroom}/random', [\App\Http\Controllers\Api\ClassLifeController::class, 'randomRoster']);
        Route::post('rosters/{classroom}/import', [\App\Http\Controllers\Api\ClassLifeController::class, 'importRoster'])->middleware('throttle:10,1');
        Route::get('rosters/{classroom}/export', [\App\Http\Controllers\Api\ClassLifeController::class, 'exportRoster']);
        Route::post('library', [\App\Http\Controllers\Api\LibraryController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('library/{item}', [\App\Http\Controllers\Api\LibraryController::class, 'destroy']);
        Route::put('library/{item}/policy', [\App\Http\Controllers\Api\LibraryController::class, 'policy']);
        Route::get('library-audit', [\App\Http\Controllers\Api\LibraryController::class, 'audit']);
        Route::get('library-audit/export', [\App\Http\Controllers\Api\LibraryController::class, 'auditExport']);
        // Daftar Nilai Sumatif: guru pengampu mengisi; admin melihat & mengekspor.
        Route::get('grade-books', [\App\Http\Controllers\Api\GradeBookController::class, 'index']);
        Route::post('grade-books', [\App\Http\Controllers\Api\GradeBookController::class, 'open'])->middleware('throttle:60,1');
        Route::get('grade-books/{book}', [\App\Http\Controllers\Api\GradeBookController::class, 'show']);
        Route::put('grade-books/{book}', [\App\Http\Controllers\Api\GradeBookController::class, 'update']);
        Route::put('grade-books/{book}/entries', [\App\Http\Controllers\Api\GradeBookController::class, 'saveEntries'])->middleware('throttle:120,1');
        Route::get('grade-books/{book}/export', [\App\Http\Controllers\Api\GradeBookController::class, 'export']);
    });

    // CBT (paket All Service). Guru: paket soal & nilai essay. Admin: soal masuk, bank soal, ujian, pantau, record. Siswa: mengerjakan.
    Route::prefix('cbt')->group(function () {
        $P = \App\Http\Controllers\Api\CbtPackageController::class;
        $E = \App\Http\Controllers\Api\CbtExamController::class;
        $St = \App\Http\Controllers\Api\CbtStudentController::class;
        $G = \App\Http\Controllers\Api\CbtGradingController::class;
        Route::middleware('role:admin,teacher')->group(function () use ($P, $G) {
            Route::get('meta', [$P, 'meta']);
            Route::get('packages', [$P, 'index']);
            Route::get('packages/{bank}', [$P, 'show'])->whereNumber('bank');
            Route::delete('packages/{bank}', [$P, 'destroy'])->whereNumber('bank');
            Route::get('format', [$P, 'format']);
            Route::get('grading', [$G, 'index']);
            Route::get('grading/{class}', [$G, 'show'])->whereNumber('class');
            Route::put('grading/answers/{answer}', [$G, 'grade'])->whereNumber('answer')->middleware('throttle:300,1');
        });
        Route::middleware('role:teacher')->group(function () use ($P) {
            Route::post('packages', [$P, 'store'])->middleware('throttle:30,1');
            Route::put('packages/{bank}', [$P, 'update'])->whereNumber('bank')->middleware('throttle:240,1');
            Route::post('packages/{bank}/submit', [$P, 'submit'])->whereNumber('bank');
            Route::post('packages/{bank}/import', [$P, 'import'])->whereNumber('bank')->middleware('throttle:10,1');
            Route::post('images', [$P, 'image'])->middleware('throttle:120,1');
        });
        // Ujian: admin (PTS/PAS) & guru (Ulangan Harian miliknya — dicek di controller).
        Route::middleware('role:admin,teacher')->group(function () use ($P, $E) {
            Route::get('bank', [$P, 'bank']);
            Route::get('exams', [$E, 'index']);
            Route::post('exams', [$E, 'store'])->middleware('throttle:60,1');
            Route::get('exams/{exam}', [$E, 'show'])->whereNumber('exam');
            Route::put('exams/{exam}', [$E, 'update'])->whereNumber('exam');
            Route::delete('exams/{exam}', [$E, 'destroy'])->whereNumber('exam');
            Route::post('exams/{exam}/publish', [$E, 'publish'])->whereNumber('exam');
            Route::post('exams/{exam}/tokens', [$E, 'tokens'])->whereNumber('exam');
            Route::put('exams/{exam}/classes/{class}', [$E, 'grader'])->whereNumber(['exam', 'class']);
            Route::get('exams/{exam}/monitor', [$E, 'monitor'])->whereNumber('exam');
            Route::get('exams/{exam}/results', [$E, 'results'])->whereNumber('exam');
            Route::get('exams/{exam}/export', [$E, 'export'])->whereNumber('exam');
            Route::post('attempts/{attempt}/action', [$E, 'action'])->whereNumber('attempt');
        });
        Route::middleware('role:admin')->group(function () use ($P, $E) {
            Route::post('packages/{bank}/review', [$P, 'review'])->whereNumber('bank');
            Route::get('students', [$E, 'students']);
            Route::get('students/{student}', [$E, 'record'])->whereNumber('student');
        });
        Route::middleware('role:student')->group(function () use ($St) {
            Route::get('my-exams', [$St, 'index']);
            Route::post('my-exams/{exam}/start', [$St, 'start'])->whereNumber('exam')->middleware('throttle:30,1');
            Route::get('attempts/{attempt}', [$St, 'show'])->whereNumber('attempt');
            Route::put('attempts/{attempt}/answer', [$St, 'answer'])->whereNumber('attempt')->middleware('throttle:600,1');
            Route::post('attempts/{attempt}/event', [$St, 'event'])->whereNumber('attempt')->middleware('throttle:120,1');
            Route::post('attempts/{attempt}/finish', [$St, 'finish'])->whereNumber('attempt');
        });
    });

    // Kas Kelas: bendahara kelas (siswa) mencatat; siswa sekelas melihat; wali kelas & admin memantau.
    Route::middleware('role:admin,teacher,student')->group(function () {
        Route::get('class-cash', [\App\Http\Controllers\Api\ClassCashController::class, 'show']);
        Route::get('class-cash/export', [\App\Http\Controllers\Api\ClassCashController::class, 'export']);
    });
    Route::middleware('role:student')->group(function () {
        Route::post('class-cash', [\App\Http\Controllers\Api\ClassCashController::class, 'store'])->middleware('throttle:60,1');
        Route::put('class-cash/settings', [\App\Http\Controllers\Api\ClassCashController::class, 'settings'])->middleware('throttle:20,1');
        Route::delete('class-cash/{entry}', [\App\Http\Controllers\Api\ClassCashController::class, 'destroy'])->whereNumber('entry');
    });

    // Biodata peserta didik (halaman identitas buku rapor)
    Route::middleware('role:student')->group(function () {
        Route::get('me/biodata', [\App\Http\Controllers\Api\StudentProfileController::class, 'mine']);
        Route::put('me/biodata', [\App\Http\Controllers\Api\StudentProfileController::class, 'updateMine'])->middleware('throttle:30,1');
    });
    Route::middleware('role:admin,teacher')->group(function () {
        Route::get('students/{student}/biodata', [\App\Http\Controllers\Api\StudentProfileController::class, 'show'])->whereNumber('student');
        Route::put('students/{student}/biodata', [\App\Http\Controllers\Api\StudentProfileController::class, 'update'])->whereNumber('student')->middleware('throttle:60,1');
    });

    // Absensi guru: guru sendiri
    Route::middleware('role:teacher')->group(function () {
        Route::get('teacher-attendance/me', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'mine']);
        Route::post('teacher-attendance/check-in', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'checkIn'])->middleware('throttle:20,1');
        Route::post('teacher-attendance/enter', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'enter'])->middleware('throttle:30,1');
    });

    // Absensi guru: pantau & catat manual (admin)
    Route::middleware(['role:admin', 'admin.verified'])->group(function () {
        Route::get('teacher-attendance', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'report']);
        Route::post('teacher-attendance/manual', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'manual']);
        Route::post('teacher-attendance/manual-enter', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'manualEnter']);
        Route::get('teacher-attendance/gate', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'gate']);
        Route::get('teacher-attendance/gate.svg', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'gateSvg']);
        Route::put('leger/settings', [\App\Http\Controllers\Api\LegerController::class, 'saveSettings']);
        Route::post('leger/master', [\App\Http\Controllers\Api\LegerController::class, 'uploadMaster'])->middleware('throttle:10,1');
        Route::delete('leger/master', [\App\Http\Controllers\Api\LegerController::class, 'resetMaster']);
        Route::post('leger/distribute', [\App\Http\Controllers\Api\LegerController::class, 'distribute']);
        Route::delete('leger/distribute/{classroom}', [\App\Http\Controllers\Api\LegerController::class, 'withdraw']);
        Route::post('teacher-attendance/gate/rotate', [\App\Http\Controllers\Api\TeacherAttendanceController::class, 'rotateGate']);
    });
});

// Kartu token CBT per kelas, siap cetak (admin).
Route::middleware('role:admin,teacher')->get('cbt/kartu-token/{exam}', [\App\Http\Controllers\Api\CbtExamController::class, 'cards'])->whereNumber('exam')->name('pwa.cbt.cards');
// Biodata peserta didik siap cetak (cover/identitas rapor): siswa sendiri, wali kelas, admin.
Route::middleware('role:admin,teacher,student')->get('biodata/cetak', [\App\Http\Controllers\Api\StudentProfileController::class, 'print'])->name('pwa.biodata');
// Kartu login siswa (password sementara baru), dibuat dari menu Wali Kelas; hanya pembuatnya, 30 menit.
Route::middleware('role:admin,teacher')->get('kartu-login/{key}', [\App\Http\Controllers\Api\LoginCardController::class, 'show'])->where('key', '[A-Za-z0-9]{40}')->name('pwa.login-cards');
// Rapor siap cetak dari leger (wali kelas ini atau admin).
Route::middleware('role:admin,teacher')->get('rapor/{classroom}', \App\Http\Controllers\Portal\RaporController::class)->whereNumber('classroom')->name('pwa.rapor');
