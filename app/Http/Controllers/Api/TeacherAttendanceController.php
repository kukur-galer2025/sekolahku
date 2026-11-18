<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Services\DeviceBinding;
use App\Services\Platform\AuditLogger;
use App\Services\TeacherAttendanceService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Absensi guru: ① hadir di sekolah, ② masuk kelas sesuai jadwal; rekap & catat manual untuk admin. */
class TeacherAttendanceController extends Controller
{
    public function __construct(private TeacherAttendanceService $svc, private TenantContext $tenant, private DeviceBinding $devices) {}

    private function teacher(Request $request): Teacher
    {
        return $request->user()->teacher ?? abort(403, 'Akun ini tidak terhubung ke data guru.');
    }

    public function mine(Request $request): JsonResponse
    {
        $school = $this->tenant->get();

        return response()->json($this->svc->mine($school, $this->teacher($request)) + [
            'on_school_network' => app(\App\Services\SchoolNetwork::class)->allows($school, $request->ip()),
            'on_registered_device' => (bool) $this->devices->current($request->user(), $request),
        ]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $device = $this->devices->requireForScan($request->user(), $request);
        $a = $this->svc->checkIn($this->tenant->get(), $this->teacher($request), $d['code'], $request->ip(), $device);

        return response()->json(['message' => 'Kehadiran di sekolah tercatat pukul '.$a->checked_in_at->setTimezone($this->tenant->get()->timezone)->format('H:i').'. Sekarang kamu bisa absen masuk kelas.']);
    }

    public function enter(Request $request): JsonResponse
    {
        $d = $request->validate(['schedule_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:40']]);
        $this->devices->requireForScan($request->user(), $request);
        $schedule = Schedule::query()->with('classroom:id,name')->findOrFail($d['schedule_id']);
        $e = $this->svc->enterClass($this->tenant->get(), $this->teacher($request), $schedule, $d['code'], $request->ip());

        return response()->json(['message' => 'Masuk kelas '.$schedule->classroom?->name.' tercatat'.($e->is_late ? ' (terlambat).' : '.')]);
    }

    // ── Admin ────────────────────────────────────────────────────────

    private function date(Request $request): CarbonImmutable
    {
        $school = $this->tenant->get();
        $d = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? null;

        return $d ? CarbonImmutable::parse($d, $school->timezone) : $school->localNow();
    }

    public function report(Request $request): JsonResponse
    {
        return response()->json($this->svc->report($this->tenant->get(), $this->date($request)));
    }

    public function manual(Request $request, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['teacher_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $t = Teacher::query()->findOrFail($d['teacher_id']);
        $this->svc->manualCheckIn($this->tenant->get(), $t, $d['date'], $request->user());
        $audit->log('teacher.checkin_manual', "Kehadiran guru {$t->name} tanggal {$d['date']} dicatat manual", $this->tenant->get());

        return response()->json(['message' => "Kehadiran {$t->name} dicatat manual."]);
    }

    public function manualEnter(Request $request, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate(['schedule_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $s = Schedule::query()->with(['teacher', 'classroom:id,name'])->findOrFail($d['schedule_id']);
        $this->svc->manualEnter($this->tenant->get(), $s, $d['date'], $request->user());
        $audit->log('teacher.enter_manual', "Masuk kelas {$s->classroom?->name} ({$s->teacher?->name}) tanggal {$d['date']} dicatat manual", $this->tenant->get());

        return response()->json(['message' => 'Masuk kelas dicatat manual.']);
    }

    public function gate(): JsonResponse
    {
        return response()->json(['payload' => $this->svc->gatePayload($this->tenant->get())]);
    }

    public function gateSvg(): Response
    {
        $o = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG, 'outputBase64' => false,
            'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M, 'addQuietzone' => true, 'svgUseFillAttributes' => true]);

        return response((new \chillerlan\QRCode\QRCode($o))->render($this->svc->gatePayload($this->tenant->get())), 200, [
            'Content-Type' => 'image/svg+xml', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function rotateGate(AuditLogger $audit): JsonResponse
    {
        $this->svc->rotateGate($this->tenant->get());
        $audit->log('teacher.gate_rotated', 'QR kehadiran guru diperbarui (QR lama tidak berlaku)', $this->tenant->get());

        return response()->json(['message' => 'QR kehadiran guru diperbarui. Cetak dan tempel ulang QR yang baru.', 'payload' => $this->svc->gatePayload($this->tenant->get())]);
    }
}
