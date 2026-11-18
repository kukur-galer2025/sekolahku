<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\SchoolNetwork;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengaturan presensi oleh admin sekolah: mode QR dan daftar jaringan WiFi sekolah.
 *
 * Cara termudah untuk admin: buka MyPresence dari laptop/HP yang tersambung ke WiFi
 * sekolah, lalu tekan "Daftarkan jaringan ini" (POST .../register-current).
 */
class AttendanceSettingsController extends Controller
{
    public function __construct(private TenantContext $tenant, private SchoolNetwork $network) {}

    public function show(Request $request): JsonResponse
    {
        $school = $this->tenant->get();
        $ip = $request->ip();

        return response()->json([
            'qr_mode' => $school->qrMode(),
            'cutoff_period' => $school->attendanceCutoffPeriod(),
            'restrict_to_lessons' => (bool) $school->setting('attendance.restrict_to_lessons', true),
            'early_minutes' => (int) $school->setting('attendance.early_minutes', 30),
            'device_limits' => [
                'student' => (int) $school->setting('devices.student', 1),
                'teacher' => (int) $school->setting('devices.teacher', 2),
            ],
            'networks' => $this->network->networks($school),
            'current_ip' => $ip,
            'current_range' => $ip ? $this->network->rangeFor($ip) : null,
            'current_ip_allowed' => $this->network->allows($school, $ip),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'qr_mode' => ['sometimes', Rule::in(['static', 'dynamic'])],
            'cutoff_period' => ['sometimes', 'integer', 'min:1', 'max:15'],
            'restrict_to_lessons' => ['sometimes', 'boolean'],
            'early_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'device_limits.student' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'device_limits.teacher' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'networks' => ['sometimes', 'array', 'max:20'],
            'networks.*.cidr' => ['required', 'string', 'max:64', function ($attr, $value, $fail) {
                if (! $this->network->isValidCidr($value)) {
                    $fail('Format IP/rentang tidak valid atau terlalu lebar (IPv4 minimal /16, IPv6 minimal /40).');
                }
            }],
            'networks.*.label' => ['nullable', 'string', 'max:60'],
        ]);

        $school = $this->tenant->get();
        $settings = $school->settings ?? [];

        if (array_key_exists('qr_mode', $data)) {
            data_set($settings, 'attendance.qr_mode', $data['qr_mode']);
        }
        if (array_key_exists('cutoff_period', $data)) {
            $settings['attendance_cutoff_period'] = $data['cutoff_period'];
        }
        if (array_key_exists('networks', $data)) {
            data_set($settings, 'attendance.networks', $this->normalize($data['networks']));
        }
        foreach (['restrict_to_lessons', 'early_minutes'] as $k) {
            if (array_key_exists($k, $data)) {
                data_set($settings, "attendance.$k", $data[$k]);
            }
        }
        foreach (['student', 'teacher'] as $role) {
            if (isset($data['device_limits'][$role])) {
                data_set($settings, "devices.$role", $data['device_limits'][$role]);
            }
        }

        $this->save($school, $settings);

        return $this->show($request);
    }

    /** Daftarkan IP publik perangkat admin saat ini sebagai jaringan WiFi sekolah. */
    public function registerCurrent(Request $request): JsonResponse
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:60']]);
        $school = $this->tenant->get();
        $ip = $request->ip();
        abort_unless($ip, 422, 'IP perangkat tidak terbaca.');

        $networks = $this->network->networks($school);
        $range = $this->network->rangeFor($ip);

        if (! in_array($range, array_column($networks, 'cidr'), true)) {
            $networks[] = ['cidr' => $range, 'label' => $data['label'] ?? 'WiFi sekolah'];
        }

        $settings = $school->settings ?? [];
        data_set($settings, 'attendance.networks', $this->normalize($networks));
        $this->save($school, $settings);

        return $this->show($request);
    }

    private function normalize(array $networks): array
    {
        return collect($networks)
            ->map(fn ($n) => ['cidr' => trim($n['cidr']), 'label' => $n['label'] ?? null])
            ->unique('cidr')->values()->all();
    }

    private function save(School $school, array $settings): void
    {
        $school->forceFill(['settings' => $settings])->save();
    }
}
