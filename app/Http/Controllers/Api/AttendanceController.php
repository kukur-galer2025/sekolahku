<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Student;
use App\Services\AttendanceService;
use App\Services\ClassQrCode;
use App\Services\DeviceBinding;
use App\Services\SchoolNetwork;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function __construct(private TenantContext $tenant, private AttendanceService $attendance, private ClassQrCode $qr, private SchoolNetwork $network, private DeviceBinding $devices) {}

    /**
     * Isi QR kelas. Mode statis: untuk dicetak dan ditempel di kelas.
     * Mode dinamis: ditampilkan di layar guru, frontend memanggil ulang saat expires_in habis.
     */
    public function qr(Classroom $classroom): JsonResponse
    {
        return response()->json(
            $this->qr->current($classroom, $this->tenant->get()->qrMode()) + ['classroom' => $classroom->only(['id', 'name'])]
        );
    }

    /** Gambar QR (SVG) untuk ditampilkan/dicetak. Isi QR sama dengan qr()->payload. */
    public function qrSvg(Classroom $classroom): \Illuminate\Http\Response
    {
        $payload = $this->qr->current($classroom, $this->tenant->get()->qrMode())['payload'];
        $o = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG, 'outputBase64' => false,
            'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M, 'addQuietzone' => true, 'svgUseFillAttributes' => true]);

        return response((new \chillerlan\QRCode\QRCode($o))->render($payload), 200, [
            'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** "Perbarui QR": kode lama langsung tidak berlaku. Untuk QR statis, QR harus dicetak ulang. */
    public function rotateQr(Classroom $classroom): JsonResponse
    {
        $classroom->forceFill(['qr_secret' => Str::random(40)])->save();
        $res = $this->qr($classroom)->getData(true);

        if ($res['mode'] === ClassQrCode::STATIC) {
            $res['message'] = 'QR lama tidak berlaku lagi. Cetak dan tempel QR baru di kelas '.$classroom->name.'.';
        }

        return response()->json($res);
    }

    /** Siswa scan QR kelas (atau mengetik kode di bawah QR). */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $student = $request->user()->student;
        abort_unless($student, 403, 'Akun ini tidak terhubung ke data siswa.');

        // Hanya dari HP terdaftar milik siswa ini (1 akun = 1 HP).
        $device = $this->devices->requireForScan($request->user(), $request);

        $a = $this->attendance->scan($this->tenant->get(), $student, $data['code'], $request->ip(), $device);

        return response()->json([
            'message' => $a->is_late ? 'Presensi tercatat, tetapi terlambat.' : 'Presensi tercatat. Selamat belajar!',
            'attendance' => $this->present($a),
        ]);
    }

    /** Status presensi siswa yang login: hari ini, batas scan, dan riwayat 30 hari. */
    public function mine(Request $request): JsonResponse
    {
        $student = $request->user()->student;
        abort_unless($student, 403, 'Akun ini tidak terhubung ke data siswa.');
        $school = $this->tenant->get();
        $today = $school->localNow()->startOfDay();

        $history = $student->attendances()->orderByDesc('date')->limit(30)->get();

        return response()->json([
            'date' => $today->toDateString(),
            'is_school_day' => $school->isSchoolDay($today),
            'cutoff' => $this->attendance->cutoff($school, $today)->format('H:i'),
            'cutoff_period' => $school->attendanceCutoffPeriod(),
            // Agar PWA bisa mengingatkan "Sambungkan ke WiFi sekolah" sebelum siswa membuka kamera.
            'on_school_network' => $this->network->allows($school, $request->ip()),
            'on_registered_device' => $this->devices->current($request->user(), $request) !== null,
            'scan_windows' => $student->classroom
                ? array_map(fn ($w) => ['from' => $w[0]->format('H:i'), 'to' => $w[1]->format('H:i')], $this->attendance->lessonWindows($school, $student->classroom, $today))
                : [],
            'today' => ($t = $history->firstWhere('date', $today->toDateString())) ? $this->present($t) : null,
            'history' => $history->map(fn ($a) => $this->present($a)),
        ]);
    }

    /** Rekap satu kelas pada satu tanggal (guru/admin). */
    public function classroom(Request $request, Classroom $classroom): JsonResponse
    {
        $school = $this->tenant->get();
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $school->localNow()->toDateString();

        $students = $classroom->students()->where('is_active', true)->orderBy('name')->get();
        $records = Attendance::query()->whereIn('student_id', $students->pluck('id'))->whereDate('date', $date)->get()->keyBy('student_id');

        $rows = $students->map(fn (Student $s) => [
            'student' => $s->only(['id', 'nis', 'name']),
            'attendance' => ($r = $records->get($s->id)) ? $this->present($r) : null,
        ]);

        $count = fn ($st) => $records->filter(fn ($r) => $r->status === $st)->count();

        return response()->json([
            'classroom' => $classroom->only(['id', 'name']),
            'date' => $date,
            'summary' => [
                'H' => $count(AttendanceStatus::Present), 'S' => $count(AttendanceStatus::Sick),
                'I' => $count(AttendanceStatus::Permit), 'A' => $count(AttendanceStatus::Absent),
                'belum' => $students->count() - $records->count(), 'total' => $students->count(),
            ],
            'rows' => $rows,
        ]);
    }

    /** Koreksi guru/admin: hanya ke H, S, atau I. */
    public function correct(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(AttendanceStatus::correctable())],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        abort_unless($this->canCorrect($request->user(), $student), 403, 'Koreksi presensi hanya untuk guru yang mengajar atau menjadi wali kelas siswa ini.');

        $a = $this->attendance->correct($this->tenant->get(), $student, AttendanceStatus::from($data['status']), $request->user(), $data['note'] ?? null);

        return response()->json(['attendance' => $this->present($a)]);
    }

    /** Admin: semua siswa. Guru: siswa di kelas yang ia walikan atau ajar menurut Jadwal. */
    private function canCorrect(\App\Models\User $u, Student $student): bool
    {
        if ($u->role === Role::Admin) {
            return true;
        }
        $teacherId = $u->teacher?->id;
        if (! $teacherId || ! $student->classroom_id) {
            return false;
        }

        return Classroom::query()->whereKey($student->classroom_id)->where('homeroom_teacher_id', $teacherId)->exists()
            || \App\Models\Schedule::query()->where('classroom_id', $student->classroom_id)->where('teacher_id', $teacherId)->exists();
    }

    private function present(Attendance $a): array
    {
        return [
            'date' => $a->date,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'method' => $a->method->value,
            'time' => $a->scanned_at?->setTimezone($this->tenant->get()->timezone)->format('H:i'),
            'is_late' => $a->is_late,
            'note' => $a->note,
        ];
    }
}
