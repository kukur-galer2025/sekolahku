<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache dengan awalan sekolah: kunci "rekap-hari-ini" milik sekolah 12 disimpan sebagai
 * "school_12:rekap-hari-ini", jadi dua sekolah tidak pernah berbagi isi cache.
 */
class TenantCache
{
    public function __construct(private TenantContext $tenant) {}

    public function key(string $key): string
    {
        $id = $this->tenant->id() ?? throw new TenantViolation('Tidak ada sekolah aktif untuk cache.');

        return 'school_'.$id.':'.$key;
    }

    public function remember(string $key, \DateTimeInterface|\DateInterval|int $ttl, Closure $callback): mixed
    {
        return Cache::remember($this->key($key), $ttl, $callback);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->key($key), $default);
    }

    public function put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int $ttl): bool
    {
        return Cache::put($this->key($key), $value, $ttl);
    }

    public function forget(string $key): bool
    {
        return Cache::forget($this->key($key));
    }
}
