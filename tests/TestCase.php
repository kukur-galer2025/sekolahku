<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Classroom;
use App\Models\LessonPeriod;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceBinding;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** URL lengkap untuk subdomain sekolah, mis. http://smkn1.mypresence.test/api/me */
    protected function at(School $school, string $path): string
    {
        return 'http://'.$school->subdomain.'.'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    /**
     * Sekolah lengkap untuk pengujian: jam KBM (jam ke-1 berakhir 08:30), satu kelas,
     * satu admin, satu guru, dan satu siswa.
     *
     * @return array{school:School,admin:User,teacher:Teacher,classroom:Classroom,student:Student,deviceToken:string}
     */
    protected function makeSchool(array $attrs = []): array
    {
        $school = School::factory()->create($attrs);

        return $this->tenant()->run($school, function () use ($school) {
            LessonPeriod::create(['label' => '1', 'starts_at' => '07:55', 'ends_at' => '08:30', 'sort' => 1]);
            LessonPeriod::create(['label' => '2', 'starts_at' => '08:30', 'ends_at' => '09:05', 'sort' => 2]);

            $admin = User::factory()->create(['role' => Role::Admin, 'username' => 'admin', 'password' => 'rahasia123']);
            $tu = User::factory()->create(['role' => Role::Teacher, 'username' => '3301010101', 'password' => 'rahasia123']);
            $teacher = Teacher::create(['user_id' => $tu->id, 'nik' => '3301010101', 'name' => $tu->name]);
            $classroom = Classroom::create(['name' => 'X-FKK 1']);
            $su = User::factory()->create(['role' => Role::Student, 'username' => '1001', 'password' => '2009-05-12']);
            $student = Student::create(['user_id' => $su->id, 'nis' => '1001', 'name' => 'Aulia Rahma', 'classroom_id' => $classroom->id]);

            // Relasi dimuat di dalam konteks tenant; di luar konteks, lazy-load sengaja menghasilkan null.
            $teacher->setRelation('user', $tu);
            $student->setRelation('user', $su);

            // Sekolah sudah menyetujui Perjanjian Pemrosesan Data versi terbaru.
            \App\Services\Platform\Legal::record('dpa', $school, $admin, $admin->name, 'Kepala Sekolah');

            // HP siswa yang sudah terdaftar (seperti setelah login pertama).
            $deviceToken = str_repeat('d', 32).$school->subdomain;
            UserDevice::create(['user_id' => $su->id, 'token_hash' => hash('sha256', $deviceToken), 'name' => 'Android · Chrome']);

            return compact('school', 'admin', 'teacher', 'classroom', 'student', 'deviceToken');
        });
    }

    /** Request dari HP siswa yang terdaftar (cookie perangkat terenkripsi seperti di browser). */
    protected function fromDevice(string $token): static
    {
        return $this->withCredentials()->withCookie(DeviceBinding::COOKIE, $token);
    }
}
