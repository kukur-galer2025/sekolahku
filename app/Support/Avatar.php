<?php

namespace App\Support;

use App\Models\User;

/**
 * Foto profil yang bisa dilihat sesama pengguna satu sekolah (admin, guru, siswa).
 * URL stabil per foto (/api/avatar/{user}?v=…) agar bisa di-cache browser; berganti otomatis saat foto diganti.
 */
class Avatar
{
    public static function url(?int $userId, ?string $path): ?string
    {
        return $userId && $path ? '/api/avatar/'.$userId.'?v='.substr(md5($path), 0, 10) : null;
    }

    public static function of(?User $u): ?string
    {
        return $u ? self::url($u->id, $u->photo_path) : null;
    }

    /** [user_id => url] untuk banyak pengguna sekaligus (satu kueri). */
    public static function map(iterable $userIds): array
    {
        $ids = collect($userIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->whereNotNull('photo_path')->pluck('photo_path', 'id')
            ->map(fn ($p, $id) => self::url((int) $id, $p))->all();
    }
}
