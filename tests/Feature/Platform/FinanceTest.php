<?php

namespace Tests\Feature\Platform;

use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Models\BankMutation;
use App\Models\CashEntry;
use App\Models\Invoice;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Finance\Ledger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.price_per_student_month' => 3000, 'billing.tax_percent' => 0, 'billing.gateway' => 'manual']);
        Mail::fake();
        $this->s = $this->makeSchool(['subdomain' => 'smkfin', 'status' => SchoolStatus::Trial, 'trial_ends_at' => now()->addDays(5), 'student_quota' => 100]);
        $this->tenant()->forget();
    }

    private function central(string $path): string
    {
        return 'http://'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    private function superadmin(): User
    {
        $u = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'superadmin', 'password' => 'SuperRahasia1']);
        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }

    private function invoice(int $students = 100, int $months = 6): Invoice
    {
        return app(BillingService::class)->createSubscriptionInvoice($this->s['school']->fresh(), $students, $months, null);
    }

    public function test_kode_unik_ditambahkan_ke_total_dan_berbeda_antar_tagihan(): void
    {
        config(['billing.unique_code' => true]);
        $a = $this->invoice(100, 6);
        $b = $this->invoice(100, 6);
        $this->assertNotNull($a->unique_code);
        $this->assertNotSame($a->unique_code, $b->unique_code);
        $this->assertSame($a->gross - $a->discount + $a->tax + $a->unique_code, $a->total);
        $this->assertContains('Kode unik pembayaran', array_column($a->lines, 'label'));
    }

    public function test_buku_kas_menggabungkan_invoice_gaji_dan_catatan_manual(): void
    {
        $super = $this->superadmin();
        $inv = $this->invoice();
        app(BillingService::class)->recordPayment($inv, ['amount' => 500000, 'paid_on' => now()->toDateString(), 'method' => 'transfer_manual', 'kind' => 'dp'], 'tes', $super);

        $run = PayrollRun::create(['year' => now()->year, 'month' => now()->month, 'pay_date' => now()->toDateString(), 'work_days' => 22]);
        (new PayrollItem(['payroll_run_id' => $run->id, 'nik' => 'K1', 'name' => 'Andi', 'base_salary' => 3000000, 'pay_status' => 'dibayar', 'paid_on' => now()->toDateString()]))->recalc()->save();

        $this->actingAs($super)->post($this->central('panel/keuangan/kas'), ['entry_date' => now()->toDateString(), 'direction' => 'out', 'category' => 'server',
            'amount' => '250.000', 'description' => 'Hosting'])->assertSessionHas('ok');
        $this->actingAs($super)->post($this->central('panel/keuangan/kas'), ['entry_date' => now()->toDateString(), 'direction' => 'in', 'category' => 'modal',
            'amount' => '10.000.000', 'description' => 'Setoran modal'])->assertSessionHas('ok');
        // Kategori harus sesuai arah.
        $this->actingAs($super)->post($this->central('panel/keuangan/kas'), ['entry_date' => now()->toDateString(), 'direction' => 'in', 'category' => 'server',
            'amount' => '1', 'description' => 'x'])->assertSessionHasErrors('category');

        $l = app(Ledger::class);
        $rows = $l->entries(now('Asia/Jakarta')->startOfMonth(), now('Asia/Jakarta')->endOfMonth());
        $sum = $l->summary($rows, 0);
        $this->assertSame(10500000, $sum['in']);
        $this->assertSame(3250000, $sum['out']);
        $this->assertSame(500000, $sum['revenue']);       // modal tidak dihitung pendapatan
        $this->assertSame(3250000, $sum['expense']);
        $this->assertSame(-2750000, $sum['profit']);

        $this->actingAs($super)->get($this->central('panel/keuangan'))->assertOk()->assertSee('Arus kas 12 bulan');
        $this->actingAs($super)->get($this->central('panel/keuangan/kas'))->assertOk()->assertSee('Hosting')->assertSee($inv->number)->assertSee('Gaji');
        $this->actingAs($super)->get($this->central('panel/keuangan/kas/unduh'))->assertOk();
    }

    public function test_kode_referensi_kas_otomatis_per_arah_per_bulan(): void
    {
        $super = $this->superadmin();
        $post = fn ($dir, $cat, $date) => $this->actingAs($super)->post($this->central('panel/keuangan/kas'),
            ['entry_date' => $date, 'direction' => $dir, 'category' => $cat, 'amount' => '10.000', 'description' => "$dir $date"]);
        $post('out', 'server', '2026-11-03');
        $post('out', 'domain', '2026-11-05');
        $post('in', 'modal', '2026-11-05');
        $post('out', 'server', '2026-10-28');
        $this->assertSame(['KK/2026/11/0001', 'KK/2026/11/0002', 'KM/2026/11/0001', 'KK/2026/10/0001'], CashEntry::orderBy('id')->pluck('code')->all());

        // Ubah arah → kode baru sesuai arah; ubah keterangan saja → kode tetap.
        $e = CashEntry::where('code', 'KK/2026/11/0002')->first();
        $this->actingAs($super)->put($this->central('panel/keuangan/kas/'.$e->id), ['entry_date' => '2026-11-05', 'direction' => 'in', 'category' => 'pendapatan_lain',
            'amount' => '10.000', 'description' => 'ternyata pemasukan'])->assertSessionHas('ok');
        $this->assertSame('KM/2026/11/0002', $e->fresh()->code);
        $this->actingAs($super)->put($this->central('panel/keuangan/kas/'.$e->id), ['entry_date' => '2026-11-06', 'direction' => 'in', 'category' => 'pendapatan_lain',
            'amount' => '12.000', 'description' => 'ganti nominal'])->assertSessionHas('ok');
        $this->assertSame('KM/2026/11/0002', $e->fresh()->code);

        $this->actingAs($super)->get($this->central('panel/keuangan/kas?bulan=2026-11&cari=KM/2026/11/0001'))->assertOk()->assertSee('in 2026-11-05')->assertDontSee('out 2026-11-03');
    }

    public function test_impor_mutasi_dan_cocokkan_ke_invoice(): void
    {
        $super = $this->superadmin();
        $a = $this->invoice(100, 6);   // dicocokkan lewat nomor invoice di berita
        // Tagihan khusus (bukan paket): tidak ikut dibatalkan otomatis saat tagihan paket $a lunas.
        $b = app(BillingService::class)->createCustomInvoice($this->s['school']->fresh(), 'Pelatihan operator', 750000); // dicocokkan lewat nominal = sisa
        $csv = "Laporan Mutasi Rekening\nNo. Rekening;0123456789\n\nTanggal;Keterangan;Debet;Kredit;Saldo\n"
            ."15/11/2026;TRF DARI SMK FIN BAYAR ".$a->number.";0,00;".number_format($a->total, 2, ',', '.').";1.000,00\n"
            ."15/11/2026;TRF MASUK SEKOLAH;0,00;".number_format($b->total, 2, ',', '.').";1.000,00\n"
            ."16/11/2026;BIAYA ADM;15.000,00;0,00;985,00\n"
            ."16/11/2026;TRF LAIN;0,00;123.456,00;1.000,00\n";
        $path = tempnam(sys_get_temp_dir(), 'mut').'.csv';
        file_put_contents($path, $csv);
        $file = new UploadedFile($path, 'mutasi.csv', 'text/csv', null, true);

        $this->actingAs($super)->post($this->central('panel/keuangan/mutasi/impor'), ['file' => $file])->assertSessionHas('ok');
        $this->assertSame(4, BankMutation::count());
        $this->assertSame($a->id, BankMutation::where('amount', $a->total)->first()->suggested_invoice_id);
        $this->assertSame($b->id, BankMutation::where('amount', $b->total)->first()->suggested_invoice_id);
        $this->assertSame('out', BankMutation::where('amount', 15000)->first()->direction);

        // Impor ulang file yang sama → tidak dobel.
        $file2 = new UploadedFile($path, 'mutasi.csv', 'text/csv', null, true);
        $this->actingAs($super)->post($this->central('panel/keuangan/mutasi/impor'), ['file' => $file2]);
        $this->assertSame(4, BankMutation::count());

        $this->actingAs($super)->get($this->central('panel/keuangan/mutasi'))->assertOk()->assertSee($a->number);
        $this->actingAs($super)->post($this->central('panel/keuangan/mutasi/cocokkan-semua'))->assertSessionHas('ok');
        $this->assertSame('paid', $a->fresh()->status);
        $this->assertSame('paid', $b->fresh()->status);
        $this->assertSame(2, BankMutation::where('status', 'matched')->count());

        // Debit dicatat ke kas, kredit tak dikenal diabaikan.
        $adm = BankMutation::where('amount', 15000)->first();
        $this->actingAs($super)->post($this->central('panel/keuangan/mutasi/'.$adm->id.'/catat'), ['category' => 'biaya_bank'])->assertSessionHas('ok');
        $this->assertSame('recorded', $adm->fresh()->status);
        $this->assertSame(15000, (int) CashEntry::where('category', 'biaya_bank')->sum('amount')); // MySQL: SUM() berupa string
        $lain = BankMutation::where('amount', 123456)->first();
        $this->actingAs($super)->post($this->central('panel/keuangan/mutasi/'.$lain->id.'/abaikan'))->assertSessionHas('ok');
        $this->assertSame('ignored', $lain->fresh()->status);
    }

    public function test_nominal_melebihi_sisa_ditolak(): void
    {
        $super = $this->superadmin();
        $inv = $this->invoice(10, 1);
        $m = BankMutation::create(['hash' => str_repeat('a', 64), 'trx_date' => now()->toDateString(), 'description' => 'X', 'direction' => 'in', 'amount' => $inv->total + 1000]);
        $this->actingAs($super)->post($this->central('panel/keuangan/mutasi/'.$m->id.'/cocokkan'), ['invoice_id' => $inv->id])->assertSessionHasErrors('invoice_id');
        $this->assertSame('pending', $m->fresh()->status);
    }

    public function test_log_aktivitas_bisa_difilter_per_kategori_dan_dicari(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->post($this->central('panel/keuangan/kas'), ['entry_date' => now()->toDateString(), 'direction' => 'out', 'category' => 'domain',
            'amount' => '150.000', 'description' => 'Domain parama.sbs']);
        $audit = app(\App\Services\Platform\AuditLogger::class);
        $audit->log('payroll.run_created', 'Periode gaji Desember 2026 dibuat', null, $super);

        $this->actingAs($super)->get($this->central('panel/log?kategori=keuangan'))->assertOk()->assertSee('Domain parama.sbs')->assertDontSee('Periode gaji Desember');
        $this->actingAs($super)->get($this->central('panel/log?kategori=gaji'))->assertOk()->assertSee('Periode gaji Desember')->assertDontSee('Domain parama.sbs');
        $this->actingAs($super)->get($this->central('panel/log?q=parama.sbs'))->assertOk()->assertSee('Domain parama.sbs')->assertDontSee('Periode gaji Desember');
        $this->actingAs($super)->get($this->central('panel/log?q=100%25'))->assertOk(); // karakter LIKE di-escape
        $this->assertSame(['gaji', 'Penggajian', 'wr'], \App\Models\AuditLog::categoryOf('payroll.exported'));
    }

    public function test_hanya_superadmin(): void
    {
        $this->actingAs($this->s['admin'])->get($this->central('panel/keuangan'))->assertStatus(403);
    }
}
