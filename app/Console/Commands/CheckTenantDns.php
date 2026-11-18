<?php

namespace App\Console\Commands;

use App\Models\School;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Memastikan DNS wildcard sudah benar setelah domain diarahkan ke VPS:
 *
 *   php artisan tenancy:dns-check --expect=203.0.113.10
 *
 * Mengecek domain pusat, subdomain acak (membuktikan wildcard *.domain aktif),
 * dan subdomain setiap sekolah yang terdaftar.
 */
class CheckTenantDns extends Command
{
    protected $signature = 'tenancy:dns-check
        {--expect= : IP publik VPS yang seharusnya dituju (IPv4)}
        {--schools : Cek juga subdomain semua sekolah terdaftar}';

    protected $description = 'Cek apakah domain pusat dan wildcard subdomain sudah mengarah ke server ini';

    /** @var callable(string): list<string> */
    private $resolver;

    public function handle(): int
    {
        $this->resolver ??= fn (string $host) => array_values(array_unique(gethostbynamel($host) ?: []));

        $domain = config('tenancy.central_domain');
        $expect = $this->option('expect');
        $hosts = [
            $domain => 'Domain pusat',
            'www.'.$domain => 'www',
            'cek-'.Str::lower(Str::random(8)).'.'.$domain => 'Subdomain acak (bukti wildcard)',
        ];

        if ($this->option('schools')) {
            foreach (School::query()->orderBy('subdomain')->pluck('subdomain') as $sub) {
                $hosts[$sub.'.'.$domain] = 'Sekolah';
            }
        }

        $rows = [];
        $ok = true;

        foreach ($hosts as $host => $label) {
            $ips = ($this->resolver)($host);
            $status = match (true) {
                $ips === [] => 'TIDAK TERDAFTAR',
                $expect && ! in_array($expect, $ips, true) => 'SALAH ARAH',
                default => 'OK',
            };
            $ok = $ok && $status === 'OK';
            $rows[] = [$label, $host, $ips ? implode(', ', $ips) : '-', $status];
        }

        $this->table(['Cek', 'Host', 'IP', 'Status'], $rows);

        if (! $ok) {
            $this->error('DNS belum benar. Pastikan ada record A untuk "@" dan "*" yang mengarah ke IP VPS (lihat deploy/DNS.md).');
            $this->line('Perubahan DNS bisa butuh beberapa menit sampai 24 jam untuk menyebar.');

            return self::FAILURE;
        }

        $this->info('DNS wildcard sudah benar.');

        return self::SUCCESS;
    }

    /** Untuk tes: ganti resolver DNS. */
    public function setResolver(callable $resolver): static
    {
        $this->resolver = $resolver;

        return $this;
    }
}
