<?php

namespace App\Services\Notify;

use App\Enums\Role;
use App\Jobs\SendWebPush;
use App\Models\PushSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu pintu notifikasi: tulis ke lonceng semua penerima, lalu bangunkan HP yang mengizinkan push.
 * Harus dipanggil di dalam konteks tenant. Isi tidak memuat data pribadi siswa (cukup jumlah/judul).
 */
class Notifier
{
    /** Jenis notifikasi yang tetap dikirim ke alumni. */
    public const ALUMNI_TYPES = ['graduated', 'alumni_ending'];

    /**
     * Jenis notifikasi → kunci fitur paket. Bila fitur tidak termasuk paket sekolah, notifikasinya tidak dikirim
     * (menu sudah tersembunyi, jadi tautannya tidak bisa dibuka) — selaras dengan Panel → Paket & harga.
     */
    public const TYPE_FEATURES = ['assignment' => 'tugas', 'submission' => 'tugas', 'agenda' => 'agenda', 'cbt' => 'cbt', 'class_cash' => 'kas', 'library' => 'library',
        'habit' => 'pembiasaan', 'class_role' => 'struktur'];

    public function __construct(private TenantContext $tenant) {}

    /** @param  iterable<User>|Collection<int,User>  $users */
    public function send(iterable $users, string $type, string $title, ?string $body = null, ?string $url = null): int
    {
        $school = $this->tenant->get();
        if (! $school) {
            return 0;
        }
        if (isset(self::TYPE_FEATURES[$type]) && ! \App\Support\Plan::allows($school->plan, self::TYPE_FEATURES[$type])) {
            return 0;
        }
        $users = collect($users)->filter(fn ($u) => $u && $u->is_active && (int) $u->school_id === $school->id)->unique('id')->values();
        // Alumni (siswa lulus) hanya menerima kabar tentang akunnya sendiri, bukan informasi/tugas sekolah.
        if (! in_array($type, self::ALUMNI_TYPES, true)) {
            $alumni = \App\Models\Student::query()->whereIn('user_id', $users->where('role', Role::Student)->pluck('id'))->where('status', '!=', 'aktif')->pluck('user_id')->flip();
            $users = $users->reject(fn ($u) => $alumni->has($u->id))->values();
        }
        if ($users->isEmpty()) {
            return 0;
        }

        $now = now();
        foreach ($users->chunk(500) as $chunk) {
            DB::table('user_notifications')->insert($chunk->map(fn (User $u) => [
                'school_id' => $school->id, 'user_id' => $u->id, 'type' => $type,
                'title' => mb_substr($title, 0, 160), 'body' => $body !== null ? mb_substr($body, 0, 500) : null,
                'url' => $url !== null ? $this->urlFor($u, $url) : null, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        }

        if (WebPush::configured()) {
            // Jam tenang: siswa tidak dibangunkan 21.00–05.00 (lonceng tetap terisi).
            $hour = (int) $school->localNow()->format('G');
            $quiet = $hour >= 21 || $hour < 5;
            $ids = $users->reject(fn ($u) => $quiet && $u->role === Role::Student)->pluck('id');
            $subs = PushSubscription::query()->whereIn('user_id', $ids)->pluck('id');
            foreach ($subs->chunk(200) as $chunk) {
                config('services.webpush.delivery') === 'queue'
                    ? SendWebPush::dispatch($school->id, $chunk->values()->all())
                    : SendWebPush::dispatchAfterResponse($school->id, $chunk->values()->all());
            }
        }

        return $users->count();
    }

    /** Kirim ke semua pengguna aktif dengan peran tertentu (admin/teacher/student) di sekolah ini. */
    public function toRoles(array $roles, string $type, string $title, ?string $body = null, ?string $url = null, ?int $exceptUserId = null): int
    {
        return $this->send(User::query()->whereIn('role', $roles)->where('is_active', true)->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))->get(), $type, $title, $body, $url);
    }

    /** "#/tugas" → "/siswa#/tugas" sesuai aplikasi penerima. */
    private function urlFor(User $u, string $url): string
    {
        return str_starts_with($url, '#')
            ? '/'.\App\Http\Controllers\Portal\PwaController::appForRole($u->role->value).$url
            : $url;
    }
}
