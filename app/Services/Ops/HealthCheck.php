<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pemeriksaan kesehatan produksi untuk monitor uptime (UptimeRobot/BetterStack):
 *   GET https://mypresence.id/health?token=HEALTH_TOKEN  → 200 {"status":"ok"} atau 503 {"status":"fail"}
 * /up (bawaan Laravel) tetap ada untuk cek "aplikasi hidup" tanpa token.
 */
class HealthCheck
{
    public const HEARTBEAT = 'ops:scheduler-heartbeat';

    /** @return array{status:string,checks:array<string,array{ok:bool,detail:string}>} */
    public function run(): array
    {
        $checks = [
            'database' => $this->try(fn () => DB::select('select 1') ? 'tersambung' : throw new \RuntimeException('query gagal')),
            'cache' => $this->try(function () {
                Cache::put('ops:ping', $v = (string) microtime(true), 30);

                return Cache::get('ops:ping') === $v ? Cache::getDefaultDriver() : throw new \RuntimeException('cache tidak konsisten');
            }),
            'scheduler' => $this->try(function () {
                $t = Cache::get(self::HEARTBEAT);
                if (! $t) {
                    throw new \RuntimeException('belum ada heartbeat — cek cron schedule:run');
                }
                $age = now()->getTimestamp() - (int) $t;

                return $age <= 180 ? "detak {$age} dtk lalu" : throw new \RuntimeException("detak terakhir {$age} dtk lalu — cron mati?");
            }),
            'queue' => $this->try(function () {
                $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
                $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
                $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('available_at') : null;
                if ($oldest && now()->getTimestamp() - (int) $oldest > 600) {
                    throw new \RuntimeException("{$pending} job menunggu > 10 menit — worker mati?");
                }

                return "{$pending} menunggu, {$failed} gagal (24 jam)";
            }),
            'storage' => $this->try(function () {
                $disk = Storage::disk(config('tenancy.files_disk', 'local'));
                $disk->put('.health', (string) time());

                return 'bisa ditulis ('.config('tenancy.files_disk', 'local').')';
            }),
            'disk' => $this->try(function () {
                $free = @disk_free_space(storage_path());
                $total = @disk_total_space(storage_path());
                if (! $free || ! $total) {
                    return 'tidak terbaca';
                }
                $pct = (int) round($free / $total * 100);

                return $pct >= 10 ? "sisa {$pct}%" : throw new \RuntimeException("disk hampir penuh (sisa {$pct}%)");
            }),
        ];

        return ['status' => collect($checks)->every(fn ($c) => $c['ok']) ? 'ok' : 'fail', 'checks' => $checks, 'time' => now()->toIso8601String(), 'version' => config('app.version')];
    }

    private function try(callable $fn): array
    {
        try {
            return ['ok' => true, 'detail' => (string) $fn()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
