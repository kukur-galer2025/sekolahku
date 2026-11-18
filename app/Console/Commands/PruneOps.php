<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Bersihkan data operasional yang menumpuk: notifikasi lama dan log aktivitas melewati masa simpan. */
class PruneOps extends Command
{
    protected $signature = 'ops:prune {--dry-run}';

    protected $description = 'Hapus notifikasi lonceng > 120 hari (sudah dibaca) / > 365 hari, dan log aktivitas > AUDIT_KEEP_DAYS';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $notif = DB::table('user_notifications')->where(fn ($q) => $q->where(fn ($w) => $w->whereNotNull('read_at')->where('created_at', '<', now()->subDays(120)))
            ->orWhere('created_at', '<', now()->subDays(365)));
        $keep = max(365, (int) config('tenancy.audit_keep_days', 730));
        $logs = DB::table('audit_logs')->where('created_at', '<', now()->subDays($keep));

        $n1 = $dry ? $notif->count() : $notif->delete();
        $n2 = $dry ? $logs->count() : $logs->delete();
        $this->line(($dry ? '[uji] ' : '')."{$n1} notifikasi, {$n2} log aktivitas (> {$keep} hari) dihapus");

        return self::SUCCESS;
    }
}
