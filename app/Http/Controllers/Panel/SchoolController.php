<?php

namespace App\Http\Controllers\Panel;

use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Http\Controllers\Central\RegistrationController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
use App\Models\User;
use App\Rules\AvailableSubdomain;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\Impersonation;
use App\Services\Platform\SchoolProvisioner;
use App\Services\Platform\SchoolUsage;
use App\Tenancy\SchoolScope;
use App\Tenancy\Subdomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Panel super-admin Parama Tech: semua sekolah (tenant). */
class SchoolController extends Controller
{
    public function __construct(private AuditLogger $audit, private SchoolUsage $usage) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $status = (string) $request->query('status');

        $schools = School::query()
            ->when($q, fn ($b) => $b->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('subdomain', 'like', "%$q%")->orWhere('npsn', 'like', "%$q%")))
            ->when(in_array($status, ['trial', 'active', 'suspended'], true), fn ($b) => $b->where('status', $status))
            ->when($status === 'unverified', fn ($b) => $b->whereNull('verified_at'))
            ->orderByDesc('created_at')->paginate(30)->withQueryString();

        $counts = School::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status');

        return view('panel.schools.index', [
            'schools' => $schools, 'q' => $q, 'status' => $status,
            'usage' => $this->usage->summary($schools->pluck('id')->all()),
            'counts' => $counts, 'totalStudents' => $this->usage->totalActiveStudents(),
        ]);
    }

    public function create(): View
    {
        return view('panel.schools.create', ['timezones' => RegistrationController::TIMEZONES]);
    }

    public function store(Request $request, SchoolProvisioner $provisioner): RedirectResponse
    {
        $request->merge(['subdomain' => Subdomain::normalize((string) $request->input('subdomain')),
            'access_code' => filled($request->input('access_code')) ? \App\Support\SchoolCode::normalize((string) $request->input('access_code')) : null]);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'npsn' => ['nullable', 'digits:8'],
            'level' => ['nullable', Rule::in(array_keys(\App\Support\SchoolLevel::LABELS))],
            'address' => ['nullable', 'string', 'max:255'],
            'access_code' => self::codeRules(),
            'subdomain' => ['required', new AvailableSubdomain],
            'timezone' => ['required', Rule::in(array_keys(RegistrationController::TIMEZONES))],
            'status' => ['required', Rule::in(['trial', 'active'])],
            'plan' => ['nullable', Rule::in(\App\Support\Plan::keys())],
            'student_quota' => ['required', 'integer', 'min:1', 'max:100000'],
            'active_until' => ['nullable', 'date', 'after:today', 'required_if:status,active'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'regex:/^\+?[0-9\s-]{8,16}$/'],
        ], [], ['subdomain' => 'link portal', 'student_quota' => 'kuota siswa', 'active_until' => 'aktif sampai']);

        $code = $d['access_code'] ?? null;
        unset($d['access_code']);
        $out = $provisioner->create($d, 'panel', $request->user());
        if ($code) {
            $out['school']->setAccessCode($code);
        }

        return redirect()->route('panel.schools.show', $out['school'])->with('created', [
            'username' => 'admin', 'email' => $out['admin']->email, 'password' => $out['temporary_password'],
        ]);
    }

    public function show(School $school): View
    {
        $admins = User::query()->withoutGlobalScope(SchoolScope::class)->where('school_id', $school->id)->where('role', Role::Admin->value)->get();
        $logs = AuditLog::query()->withoutGlobalScope(SchoolScope::class)->where('school_id', $school->id)->latest('created_at')->latest('id')->limit(30)->get();

        return view('panel.schools.show', [
            'school' => $school, 'admins' => $admins, 'logs' => $logs, 'u' => $this->usage->detail($school),
            'backups' => app(\App\Services\Platform\SchoolBackup::class)->list($school),
            'dpa' => \App\Services\Platform\Legal::accepted($school->id, 'dpa'),
            'invoices' => \App\Services\Billing\BillingService::invoices()->where('school_id', $school->id)->latest('id')->limit(20)->get(),
        ]);
    }

    /** Aturan kode sekolah susunan super-admin: 4–12 huruf/angka, unik. Kosong = dibuat acak. */
    private static function codeRules(?School $except = null): array
    {
        return ['nullable', 'regex:/^[A-Z0-9]{'.\App\Support\SchoolCode::MIN.','.\App\Support\SchoolCode::MAX.'}$/',
            Rule::unique('schools', 'access_code')->ignore($except?->id)];
    }

    /** POST /panel/sekolah/{school}/kode — susun/ganti kode sekolah. Kosong = acak. Kode lama langsung tidak berlaku. */
    public function code(Request $request, School $school): RedirectResponse
    {
        $request->merge(['access_code' => filled($request->input('access_code')) ? \App\Support\SchoolCode::normalize((string) $request->input('access_code')) : null]);
        $d = $request->validate(['access_code' => self::codeRules($school)], [
            'access_code.regex' => 'Kode sekolah 4–12 huruf/angka tanpa spasi, mis. SMPN1KSG.',
            'access_code.unique' => 'Kode ini sudah dipakai sekolah lain.',
        ], ['access_code' => 'kode sekolah']);

        $old = $school->access_code;
        $new = $school->setAccessCode($d['access_code'] ?? null);
        $this->audit->log('school.access_code', 'Kode sekolah diganti ('.\App\Support\SchoolCode::format($old).' → '.\App\Support\SchoolCode::format($new).')', $school);

        return back()->with('ok', 'Kode sekolah sekarang '.\App\Support\SchoolCode::format($new).'. Bagikan ke admin sekolah; kode lama tidak berlaku lagi.');
    }

    /** Ubah identitas, paket, kuota, dan masa aktif. */
    public function update(Request $request, School $school): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'npsn' => ['nullable', 'digits:8'],
            'level' => ['nullable', Rule::in(array_keys(\App\Support\SchoolLevel::LABELS))],
            'timezone' => ['required', Rule::in(array_keys(RegistrationController::TIMEZONES))],
            'plan' => ['nullable', Rule::in(\App\Support\Plan::keys())],
            'student_quota' => ['required', 'integer', 'min:1', 'max:100000'],
            'status' => ['required', Rule::in(['trial', 'active'])],
            'trial_ends_at' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date', 'required_if:status,active'],
        ], [], ['student_quota' => 'kuota siswa', 'active_until' => 'aktif sampai', 'trial_ends_at' => 'trial berakhir']);

        if ($school->isSuspended()) {
            unset($d['status']); // status ditangani tombol Aktifkan
        }
        if (empty($d['level'])) {
            unset($d['level']);
        }
        if (empty($d['plan'])) {
            unset($d['plan']);
        }

        $before = $school->only(['name', 'level', 'plan', 'student_quota', 'status', 'trial_ends_at', 'active_until', 'timezone']);
        $school->update($d);
        // Jenjang berubah: tingkat semua kelas dihitung ulang dari namanya.
        if ($school->wasChanged('level')) {
            app(\App\Tenancy\TenantContext::class)->run($school, fn () => \App\Models\Classroom::query()->get()
                ->each(fn ($c) => $c->forceFill(['grade' => null])->save()));
        }
        $changes = collect($school->getChanges())->except('updated_at')->keys()->implode(', ');
        $this->audit->log('school.updated', "Data sekolah diperbarui ($changes)", $school, null, ['before' => $before]);

        return back()->with('ok', 'Perubahan tersimpan.');
    }

    /** Verifikasi sekolah daftar mandiri setelah NPSN dicek: kuota penuh dan guru/siswa bisa masuk. */
    public function verify(Request $request, School $school): RedirectResponse
    {
        if ($school->isVerified()) {
            return back()->with('ok', 'Sekolah sudah terverifikasi.');
        }
        if (! preg_match('/^\d{8}$/', (string) $school->npsn)) {
            return back()->withErrors(['npsn' => 'Isi NPSN 8 digit dulu (oleh admin sekolah atau di formulir Identitas).']);
        }

        $school->forceFill(['verified_at' => now()])->save();
        $this->audit->log('school.verified', "Sekolah diverifikasi Parama Tech (NPSN {$school->npsn}) oleh {$request->user()->name}", $school);

        return back()->with('ok', "{$school->name} terverifikasi. Kuota penuh aktif dan guru/siswa sudah bisa masuk.");
    }

    public function suspend(Request $request, School $school): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $school->forceFill(['status' => SchoolStatus::Suspended, 'suspended_at' => now(), 'suspended_reason' => $d['reason'], 'suspension_type' => 'manual'])->save();
        $this->audit->log('school.suspended', 'Sekolah ditangguhkan. Alasan: '.$d['reason'], $school);

        return back()->with('ok', "{$school->name} ditangguhkan. Data tetap tersimpan; portal sekolah tidak bisa diakses.");
    }

    public function activate(School $school): RedirectResponse
    {
        $status = $school->active_until?->isFuture() ? SchoolStatus::Active : SchoolStatus::Trial;
        $school->forceFill(['status' => $status, 'suspended_at' => null, 'suspended_reason' => null, 'suspension_type' => null, 'billing_notices' => null,
            'trial_ends_at' => $status === SchoolStatus::Trial && ! $school->trial_ends_at?->isFuture() ? now()->addDays(14) : $school->trial_ends_at])->save();
        $this->audit->log('school.activated', 'Sekolah diaktifkan kembali ('.$status->value.')', $school);

        return back()->with('ok', "{$school->name} aktif kembali.");
    }

    /** Admin sekolah kehilangan akses: buat password sementara (ditampilkan sekali). */
    public function resetAdmin(Request $request, School $school): RedirectResponse
    {
        $d = $request->validate(['user_id' => ['required', 'integer']]);
        $admin = User::query()->withoutGlobalScope(SchoolScope::class)->where('school_id', $school->id)->where('role', Role::Admin->value)->findOrFail($d['user_id']);

        $pw = strtolower(Str::password(10, symbols: false));
        // Sekaligus hapus 2FA: kasus paling umum admin terkunci adalah HP authenticator hilang. Admin wajib mendaftar ulang 2FA.
        $admin->forceFill(['password' => $pw, 'must_change_password' => true, 'is_active' => true,
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        $this->audit->log('admin.reset', "Password & 2FA admin {$admin->name} direset oleh Parama Tech", $school);

        return back()->with('reset', ['name' => $admin->name, 'username' => $admin->username, 'password' => $pw]);
    }

    public function impersonate(Request $request, School $school, Impersonation $impersonation): RedirectResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], [], ['reason' => 'alasan']);

        return redirect()->away($impersonation->start($school, $request->user(), $d['reason']));
    }

    public function backup(Request $request, School $school, \App\Services\Platform\SchoolBackup $backup): RedirectResponse
    {
        $r = $backup->create($school, $request->user()->name.' (Parama Tech)');

        return back()->with('ok', "Backup {$r['name']} dibuat (".\App\Services\Platform\SchoolUsage::bytes($r['size']).').');
    }

    public function downloadBackup(Request $request, School $school, string $name, \App\Services\Platform\SchoolBackup $backup)
    {
        $this->audit->log('backup.downloaded', "Backup $name diunduh oleh {$request->user()->name}", $school);

        return $backup->download($school, $name);
    }

    /**
     * Hapus sekolah permanen (hak hapus data). Syarat: sudah ditangguhkan dan nama alamat diketik ulang.
     * Menghapus semua baris database (cascade), berkas, dan seluruh backup sekolah ini.
     */
    public function destroy(Request $request, School $school, \App\Services\Platform\SchoolDeletion $deletion): RedirectResponse
    {
        abort_unless($school->isSuspended(), 422, 'Tangguhkan sekolah terlebih dahulu.');
        $request->validate(['confirm' => ['required', Rule::in([$school->subdomain])]], ['confirm.in' => 'Ketik link portal sekolah persis untuk konfirmasi.']);

        $deletion->delete($school, $request->user());

        return redirect()->route('panel.schools')->with('ok', "{$school->name} dan seluruh datanya sudah dihapus permanen.");
    }

    private function logQuery(Request $request)
    {
        return AuditLog::query()->withoutGlobalScope(SchoolScope::class)->with('school:id,name,subdomain')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when(\App\Models\AuditLog::CATEGORIES[(string) $request->query('kategori')] ?? null, fn ($q, $c) => $q->where(function ($w) use ($c) {
                foreach ($c[2] as $prefix) {
                    $w->orWhere('action', 'like', $prefix.'%');
                }
            }))
            ->when(trim((string) $request->query('q')), function ($q, $term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($term, 0, 80)).'%';
                $q->where(fn ($w) => $w->where('description', 'like', $like)->orWhere('actor_name', 'like', $like)->orWhere('action', 'like', $like)->orWhere('ip', 'like', $like));
            })
            ->when($request->integer('school'), fn ($q, $id) => $q->where('school_id', $id))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse($d, 'Asia/Jakarta')->startOfDay()->utc()))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<=', \Illuminate\Support\Carbon::parse($d, 'Asia/Jakarta')->endOfDay()->utc()))
            ->latest('created_at')->latest('id');
    }

    public function logs(Request $request): View
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        $today = \App\Models\AuditLog::query()->withoutGlobalScope(SchoolScope::class)->where('created_at', '>=', now('Asia/Jakarta')->startOfDay()->utc())
            ->pluck('action')->map(fn ($a) => \App\Models\AuditLog::categoryOf((string) $a)[0])->countBy();

        return view('panel.logs', ['logs' => $this->logQuery($request)->paginate(50)->withQueryString(), 'today' => $today]);
    }

    /** Log aktivitas sebagai CSV (maks. 20.000 baris sesuai filter). */
    public function exportLogs(Request $request)
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $this->audit->log('audit.exported', 'Log aktivitas platform diunduh oleh '.$request->user()->name, null, $request->user());
        $rows = $this->logQuery($request)->limit(20000)->get()->map(fn ($l) => [
            $l->created_at->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'), $l->school?->subdomain ?? 'platform', $l->actor_name, $l->action, $l->description, $l->ip,
        ]);

        return \App\Support\Csv::download('log-aktivitas-'.now()->format('Ymd-His').'.csv', ['Waktu (WIB)', 'Sekolah', 'Oleh', 'Tindakan', 'Keterangan', 'IP'], $rows);
    }
}
