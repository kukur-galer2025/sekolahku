<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email peringatan error produksi ke tim Parama Tech (OPS_ALERT_EMAIL).
 * Error yang sama (kelas + file + baris) dikirim maksimal sekali per 15 menit agar inbox tidak banjir.
 * Detail lengkap tetap di storage/logs/laravel-YYYY-MM-DD.log.
 */
class ErrorAlert
{
    public static function report(Throwable $e): void
    {
        $to = config('app.ops_alert_email');
        if (! $to || app()->environment(['local', 'testing'])) {
            return;
        }
        try {
            $sig = sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine());
            if (! Cache::add('ops:alert:'.$sig, 1, now()->addMinutes(15))) {
                return;
            }
            $req = app()->runningInConsole() ? null : request();
            $body = implode("\n", array_filter([
                'Waktu   : '.now()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s').' WIB',
                'Error   : '.get_class($e),
                'Pesan   : '.mb_substr($e->getMessage(), 0, 500),
                'Lokasi  : '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine(),
                $req ? 'URL     : '.$req->method().' '.$req->fullUrl() : 'Konteks : '.implode(' ', $_SERVER['argv'] ?? ['console']),
                $req?->user() ? 'Pengguna: #'.$req->user()->id.' (sekolah #'.($req->user()->school_id ?? '-').')' : null,
                '',
                'Jejak (5 teratas):',
                collect($e->getTrace())->take(5)->map(fn ($f) => '  '.str_replace(base_path().'/', '', $f['file'] ?? '?').':'.($f['line'] ?? '?').' '.($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? ''))->implode("\n"),
                '',
                'Error serupa tidak dikirim ulang selama 15 menit. Log lengkap: storage/logs/.',
            ]));
            Mail::raw($body, fn ($m) => $m->to($to)->subject('[MyPresence] '.class_basename($e).': '.mb_substr($e->getMessage(), 0, 80)));
        } catch (Throwable) {
            // Alert tidak boleh memicu error baru.
        }
    }
}
