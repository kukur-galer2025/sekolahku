<?php

namespace App\Http\Controllers\Portal;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicYearClosure;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Lifecycle\Exits;
use App\Services\Lifecycle\YearClosure;
use App\Services\Platform\Totp;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Tahap 6: Tutup Tahun Ajaran, kelulusan, mutasi/keluar, dan Surat Keterangan Kehadiran. */
class LifecycleController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    public function index(YearClosure $closure): View
    {
        $school = $this->tenant->get();

        return view('portal.lifecycle.year', [
            'school' => $school,
            'p' => $closure->preview($school),
            'classrooms' => Classroom::query()->orderBy('name')->get(['id', 'name', 'grade']),
            'history' => AcademicYearClosure::query()->with('creator:id,name')->latest('id')->limit(10)->get(),
            'twoFactor' => (bool) request()->user()->two_factor_confirmed_at,
        ]);
    }

    public function close(Request $request, YearClosure $closure): RedirectResponse
    {
        $data = $request->validate([
            'mapping' => ['required', 'array'], 'mapping.*' => ['required', 'string', 'max:20'],
            'repeaters' => ['nullable', 'array'], 'repeaters.*' => ['integer'],
            'confirm' => ['required', 'string', 'max:20'],
        ], [], ['confirm' => 'konfirmasi']);
        $this->confirm($request, $data['confirm']);

        $r = $closure->execute($this->tenant->get(), $data['mapping'], $data['repeaters'] ?? [], $request->user());
        $s = $r->summary;

        return redirect()->route('portal.year')->with('ok', "Tahun ajaran {$r->to_year} dimulai: {$s['naik']} naik kelas, {$s['tinggal']} tinggal kelas, {$s['lulus']} lulus. Bisa dibatalkan sampai ".AcademicYearClosure::UNDO_DAYS.' hari ke depan.');
    }

    public function undo(Request $request, YearClosure $closure, AcademicYearClosure $academicYearClosure): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'string', 'max:20']], [], ['confirm' => 'konfirmasi']);
        $this->confirm($request, $request->input('confirm'));
        $closure->undo($this->tenant->get(), $academicYearClosure, $request->user());

        return redirect()->route('portal.year')->with('ok', "Penutupan {$academicYearClosure->from_year} dibatalkan. Kelas siswa kembali seperti sebelumnya.");
    }

    /** Langkah berisiko: kode 2FA bila aktif, kalau tidak ketik TUTUP. */
    private function confirm(Request $request, string $input): void
    {
        $u = $request->user();
        if ($u->two_factor_confirmed_at) {
            $step = app(Totp::class)->verify((string) $u->two_factor_secret, preg_replace('/\s+/', '', $input), $u->two_factor_last_step);
            if ($step === null) {
                throw ValidationException::withMessages(['confirm' => 'Kode 2FA salah. Masukkan kode terbaru dari aplikasi authenticator.']);
            }
            $u->forceFill(['two_factor_last_step' => $step])->save();

            return;
        }
        if (strtoupper(trim($input)) !== 'TUTUP') {
            throw ValidationException::withMessages(['confirm' => 'Ketik TUTUP untuk mengonfirmasi.']);
        }
    }

    public function studentExit(Request $request, Exits $exits, Student $student): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(Exits::STUDENT_TYPES))],
            'date' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $exits->student($this->tenant->get(), $student, $data['type'], $data['date'], $data['reason'] ?? null, $request->user());

        return back()->with('ok', "{$student->name} dicatat: ".Exits::STUDENT_TYPES[$data['type']].'. Akun dinonaktifkan; riwayat kehadiran tetap tersimpan.');
    }

    public function studentReactivate(Request $request, Exits $exits, Student $student): RedirectResponse
    {
        $data = $request->validate(['classroom_id' => ['nullable', 'integer']]);
        $exits->reactivateStudent($this->tenant->get(), $student, $request->user(), $data['classroom_id'] ?? null);

        return back()->with('ok', "{$student->name} aktif kembali.");
    }

    public function teacherExit(Request $request, Exits $exits, Teacher $teacher): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(Exits::TEACHER_TYPES))],
            'date' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $exits->teacher($this->tenant->get(), $teacher, $data['type'], $data['date'], $data['reason'] ?? null, $request->user());

        return back()->with('ok', "{$teacher->name} dicatat: ".Exits::TEACHER_TYPES[$data['type']].'. Akun dinonaktifkan; jurnal & absensinya tetap tersimpan.');
    }

    public function teacherReactivate(Request $request, Exits $exits, Teacher $teacher): RedirectResponse
    {
        $exits->reactivateTeacher($this->tenant->get(), $teacher, $request->user());

        return back()->with('ok', "{$teacher->name} aktif kembali.");
    }

    /** Mutasi masuk: siswa pindahan langsung ke kelas tujuan, password awal acak ditampilkan sekali. */
    public function transferIn(Request $request, \App\Services\Platform\AuditLogger $audit): RedirectResponse
    {
        $school = $this->tenant->get();
        $request->merge(['nis' => preg_replace('/\s+/', '', (string) $request->input('nis'))]);
        $d = $request->validate([
            'nis' => ['required', 'string', 'max:32', \Illuminate\Validation\Rule::unique('students')->where('school_id', $school->id),
                \Illuminate\Validation\Rule::unique('users', 'username')->where('school_id', $school->id)],
            'nisn' => ['nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', 'in:L,P'],
            'classroom_id' => ['required', 'integer'],
            'origin' => ['nullable', 'string', 'max:120'],
        ], [], ['nis' => 'NIS', 'name' => 'nama', 'classroom_id' => 'kelas', 'origin' => 'asal sekolah']);
        $class = Classroom::query()->find($d['classroom_id']);
        if (! $class) {
            throw ValidationException::withMessages(['classroom_id' => 'Kelas tidak ditemukan.']);
        }
        $quota = $school->effectiveStudentQuota();
        if ($quota !== null && Student::query()->where('is_active', true)->count() >= $quota) {
            throw ValidationException::withMessages(['nis' => "Kuota paket penuh ({$quota} siswa). Tingkatkan paket untuk menambah siswa."]);
        }

        $pw = strtolower(\Illuminate\Support\Str::password(8, symbols: false));
        $student = \Illuminate\Support\Facades\DB::transaction(function () use ($d, $pw, $school) {
            $u = \App\Models\User::create(['role' => \App\Enums\Role::Student, 'username' => $d['nis'], 'name' => $d['name'], 'password' => $pw, 'must_change_password' => true]);
            $s = Student::create(['user_id' => $u->id, 'nis' => $d['nis'], 'nisn' => $d['nisn'] ?? null, 'name' => $d['name'], 'gender' => $d['gender'] ?? null, 'classroom_id' => $d['classroom_id']]);
            \App\Models\StudentEnrollment::query()->where('student_id', $s->id)->where('academic_year', $school->currentAcademicYear())->update(['status' => 'mutasi_masuk']);

            return $s;
        });
        $audit->log('student.transfer_in', "Siswa pindahan {$student->nis} masuk kelas {$class->name}".(! empty($d['origin']) ? " dari {$d['origin']}" : ''), $school, $request->user(), ['student_id' => $student->id]);

        return back()->with('ok', "{$student->name} terdaftar di kelas {$class->name}. Bagikan password awal di bawah; siswa wajib menggantinya saat login pertama.")
            ->with('temp_password', ['name' => $student->name, 'username' => $student->nis, 'password' => $pw]);
    }

    /** Arsip tahun ajaran yang ditutup: daftar siswa, status kenaikan, kelas baru, dan rekap kehadiran (CSV). */
    public function exportClosure(AcademicYearClosure $academicYearClosure)
    {
        $c = $academicYearClosure;
        [$a] = explode('/', $c->from_year);
        $from = \App\Models\StudentEnrollment::query()->with('student:id,nis,nisn,name')->where('academic_year', $c->from_year)->orderBy('classroom_name')->get();
        $to = \App\Models\StudentEnrollment::query()->where('academic_year', $c->to_year)->pluck('classroom_name', 'student_id');
        $att = Attendance::query()->whereBetween('date', [$a.'-07-01', ((int) $a + 1).'-06-30'])
            ->selectRaw('student_id, status, count(*) as n')->groupBy('student_id', 'status')->get()
            ->groupBy('student_id')->map(fn ($g) => $g->pluck('n', 'status'));
        $label = ['naik' => 'Naik kelas', 'tinggal' => 'Tinggal kelas', 'lulus' => 'Lulus', 'aktif' => 'Tetap', 'mutasi_masuk' => 'Mutasi masuk', 'mutasi_keluar' => 'Mutasi keluar', 'keluar' => 'Keluar', 'nonaktif' => 'Nonaktif'];
        $rows = $from->map(function ($e) use ($to, $att, $label) {
            $s = $att[$e->student_id] ?? collect();
            $v = fn (AttendanceStatus $st) => (int) ($s[$st->value] ?? 0);

            return [$e->student?->nis, $e->student?->nisn, $e->student?->name, $e->classroom_name, $label[$e->status] ?? $e->status, $to[$e->student_id] ?? '',
                $v(AttendanceStatus::Present), $v(AttendanceStatus::Sick), $v(AttendanceStatus::Permit), $v(AttendanceStatus::Absent)];
        });

        return \App\Support\Csv::download('arsip-'.str_replace('/', '-', $c->from_year).'.csv',
            ['NIS', 'NISN', 'Nama', 'Kelas '.$c->from_year, 'Status', 'Kelas '.$c->to_year, 'Hadir', 'Sakit', 'Izin', 'Alpa'], $rows);
    }

    /** Surat Keterangan Kehadiran (untuk mutasi/lulus): rekap H/S/I/A per tahun ajaran. */
    public function certificate(Student $student): View
    {
        $school = $this->tenant->get();
        $rows = $student->enrollments()->orderBy('academic_year')->get();
        $recap = [];
        foreach ($rows as $e) {
            [$a] = explode('/', $e->academic_year);
            $counts = Attendance::query()->where('student_id', $student->id)
                ->whereBetween('date', [$a.'-07-01', ((int) $a + 1).'-06-30'])
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            $n = fn (AttendanceStatus $s) => (int) ($counts[$s->value] ?? 0);
            $total = $counts->sum();
            $recap[] = [
                'year' => $e->academic_year, 'class' => $e->classroom_name ?? '-', 'status' => $e->status,
                'H' => $n(AttendanceStatus::Present), 'S' => $n(AttendanceStatus::Sick), 'I' => $n(AttendanceStatus::Permit), 'A' => $n(AttendanceStatus::Absent),
                'pct' => $total ? round($n(AttendanceStatus::Present) / $total * 100, 1) : null,
            ];
        }

        return view('portal.lifecycle.certificate', [
            'school' => $school, 's' => $student, 'recap' => $recap,
            'principal' => $school->setting('leger.principal_name'), 'nip' => $school->setting('leger.principal_nip'),
            'city' => $school->setting('leger.city'),
            'number' => sprintf('%03d/SKK/%s/%s', $student->id, now()->format('m'), now()->format('Y')),
        ]);
    }
}
