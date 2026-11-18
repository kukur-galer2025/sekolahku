<?php

namespace App\Tenancy;

use App\Models\School;

/**
 * Menyimpan sekolah (tenant) yang sedang aktif untuk request ini.
 *
 * Sumbernya hanya dua: middleware IdentifyTenant (dari subdomain) atau
 * kode server yang sengaja menjalankan sesuatu atas nama satu sekolah
 * lewat run(). school_id tidak pernah diambil dari input klien.
 */
class TenantContext
{
    private ?School $school = null;

    public function set(School $school): void
    {
        $this->school = $school;
    }

    public function forget(): void
    {
        $this->school = null;
    }

    public function get(): ?School
    {
        return $this->school;
    }

    public function id(): ?int
    {
        return $this->school?->getKey();
    }

    public function check(): bool
    {
        return $this->school !== null;
    }

    /**
     * Jalankan callback dalam konteks satu sekolah, lalu kembalikan konteks sebelumnya.
     * Dipakai oleh job/command terjadwal yang memproses banyak sekolah.
     */
    public function run(School $school, callable $callback): mixed
    {
        $previous = $this->school;
        $this->school = $school;

        try {
            return $callback($school);
        } finally {
            $this->school = $previous;
        }
    }
}
