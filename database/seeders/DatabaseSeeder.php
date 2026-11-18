<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Models\Classroom;
use App\Models\LessonPeriod;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Data awal:
 * - Super-admin Parama Tech (domain pusat)
 * - Sekolah demo "demo" (demo.mypresence.test) berisi data dari prototipe:
 *   42 kelas, 54 guru, jadwal aSc, jam KBM, dan 8 siswa contoh.
 */
class DatabaseSeeder extends Seeder
{
    public function run(TenantContext $tenant): void
    {
        User::create([
            'role' => Role::SuperAdmin, 'username' => 'superadmin', 'name' => 'Parama Tech',
            'email' => 'superadmin@mypresence.test', 'password' => env('SUPERADMIN_PASSWORD', 'ganti-password-ini'),
        ]);

        $school = School::create([
            'name' => 'SMK Demo MyPresence', 'subdomain' => 'demo', 'status' => SchoolStatus::Trial,
            'timezone' => 'Asia/Jakarta', 'plan' => 'all_service', 'student_quota' => 500, 'registered_via' => 'seed',
            'npsn' => '20300000', 'contact_name' => 'Admin Sekolah', 'contact_phone' => '081234560000',
            // Sekolah demo langsung terverifikasi agar akun guru & siswa demo bisa dipakai (lihat README).
            'verified_at' => now(),
            'trial_ends_at' => now()->addDays(config('tenancy.trial_days')),
            'settings' => [
                'attendance_cutoff_period' => 1, 'school_days' => [1, 2, 3, 4, 5],
                // QR statis + WiFi sekolah. Localhost hanya untuk uji lokal: di produksi admin
                // menekan "Daftarkan jaringan ini" dari WiFi sekolah, lalu hapus entri ini.
                'attendance' => ['qr_mode' => 'static', 'networks' => [
                    ['cidr' => '127.0.0.1/32', 'label' => 'Localhost (uji lokal)'],
                    ['cidr' => '::1/128', 'label' => 'Localhost IPv6 (uji lokal)'],
                ]],
            ],
        ]);

        $tenant->run($school, function () use ($school) {
            $this->seedDemoSchool();
            $admin = User::where('username', 'admin')->first();
            foreach (['terms', 'privacy', 'dpa'] as $doc) {
                \App\Services\Platform\Legal::record($doc, $school, $admin, $admin->name, 'Operator Sekolah');
            }
        });
    }

    private function seedDemoSchool(): void
    {
        $d = json_decode(file_get_contents(__DIR__.'/data/demo_school.json'), true);

        User::create(['role' => Role::Admin, 'username' => 'admin', 'name' => 'Admin Sekolah', 'email' => 'admin@sekolah.sch.id', 'password' => 'admin123', 'must_change_password' => true])
            ->forceFill(['email_verified_at' => now()])->save();

        foreach ($d['periods'] as $i => [$label, $start, $end, $break]) {
            LessonPeriod::create(['label' => $label, 'starts_at' => $start, 'ends_at' => $end, 'is_break' => (bool) $break, 'sort' => $i + 1]);
        }

        $rooms = collect($d['classrooms'])->mapWithKeys(fn ($name) => [$name => Classroom::create(['name' => $name, 'grade' => strtok($name, '-')])]);

        $teachers = collect($d['teachers'])->map(function ($t) {
            $u = User::create(['role' => Role::Teacher, 'username' => $t['nik'], 'name' => $t['name'], 'password' => 'guru123', 'must_change_password' => true]);

            return Teacher::create(['user_id' => $u->id, 'nik' => $t['nik'], 'name' => $t['name'], 'subject' => $t['subject'], 'position' => 'Guru']);
        });

        foreach ($d['schedules'] as [$k, $day, $start, $count, $subject, $teacher]) {
            Schedule::create([
                'classroom_id' => $rooms[$d['classrooms'][$k]]->id,
                'teacher_id' => $teacher >= 0 ? $teachers[$teacher]->id : null,
                'subject' => $d['subjects'][$subject], 'day' => $day + 1, 'period_start' => $start, 'period_count' => $count,
            ]);
        }

        $rooms['X-FKK 1']->update(['homeroom_teacher_id' => $teachers[0]->id]);

        foreach ($d['students'] as [$nis, $name, $room, $birth]) {
            $u = User::create(['role' => Role::Student, 'username' => $nis, 'name' => $name, 'password' => $birth, 'must_change_password' => true]);
            Student::create(['user_id' => $u->id, 'nis' => $nis, 'name' => $name, 'classroom_id' => $rooms[$room]->id, 'birth_date' => $birth]);
        }
    }
}
