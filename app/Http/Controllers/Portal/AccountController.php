<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\EmailVerification;
use App\Services\Platform\Impersonation;
use App\Services\Platform\PasswordResets;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Verifikasi email, lupa password, sesi bantuan Parama Tech, log akses, dan reset password oleh admin. */
class AccountController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    // ---------- verifikasi email ----------

    public function verifyNotice(Request $request): View|RedirectResponse
    {
        if ($request->user()->email_verified_at) {
            return redirect()->route('portal.dashboard');
        }

        return view('portal.account.verify', ['user' => $request->user()]);
    }

    public function verifyResend(Request $request, EmailVerification $verification): RedirectResponse
    {
        $verification->send($request->user(), $this->tenant->get());

        return back()->with('ok', 'Email verifikasi dikirim ulang ke '.$request->user()->email.'.');
    }

    /** Tautan bertanda tangan dari email (bisa dibuka tanpa login). */
    public function verify(Request $request, int $id, string $hash, AuditLogger $audit): RedirectResponse
    {
        $user = User::find($id); // ter-scope ke sekolah ini
        abort_unless($user && hash_equals(sha1((string) $user->email), $hash), 403, 'Tautan verifikasi tidak valid.');

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            $audit->log('email.verified', "Email {$user->email} terverifikasi", $this->tenant->get(), $user);
        }

        return redirect()->route($request->user() ? 'portal.dashboard' : 'portal.login')->with('ok', 'Email terverifikasi. Selamat datang di MyPresence!');
    }

    // ---------- lupa password ----------

    public function forgotForm(): View
    {
        return view('portal.account.forgot', ['school' => $this->tenant->get()]);
    }

    public function forgotSend(Request $request, PasswordResets $resets): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $resets->request($data['email']);

        return back()->with('ok', 'Jika email itu terdaftar di sekolah ini, tautan untuk membuat password baru sudah dikirim. Periksa juga folder spam.');
    }

    public function resetForm(string $token, PasswordResets $resets): View
    {
        return view('portal.account.reset', ['school' => $this->tenant->get(), 'token' => $token, 'valid' => (bool) $resets->find($token)]);
    }

    public function resetSave(Request $request, string $token, PasswordResets $resets): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]], [], ['password' => 'password']);
        $resets->reset($token, $data['password']);

        return redirect()->route('portal.login')->with('ok', 'Password baru tersimpan. Silakan masuk.');
    }

    // ---------- sesi bantuan Parama Tech ----------

    public function impersonate(Request $request, string $token, Impersonation $impersonation): RedirectResponse
    {
        abort_unless($impersonation->consume($request, $token), 403, 'Tautan akses bantuan tidak valid atau sudah kedaluwarsa.');

        return redirect()->route('portal.dashboard');
    }

    public function impersonateEnd(Request $request, Impersonation $impersonation): RedirectResponse
    {
        $impersonation->end($request);

        return redirect()->away(\App\Tenancy\Subdomain::centralUrl('panel/sekolah/'.$this->tenant->id()));
    }

    // ---------- log akses (admin) ----------

    private function auditQuery(Request $request)
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'q' => ['nullable', 'string', 'max:64']]);
        $tz = app(\App\Tenancy\TenantContext::class)->get()->timezone;

        return AuditLog::query()
            ->when(trim((string) $request->query('q')), fn ($b, $q) => $b->where(fn ($w) => $w->where('description', 'like', "%$q%")->orWhere('actor_name', 'like', "%$q%")))
            ->when($request->query('from'), fn ($b, $d) => $b->where('created_at', '>=', \Illuminate\Support\Carbon::parse($d, $tz)->startOfDay()->utc()))
            ->when($request->query('to'), fn ($b, $d) => $b->where('created_at', '<=', \Illuminate\Support\Carbon::parse($d, $tz)->endOfDay()->utc()))
            ->latest('created_at')->latest('id');
    }

    public function auditLog(Request $request): View
    {
        return view('portal.account.audit', ['logs' => $this->auditQuery($request)->paginate(50)->withQueryString()]);
    }

    /** Log akses sekolah sebagai CSV (untuk arsip / pemeriksaan). */
    public function exportAuditLog(Request $request, AuditLogger $audit)
    {
        $school = app(\App\Tenancy\TenantContext::class)->get();
        $q = $this->auditQuery($request);
        $audit->log('audit.exported', $request->user()->name.' mengunduh log akses', $school, $request->user());
        $rows = $q->limit(20000)->get()->map(fn ($l) => [$l->created_at->timezone($school->timezone)->format('Y-m-d H:i:s'), $l->actor_name, $l->description, $l->ip]);

        return \App\Support\Csv::download('log-akses-'.$school->subdomain.'-'.now()->format('Ymd').'.csv', ['Waktu', 'Oleh', 'Keterangan', 'IP'], $rows);
    }

    // ---------- reset password guru/siswa oleh admin ----------

    public function resetTeacher(Teacher $teacher, AuditLogger $audit): RedirectResponse
    {
        return $this->issueTemporary($teacher->user, 'guru', $audit);
    }

    public function resetStudent(Student $student, AuditLogger $audit): RedirectResponse
    {
        return $this->issueTemporary($student->user, 'siswa', $audit);
    }

    private function issueTemporary(?User $user, string $label, AuditLogger $audit): RedirectResponse
    {
        abort_unless($user, 404);
        $pw = strtolower(Str::password(8, symbols: false));
        $user->forceFill(['password' => $pw, 'must_change_password' => true])->save();
        $audit->log('password.temporary', "Password sementara dibuat untuk $label {$user->name}", $this->tenant->get());

        return back()->with('temp_password', ['name' => $user->name, 'username' => $user->username, 'password' => $pw]);
    }
}
