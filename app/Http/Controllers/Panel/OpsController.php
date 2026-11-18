<?php

namespace App\Http\Controllers\Panel;

use App\Enums\SchoolStatus;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Ops\DatabaseBackup;
use App\Services\Ops\HealthCheck;
use App\Services\Platform\AuditLogger;
use App\Services\Platform\SchoolBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/** Tahap 7 — Operasional: kesehatan server, konfigurasi, backup database & sekolah, data yang menunggu dihapus, SSL. */
class OpsController extends Controller
{
    public function index(HealthCheck $health, DatabaseBackup $db, SchoolBackup $schoolBackup): View
    {
        $schools = School::query()->orderBy('name')->get();
        $noBackup = $schools->filter(function (School $s) use ($schoolBackup) {
            $last = $schoolBackup->list($s)[0]['time'] ?? null;

            return $s->status !== SchoolStatus::Suspended && (! $last || now()->getTimestamp() - $last > 26 * 3600);
        })->values();

        return view('panel.ops', [
            'health' => $health->run(),
            'config' => $this->configChecks(),
            'dbBackups' => $db->list(),
            'dbStale' => $db->stale(),
            'noBackup' => $noBackup,
            'schoolCount' => $schools->count(),
            'expired' => $schools->filter(fn (School $s) => $s->status === SchoolStatus::Suspended && $s->suspension_type === 'billing'
                && $s->suspended_at && $s->suspended_at->lt(now()->subDays(30)))->values(),
            'deletion' => $schools->whereNotNull('deletion_requested_at')->values(),
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->latest('failed_at')->limit(5)->get(['id', 'queue', 'failed_at', 'exception']) : collect(),
            'ssl' => Cache::get('ops:wildcard-ssl'),
        ]);
    }

    /** @return list<array{0:string,1:bool,2:string}> [label, ok, detail] */
    private function configChecks(): array
    {
        $https = str_starts_with((string) config('app.url'), 'https://');

        return [
            ['APP_ENV=production & APP_DEBUG=false', app()->environment('production') && ! config('app.debug'), app()->environment().' / debug '.(config('app.debug') ? 'ON' : 'off')],
            ['APP_URL memakai https', $https, (string) config('app.url')],
            ['BACKUP_PASSWORD diisi (backup terenkripsi)', (string) config('tenancy.backup_password') !== '', (string) config('tenancy.backup_password') !== '' ? 'terisi' : 'KOSONG — backup tidak terenkripsi'],
            ['Kunci VAPID (push ke HP)', \App\Services\Notify\WebPush::configured(), \App\Services\Notify\WebPush::configured() ? 'ada' : 'jalankan php artisan push:vapid --write'],
            ['Email terkirim sungguhan', ! in_array(config('mail.default'), ['log', 'array'], true), (string) config('mail.default')],
            ['2FA wajib admin sekolah', (bool) config('tenancy.school_admin_2fa'), config('tenancy.school_admin_2fa') ? 'aktif' : 'mati'],
            ['2FA super-admin', (bool) config('tenancy.superadmin_2fa'), config('tenancy.superadmin_2fa') ? 'aktif' : 'mati'],
            ['HEALTH_TOKEN untuk monitor uptime', (string) config('app.health_token') !== '', (string) config('app.health_token') !== '' ? 'terisi' : 'kosong — /health tidak bisa dipantau'],
            ['Antrean berjalan', config('queue.default') === 'sync' || (bool) config('queue.via_scheduler'), config('queue.default').(config('queue.via_scheduler') ? ' + worker via scheduler' : '')],
            ['Cookie sesi hanya HTTPS', ! $https || (bool) config('session.secure'), config('session.secure') ? 'secure' : 'SESSION_SECURE_COOKIE belum true'],
        ];
    }

    public function backupNow(Request $request, DatabaseBackup $db): RedirectResponse
    {
        $r = $db->create($request->user()->name.' (Parama Tech)');

        return back()->with('ok', "Backup {$r['name']} dibuat: {$r['tables']} tabel, {$r['rows']} baris, ".\App\Services\Platform\SchoolUsage::bytes($r['size']).'.');
    }

    public function download(Request $request, DatabaseBackup $db, AuditLogger $audit, string $name)
    {
        $audit->log('ops.db_backup_downloaded', "Backup database {$name} diunduh oleh {$request->user()->name}", null, $request->user());

        return $db->download($name);
    }

    /** Cek apakah sertifikat wildcard *.domain sudah berlaku: buka TLS ke subdomain acak. */
    public function checkSsl(): RedirectResponse
    {
        $host = 'cek-'.bin2hex(random_bytes(3)).'.'.config('tenancy.central_domain');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true, 'capture_peer_cert' => true]]);
        $errno = 0;
        $err = '';
        $fp = @stream_socket_client("ssl://{$host}:443", $errno, $err, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp) {
            $cert = openssl_x509_parse(stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? '') ?: [];
            fclose($fp);
            $r = ['ok' => true, 'detail' => 'Wildcard SSL aktif · berlaku sampai '.date('d/m/Y', $cert['validTo_time_t'] ?? time()).' ('.($cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? '?').')'];
        } else {
            $r = ['ok' => false, 'detail' => "Belum aktif untuk {$host}: ".($err ?: 'sertifikat tidak cocok').'. Sekolah baru tetap perlu domain manual + AutoSSL.'];
        }
        $r['at'] = now()->toIso8601String();
        Cache::put('ops:wildcard-ssl', $r, now()->addDay());

        return back()->with($r['ok'] ? 'ok' : 'err', $r['detail']);
    }
}
