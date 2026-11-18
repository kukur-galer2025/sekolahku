<?php

namespace Database\Factories;

use App\Enums\SchoolStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\School> */
class SchoolFactory extends Factory
{
    public function definition(): array
    {
        $name = 'SMK '.fake()->unique()->city();

        return [
            'name' => $name,
            'subdomain' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'status' => SchoolStatus::Active,
            // Tes fitur memakai paket penuh; tes batasan paket memilih 'starter'/'pro' secara eksplisit.
            'plan' => 'all_service',
            'timezone' => 'Asia/Jakarta',
            'verified_at' => now(),
            'settings' => [
                'attendance_cutoff_period' => 1,
                // Tes HTTP bawaan Laravel memakai REMOTE_ADDR 127.0.0.1
                'attendance' => ['qr_mode' => 'static', 'networks' => [['cidr' => '127.0.0.1/32', 'label' => 'Tes']]],
            ],
        ];
    }

    /** Sekolah daftar mandiri yang belum dicek Parama Tech. */
    public function unverified(): static
    {
        return $this->state(['verified_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => SchoolStatus::Suspended]);
    }
}
