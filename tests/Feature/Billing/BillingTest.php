<?php

namespace Tests\Feature\Billing;

use App\Enums\Role;
use App\Enums\SchoolStatus;
use App\Mail\BillingMail;
use App\Models\Invoice;
use App\Models\PaymentEvent;
use App\Models\School;
use App\Models\User;
use App\Services\Billing\BillingCalculator;
use App\Services\Billing\BillingCycle;
use App\Services\Billing\BillingService;
use App\Tenancy\SchoolScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingTest extends TestCase
{
    private const MT_KEY = 'SB-Mid-server-TESTKEY';

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.price_per_student_month' => 3000, 'billing.tax_percent' => 0, 'billing.gateway' => 'midtrans',
            'billing.midtrans.server_key' => self::MT_KEY, 'billing.midtrans.production' => false,
            'billing.xendit.secret_key' => 'xnd_development_TEST', 'billing.xendit.callback_token' => 'tok-xendit-rahasia',
        ]);
        Mail::fake();
        Http::preventStrayRequests();
        $this->s = $this->makeSchool(['subdomain' => 'smkn1', 'status' => SchoolStatus::Trial, 'trial_ends_at' => now()->addDays(5), 'student_quota' => 100]);
        $this->tenant()->forget();
    }

    private function central(string $path): string
    {
        return 'http://'.config('tenancy.central_domain').'/'.ltrim($path, '/');
    }

    private function school(): School
    {
        return $this->s['school']->fresh();
    }

    private function invoice(int $students = 120, int $months = 12): Invoice
    {
        return app(BillingService::class)->createSubscriptionInvoice($this->school(), $students, $months, null);
    }

    private function midtransBody(Invoice $inv, string $status = 'settlement', ?string $amount = null, ?string $key = null): array
    {
        $amount ??= $inv->total.'.00';

        return [
            'order_id' => $inv->gateway_ref, 'status_code' => '200', 'gross_amount' => $amount, 'transaction_status' => $status,
            'payment_type' => 'bank_transfer', 'transaction_id' => 'trx-'.$inv->id, 'fraud_status' => 'accept',
            'signature_key' => hash('sha512', $inv->gateway_ref.'200'.$amount.($key ?? self::MT_KEY)),
        ];
    }

    private ?array $mtStatus = null;

    /** Jawaban API status Midtrans; bisa diganti di tengah tes (fake didaftarkan sekali). */
    private function fakeMidtransStatus(Invoice $inv, string $status = 'settlement', ?int $amount = null): void
    {
        $first = $this->mtStatus === null;
        $this->mtStatus = [
            'order_id' => $inv->gateway_ref, 'transaction_status' => $status, 'fraud_status' => 'accept', 'payment_type' => 'qris',
            'gross_amount' => ($amount ?? $inv->total).'.00', 'transaction_id' => 'trx-'.$inv->id, 'status_code' => '200',
        ];
        if ($first) {
            Http::fake(['api.sandbox.midtrans.com/v2/*' => fn () => Http::response($this->mtStatus)]);
        }
    }

    private function withGatewayRef(Invoice $inv, string $gateway = 'midtrans'): Invoice
    {
        $inv->forceFill(['gateway' => $gateway, 'gateway_ref' => str_replace('/', '-', $inv->number).'-1', 'gateway_invoice_id' => 'xnd-inv-'.$inv->id, 'checkout_attempts' => 1])->save();

        return $inv;
    }

    // ── Harga ─────────────────────────────────────────────────────────

    public function test_harga_per_siswa_aktif_persis_minimal_20_dengan_diskon_periode(): void
    {
        $c = app(BillingCalculator::class);
        $this->assertSame(20, $c->normalizeStudents(12));       // minimal 20 siswa
        $this->assertSame(123, $c->normalizeStudents(123));     // persis, tanpa pembulatan

        $q1 = $c->quote(120, 1);
        $this->assertSame([120, 360000, 0, 360000], [$q1['student_count'], $q1['gross'], $q1['discount'], $q1['total']]);
        $q12 = $c->quote(120, 12);
        $this->assertSame(4320000, $q12['gross']);
        $this->assertSame(720144, $q12['discount']);            // 16,67% ≈ gratis 2 bulan
        $this->assertSame(3599856, $q12['total']);

        config(['billing.tax_percent' => 11]);
        $t = $c->quote(100, 1);
        $this->assertSame([33000, 333000], [$t['tax'], $t['total']]);
        $this->assertStringContainsString('PPN 11%', collect($t['lines'])->last()['label']);
    }

    public function test_tambah_kuota_dihitung_prorata_sisa_masa_aktif(): void
    {
        $this->s['school']->forceFill(['status' => SchoolStatus::Active, 'active_until' => now()->addDays(30), 'student_quota' => 100])->save();
        $q = app(BillingCalculator::class)->upgradeQuote($this->school(), 150);
        // 50 siswa × Rp3.000 × 30/30 hari = Rp150.000
        $this->assertSame([150, 150000], [$q['student_count'], $q['total']]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(BillingCalculator::class)->upgradeQuote($this->school(), 80);
    }

    // ── Portal admin ──────────────────────────────────────────────────

    public function test_admin_membuat_tagihan_dan_tagihan_terisolasi_per_sekolah(): void
    {
        // Per siswa aktif: sekolah ini punya 1 siswa aktif → ditagih minimal 20 siswa, isian "students" diabaikan.
        $expected = app(BillingCalculator::class)->quote(20, 12)['total'];
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'langganan'))->assertOk()->assertSee('Pilih paket langganan')->assertSee(Invoice::rupiah($expected));
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan'), ['type' => 'new', 'students' => 120, 'months' => 12])
            ->assertRedirect()->assertSessionHas('ok');

        $inv = BillingService::invoices()->sole();
        $this->assertSame([$this->s['school']->id, 'new', 'unpaid', 20, $expected], [$inv->school_id, $inv->type, $inv->status, $inv->student_count, $inv->total]);
        $this->assertMatchesRegularExpression('#^INV/\d{4}/\d{2}/00001$#', $inv->number);
        Mail::assertSent(BillingMail::class, fn ($m) => $m->kind === 'invoice' && $m->hasTo($this->s['admin']->email));

        // Klik dua kali → tagihan yang sama dipakai ulang, tidak menumpuk.
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan'), ['type' => 'new', 'students' => 120, 'months' => 12]);
        $this->assertSame(1, BillingService::invoices()->count());

        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id))->assertOk()->assertSee($inv->number)->assertSee('Unggah bukti transfer');
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id.'/cetak'))->assertOk()->assertSee('INVOICE')->assertSee('BELUM LUNAS');

        // Sekolah lain tidak bisa melihat/membayar tagihan ini.
        $b = $this->makeSchool(['subdomain' => 'smkn2']);
        $this->tenant()->forget();
        $this->actingAs($b['admin'])->get($this->at($b['school'], 'langganan/tagihan/'.$inv->id))->assertNotFound();
        $this->actingAs($b['admin'])->post($this->at($b['school'], 'langganan/tagihan/'.$inv->id.'/bayar'))->assertNotFound();

        // Guru/siswa tidak bisa membuka halaman langganan.
        $this->actingAs($this->s['teacher']->user)->get($this->at($this->s['school'], 'langganan'))->assertRedirect();
    }

    public function test_tagihan_langganan_selalu_sebanyak_siswa_aktif(): void
    {
        $this->tenant()->run($this->s['school'], function () {
            for ($i = 0; $i < 24; $i++) {
                $u = User::factory()->create(['role' => Role::Student, 'username' => 'x'.$i]);
                \App\Models\Student::create(['user_id' => $u->id, 'nis' => 'x'.$i, 'name' => 'S'.$i, 'classroom_id' => $this->s['classroom']->id]);
            }
        });
        $this->tenant()->forget();
        // 25 siswa aktif (1 + 24): isian 2 atau 500 diabaikan, ditagih 25.
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan'), ['type' => 'new', 'students' => 2, 'months' => 1])->assertSessionHas('ok');
        $this->assertSame(25, BillingService::invoices()->sole()->student_count);
    }

    public function test_tagihan_tambahan_otomatis_tiap_awal_bulan_untuk_siswa_baru(): void
    {
        $this->s['school']->forceFill(['status' => SchoolStatus::Active, 'active_until' => now()->addDays(60), 'student_quota' => 20])->save();
        $this->tenant()->run($this->s['school'], function () {
            for ($i = 0; $i < 24; $i++) {           // 25 siswa aktif, baru dibayar 20
                $u = User::factory()->create(['role' => Role::Student, 'username' => 'y'.$i]);
                \App\Models\Student::create(['user_id' => $u->id, 'nis' => 'y'.$i, 'name' => 'S'.$i, 'classroom_id' => $this->s['classroom']->id]);
            }
        });
        $this->tenant()->forget();

        $cycle = app(BillingCycle::class);
        $this->assertSame(1, $cycle->run()['invoices']);
        $inv = BillingService::invoices()->sole();
        $this->assertSame(['upgrade', 25], [$inv->type, $inv->student_count]);
        $this->assertStringContainsString('Tambahan 5 siswa aktif', $inv->lines[0]['label']);
        Mail::assertSent(BillingMail::class, fn ($m) => $m->kind === 'invoice');
        $this->assertSame(0, $cycle->run()['invoices']);       // sekali per bulan

        // Sekolah aktif tidak diblokir menambah siswa di atas yang sudah dibayar (ditagih bulan berikutnya).
        $this->assertNull($this->school()->effectiveStudentQuota()); // All Service: tanpa batas paket
    }

    // ── Midtrans ──────────────────────────────────────────────────────

    public function test_checkout_midtrans_mengarah_ke_halaman_bayar(): void
    {
        $inv = $this->invoice();
        Http::fake(['app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-tok', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-tok'], 201)]);

        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id.'/bayar'))
            ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/snap-tok');

        Http::assertSent(fn ($r) => $r['transaction_details']['gross_amount'] === 3599856
            && $r['transaction_details']['order_id'] === str_replace('/', '-', $inv->number).'-1'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::MT_KEY.':')));
        $this->assertSame(['midtrans', 1], [$inv->fresh()->gateway, $inv->fresh()->checkout_attempts]);

        // Klik "Bayar" lagi dalam 12 jam memakai tautan yang sama (tidak membuat transaksi baru).
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id.'/bayar'))->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/snap-tok');
        Http::assertSentCount(1);
    }

    public function test_webhook_midtrans_sah_melunasi_dan_mengaktifkan_langganan_sekali_saja(): void
    {
        $inv = $this->withGatewayRef($this->invoice());
        $this->fakeMidtransStatus($inv);

        $this->postJson($this->central('webhooks/midtrans'), $this->midtransBody($inv))->assertOk()->assertJson(['status' => 'ok']);

        $inv->refresh();
        $school = $this->school();
        $this->assertSame('paid', $inv->status);
        $this->assertSame('midtrans:qris', $inv->payment_method);
        $this->assertSame(SchoolStatus::Active, $school->status);
        $this->assertSame(120, $school->student_quota);
        $this->assertTrue($school->active_until->timezone('Asia/Jakarta')->isSameDay(now('Asia/Jakarta')->addMonthsNoOverflow(12)));
        Mail::assertSent(BillingMail::class, fn ($m) => $m->kind === 'receipt');

        // Notifikasi ganda (gateway mengulang) tidak memperpanjang dua kali.
        $until = $school->active_until;
        $this->postJson($this->central('webhooks/midtrans'), $this->midtransBody($inv))->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertEquals($until, $this->school()->active_until);
        $this->assertSame(1, PaymentEvent::query()->withoutGlobalScope(SchoolScope::class)->where('result', 'applied')->count());

        // Kwitansi tersedia.
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id.'/cetak'))->assertSee('KWITANSI')->assertSee('LUNAS');
    }

    public function test_webhook_midtrans_palsu_ditolak(): void
    {
        $inv = $this->withGatewayRef($this->invoice());
        Http::fake();

        // Tanda tangan dibuat dengan kunci yang salah.
        $this->postJson($this->central('webhooks/midtrans'), $this->midtransBody($inv, key: 'kunci-palsu'))->assertForbidden();
        // Tanpa tanda tangan.
        $this->postJson($this->central('webhooks/midtrans'), ['order_id' => $inv->gateway_ref, 'transaction_status' => 'settlement'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame('unpaid', $inv->fresh()->status);
        $this->assertSame(SchoolStatus::Trial, $this->school()->status);
        $this->assertSame(2, PaymentEvent::query()->withoutGlobalScope(SchoolScope::class)->where('result', 'rejected')->count());
    }

    public function test_status_webhook_dicek_ulang_ke_api_dan_nominal_harus_cocok(): void
    {
        $inv = $this->withGatewayRef($this->invoice());

        // Tanda tangan sah tetapi API Midtrans bilang masih pending → belum lunas.
        $this->fakeMidtransStatus($inv, 'pending');
        $this->postJson($this->central('webhooks/midtrans'), $this->midtransBody($inv))->assertOk();
        $this->assertSame('unpaid', $inv->fresh()->status);

        // API bilang settlement tetapi nominal lebih kecil → ditolak, tidak lunas.
        $this->fakeMidtransStatus($inv, 'settlement', 1000);
        $this->postJson($this->central('webhooks/midtrans'), $this->midtransBody($inv, 'settlement', '1000.00'))->assertOk()->assertJson(['status' => 'amount_mismatch']);
        $this->assertSame('unpaid', $inv->fresh()->status);

        // Transaksi kedaluwarsa → tautan bayar dihapus agar admin bisa membuat sesi baru.
        $inv->forceFill(['payment_url' => 'https://x'])->save();
        $this->fakeMidtransStatus($inv, 'expire');
        $this->postJson($this->central('webhooks/midtrans'), $this->midtransBody($inv, 'expire'))->assertOk();
        $this->assertNull($inv->fresh()->payment_url);
    }

    public function test_api_status_tidak_terjangkau_memakai_notifikasi_terverifikasi(): void
    {
        $inv = $this->withGatewayRef($this->invoice());
        Http::fake(['api.sandbox.midtrans.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);
        $this->postJson($this->central('webhooks/midtrans'), $this->midtransBody($inv))->assertOk();
        $this->assertSame('paid', $inv->fresh()->status);

        // Tombol "cek status" (tanpa notifikasi cadangan) memberi pesan, bukan error 500.
        $inv2 = $this->withGatewayRef($this->invoice(100, 1));
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan/'.$inv2->id.'/cek'))->assertSessionHasErrors('invoice');
        $this->assertSame('unpaid', $inv2->fresh()->status);
    }

    public function test_kembali_dari_gateway_langsung_cek_status(): void
    {
        $inv = $this->withGatewayRef($this->invoice());
        $this->fakeMidtransStatus($inv);
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id.'?selesai=1'))->assertOk()->assertSee('Lunas');
        $this->assertSame('paid', $inv->fresh()->status);
    }

    // ── Xendit ────────────────────────────────────────────────────────

    public function test_webhook_xendit_token_wajib_dan_status_dicek_ulang(): void
    {
        config(['billing.gateway' => 'xendit']);
        $inv = $this->withGatewayRef($this->invoice(), 'xendit');
        $body = ['id' => $inv->gateway_invoice_id, 'external_id' => $inv->gateway_ref, 'status' => 'PAID', 'paid_amount' => $inv->total];

        $this->postJson($this->central('webhooks/xendit'), $body, ['X-CALLBACK-TOKEN' => 'salah'])->assertForbidden();
        $this->postJson($this->central('webhooks/xendit'), $body)->assertForbidden();
        $this->assertSame('unpaid', $inv->fresh()->status);

        Http::fake(['api.xendit.co/v2/invoices/*' => Http::response(['id' => $inv->gateway_invoice_id, 'external_id' => $inv->gateway_ref, 'status' => 'PAID', 'paid_amount' => $inv->total, 'payment_channel' => 'BRI', 'payment_id' => 'pay-1'])]);
        $this->postJson($this->central('webhooks/xendit'), $body, ['X-CALLBACK-TOKEN' => 'tok-xendit-rahasia'])->assertOk();
        $this->assertSame(['paid', 'xendit:BRI'], [$inv->fresh()->status, $inv->fresh()->payment_method]);
        $this->assertSame(SchoolStatus::Active, $this->school()->status);
    }

    public function test_checkout_xendit(): void
    {
        config(['billing.gateway' => 'xendit']);
        $inv = $this->invoice(100, 1);
        Http::fake(['api.xendit.co/v2/invoices' => Http::response(['id' => 'inv-xnd-9', 'invoice_url' => 'https://checkout-staging.xendit.co/web/inv-xnd-9'])]);
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id.'/bayar'))->assertRedirect('https://checkout-staging.xendit.co/web/inv-xnd-9');
        Http::assertSent(fn ($r) => $r['amount'] === 300000 && $r['currency'] === 'IDR');
        $this->assertSame('inv-xnd-9', $inv->fresh()->gateway_invoice_id);
    }

    // ── Transfer manual ───────────────────────────────────────────────

    public function test_transfer_manual_unggah_bukti_lalu_dikonfirmasi_panel(): void
    {
        Storage::fake('local');
        $inv = $this->invoice(100, 6);
        $img = UploadedFile::fake()->image('bukti.jpg', 600, 900);
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id.'/bukti'), ['proof' => $img, 'sender' => 'Bendahara BOS'])->assertSessionHas('ok');
        $inv->refresh();
        $this->assertStringStartsWith('school_'.$this->s['school']->id.'/billing/', $inv->transfer_proof_path);
        $this->assertStringEndsWith('.webp', $inv->transfer_proof_path);

        // File palsu ber-ekstensi pdf ditolak.
        $fake = UploadedFile::fake()->createWithContent('bukti.pdf', 'bukan pdf');
        $this->actingAs($this->s['admin'])->post($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id.'/bukti'), ['proof' => $fake, 'sender' => 'X'])->assertSessionHasErrors('proof');

        $super = $this->superadmin();
        $this->actingAs($super)->get($this->central('panel/tagihan'))->assertOk()->assertSee($inv->number)->assertSee('Cek bukti');
        $this->actingAs($super)->get($this->central('panel/tagihan/'.$inv->id))->assertOk()->assertSee('Buka bukti transfer');
        $this->actingAs($super)->post($this->central('panel/tagihan/'.$inv->id.'/lunas'), ['method' => 'transfer_manual', 'ref' => 'Mutasi BCA 03/11'])->assertSessionHas('ok');

        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertTrue($this->school()->active_until->timezone('Asia/Jakarta')->isSameDay(now('Asia/Jakarta')->addMonthsNoOverflow(6)));
        // Konfirmasi kedua tidak berpengaruh.
        $this->actingAs($super)->post($this->central('panel/tagihan/'.$inv->id.'/lunas'), ['method' => 'transfer_manual', 'ref' => 'lagi']);
        $this->assertTrue($this->school()->active_until->timezone('Asia/Jakarta')->isSameDay(now('Asia/Jakarta')->addMonthsNoOverflow(6)));
    }

    public function test_dp_dan_pembayaran_bertahap_dari_panel(): void
    {
        $inv = $this->invoice(100, 6);
        $total = $inv->total;
        $super = $this->superadmin();
        $url = $this->central('panel/tagihan/'.$inv->id.'/pembayaran');
        $before = $this->school()->active_until;

        // DP tanpa aktivasi: tagihan tetap belum lunas, sisa berkurang, masa aktif belum berubah.
        $this->actingAs($super)->post($url, ['kind' => 'dp', 'amount' => 200000, 'paid_on' => now()->toDateString(), 'method' => 'transfer_manual', 'ref' => 'BRI 01'])->assertSessionHas('ok');
        $inv->refresh();
        $this->assertSame('unpaid', $inv->status);
        $this->assertSame(200000, $inv->paid_amount);
        $this->assertSame($total - 200000, $inv->remaining());
        $this->assertTrue($inv->isPartial());
        $this->assertEquals($before, $this->school()->active_until);

        // Lebih dari sisa ditolak.
        $this->actingAs($super)->post($url, ['amount' => $total, 'paid_on' => now()->toDateString(), 'method' => 'tunai'])->assertSessionHasErrors('amount');

        // Panel, cetak invoice, dan portal menampilkan DP & sisa; bayar online diblokir setelah DP.
        $this->actingAs($super)->get($this->central('panel/tagihan'))->assertOk()->assertSee('DP/Parsial');
        $this->actingAs($super)->get($this->central('panel/tagihan/'.$inv->id))->assertOk()->assertSee('Sisa tagihan')->assertSee('Riwayat pembayaran');
        $this->actingAs($super)->get($this->central('panel/tagihan/'.$inv->id.'/cetak'))->assertOk()->assertSee('DP DITERIMA')->assertSee('Sisa tagihan');
        $this->actingAs($this->s['admin'])->get($this->at($this->s['school'], 'langganan/tagihan/'.$inv->id))->assertOk()->assertSee('Sisa tagihan');
        $this->expectsNoCheckout($inv);

        // Pembayaran tambahan sebesar sisa → otomatis lunas & masa aktif diterapkan sekali.
        $this->actingAs($super)->post($url, ['kind' => 'tambahan', 'amount' => $total - 200000, 'paid_on' => now()->toDateString(), 'method' => 'transfer_manual', 'ref' => 'BRI 02'])->assertSessionHas('ok');
        $inv->refresh();
        $this->assertSame('paid', $inv->status);
        $this->assertSame($total, $inv->paid_amount);
        $this->assertSame(2, $inv->payments()->count());
        $this->assertSame('pelunasan', $inv->payments()->get()->last()->kind);
        $this->assertTrue($this->school()->active_until->timezone('Asia/Jakarta')->isSameDay(now('Asia/Jakarta')->addMonthsNoOverflow(6)));
        $this->actingAs($super)->get($this->central('panel/tagihan/'.$inv->id.'/cetak'))->assertOk()->assertSee('KWITANSI')->assertSee('RIWAYAT PEMBAYARAN');
    }

    public function test_dp_dengan_aktivasi_tidak_memperpanjang_dua_kali(): void
    {
        $inv = $this->invoice(100, 1);   // periode tersedia: 1, 6, 12 bulan (config/billing.php)
        $super = $this->superadmin();
        $url = $this->central('panel/tagihan/'.$inv->id.'/pembayaran');
        $this->actingAs($super)->post($url, ['kind' => 'dp', 'amount' => 100000, 'paid_on' => now()->toDateString(), 'method' => 'tunai', 'activate' => 1])->assertSessionHas('ok');
        $until = $this->school()->active_until;
        $this->assertTrue($until->timezone('Asia/Jakarta')->isSameDay(now('Asia/Jakarta')->addMonthsNoOverflow(1)));
        $this->assertNotNull($inv->fresh()->activated_at);

        // Hapus pembayaran salah catat → sisa kembali, aktivasi tetap.
        $p = $inv->payments()->first();
        $this->actingAs($super)->delete($this->central('panel/tagihan/'.$inv->id.'/pembayaran/'.$p->id))->assertSessionHas('ok');
        $this->assertSame(0, $inv->fresh()->paid_amount);
        $this->actingAs($super)->post($url, ['kind' => 'dp', 'amount' => 100000, 'paid_on' => now()->toDateString(), 'method' => 'tunai'])->assertSessionHas('ok');

        // "Lunasi sisa sekaligus" mencatat baris pelunasan & tidak menambah masa aktif lagi.
        $this->actingAs($super)->post($this->central('panel/tagihan/'.$inv->id.'/lunas'), ['method' => 'transfer_manual', 'ref' => 'Pelunasan'])->assertSessionHas('ok');
        $inv->refresh();
        $this->assertSame('paid', $inv->status);
        $this->assertSame($inv->total, (int) $inv->payments()->sum('amount'));
        $this->assertEquals($until, $this->school()->active_until);
    }

    private function expectsNoCheckout(Invoice $inv): void
    {
        try {
            app(BillingService::class)->checkout($inv->fresh(), $this->school(), 'https://x.test');
            $this->fail('Checkout seharusnya ditolak setelah DP.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('DP', $e->getMessage());
        }
    }

    public function test_perpanjangan_ditumpuk_setelah_masa_aktif_berjalan(): void
    {
        $until = now('Asia/Jakarta')->addDays(20)->endOfDay(); // masa aktif selalu berakhir 23:59 waktu sekolah
        $this->s['school']->forceFill(['status' => SchoolStatus::Active, 'active_until' => $until->copy()->utc()])->save();
        $inv = $this->invoice(100, 1);
        app(BillingService::class)->markPaid($inv, 'tunai', 'T-1', 'Tes');
        $this->assertSame($until->copy()->addMonthNoOverflow()->format('Y-m-d H:i'), $this->school()->active_until->timezone('Asia/Jakarta')->format('Y-m-d H:i'));
        $this->assertSame($until->format('Y-m-d'), $inv->fresh()->period_start->format('Y-m-d'));
    }

    public function test_tagihan_paket_lain_dibatalkan_otomatis_setelah_satu_dibayar(): void
    {
        $a = $this->invoice(100, 1);
        $b = $this->invoice(100, 12);
        app(BillingService::class)->markPaid($b, 'tunai', 'T-1', 'Tes');
        $this->assertSame('void', $a->fresh()->status);
    }

    public function test_panel_tagihan_khusus_dan_pembatalan(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->post($this->central('panel/sekolah/'.$this->s['school']->id.'/tagihan'), ['kind' => 'custom', 'description' => 'Pelatihan operator', 'amount' => 750000, 'send' => 1])->assertRedirect();
        $inv = BillingService::invoices()->where('type', 'custom')->sole();
        $this->assertSame(750000, $inv->total);
        Mail::assertSent(BillingMail::class, fn ($m) => $m->kind === 'invoice');

        $this->actingAs($super)->post($this->central('panel/tagihan/'.$inv->id.'/batal'), ['reason' => 'Salah nominal'])->assertSessionHas('ok');
        $this->assertSame('void', $inv->fresh()->status);
        // Tagihan khusus lunas tidak mengubah masa aktif.
        $inv2 = app(BillingService::class)->createCustomInvoice($this->school(), 'Cetak QR', 50000);
        app(BillingService::class)->markPaid($inv2, 'tunai', 'x', 'Tes');
        $this->assertSame(SchoolStatus::Trial, $this->school()->status);
    }

    // ── Siklus harian: pengingat, perpanjangan, tenggang, blokir ──────

    public function test_pengingat_trial_dikirim_sekali_per_ambang(): void
    {
        $cycle = app(BillingCycle::class);
        $this->assertSame(1, $cycle->run()['reminders']);      // H-5 → ambang H-7
        $this->assertSame(0, $cycle->run()['reminders']);      // tidak dobel
        Mail::assertSent(BillingMail::class, fn ($m) => $m->kind === 'reminder' && $m->data['trial'] === true);

        $this->travel(3)->days();                               // H-2 → ambang H-3
        $this->assertSame(1, $cycle->run()['reminders']);
        $this->assertSame(0, BillingService::invoices()->count()); // trial tidak dibuatkan invoice otomatis
    }

    public function test_invoice_perpanjangan_otomatis_h_14(): void
    {
        $this->s['school']->forceFill(['status' => SchoolStatus::Active, 'active_until' => now()->addDays(10), 'student_quota' => 200])->save();
        $r = app(BillingCycle::class)->run();
        $this->assertSame(1, $r['invoices']);
        $inv = BillingService::invoices()->sole();
        // Dibayar 200, tapi siswa aktif kini 1 → perpanjangan ikut turun ke siswa aktif (minimal 20).
        $this->assertSame(['renewal', 20, 12], [$inv->type, $inv->student_count, $inv->months]);
        $this->assertTrue($inv->due_at->isSameDay(now()->addDays(10)));
        $this->assertSame(0, app(BillingCycle::class)->run()['invoices']);
        Mail::assertSent(BillingMail::class, fn ($m) => $m->kind === 'invoice');
    }

    public function test_lewat_masa_tenggang_diblokir_lalu_aktif_otomatis_setelah_bayar(): void
    {
        $this->s['school']->forceFill(['trial_ends_at' => now()->subDays(2)])->save();
        $cycle = app(BillingCycle::class);
        $this->assertSame(1, $cycle->run()['expired']);        // masa tenggang: masih jalan
        $this->actingAs($this->s['student']->user)->getJson($this->at($this->s['school'], 'api/me'))->assertOk();

        $this->travel(6)->days();
        $this->assertSame(1, $cycle->run()['suspended']);
        $school = $this->school();
        $this->assertTrue($school->isBillingLocked());
        Mail::assertSent(BillingMail::class, fn ($m) => $m->kind === 'suspended');

        // Siswa/guru terblokir; API memberi 402.
        // api/me tetap terbuka (IdentifyTenant::OPEN_WHILE_LOCKED) agar PWA bisa memuat profil lalu menampilkan layar "diblokir".
        $this->actingAs($this->s['student']->user)->getJson($this->at($school, 'api/me'))->assertOk();
        $this->actingAs($this->s['student']->user)->getJson($this->at($school, 'api/dashboard'))->assertStatus(402)->assertJson(['billing_locked' => true]);
        $this->actingAs($this->s['teacher']->user)->get($this->at($school, 'dashboard'))->assertStatus(402)->assertSee('Layanan diblokir sementara');
        // Halaman login tetap terbuka; admin diarahkan ke Langganan.
        $this->app['auth']->guard('web')->logout();
        $this->get($this->at($school, 'masuk'))->assertOk();
        $this->actingAs($this->s['admin'])->get($this->at($school, 'dashboard'))->assertRedirect($this->at($school, 'langganan'));
        $this->actingAs($this->s['admin'])->get($this->at($school, 'data-siswa'))->assertRedirect($this->at($school, 'langganan'));
        $this->actingAs($this->s['admin'])->get($this->at($school, 'langganan'))->assertOk()->assertSee('Layanan diblokir sementara');

        // Bayar → aktif otomatis.
        $inv = $this->withGatewayRef($this->invoice(100, 1));
        $this->fakeMidtransStatus($inv);
        $this->postJson($this->central('webhooks/midtrans'), $this->midtransBody($inv))->assertOk();
        $school = $this->school();
        $this->assertSame(SchoolStatus::Active, $school->status);
        $this->assertNull($school->suspension_type);
        $this->actingAs($this->s['student']->user)->getJson($this->at($school, 'api/me'))->assertOk();
    }

    public function test_penangguhan_manual_tidak_dibuka_oleh_pembayaran(): void
    {
        $super = $this->superadmin();
        $this->actingAs($super)->post($this->central('panel/sekolah/'.$this->s['school']->id.'/tangguhkan'), ['reason' => 'Pelanggaran ketentuan']);
        $this->assertSame('manual', $this->school()->suspension_type);
        $this->get($this->at($this->school(), 'masuk'))->assertForbidden(); // seluruh portal tertutup

        $inv = $this->invoice(100, 1);
        app(BillingService::class)->markPaid($inv, 'tunai', 'x', 'Tes');
        $this->assertSame(SchoolStatus::Suspended, $this->school()->status);
        $this->assertNotNull($this->school()->active_until); // masa aktif tetap tercatat
    }

    public function test_semua_email_tagihan_bisa_dirender(): void
    {
        $inv = $this->invoice();
        $school = $this->school();
        foreach ([
            ['invoice', ['invoice' => $inv]], ['receipt', ['invoice' => $inv->forceFill(['period_start' => now(), 'period_end' => now()->addYear()])]],
            ['reminder', ['days' => 7, 'ends' => now()->addDays(7), 'trial' => true, 'invoice' => null]],
            ['expired', ['trial' => false, 'grace' => now()->addDays(7)]], ['suspended', []],
        ] as [$kind, $data]) {
            $html = (new BillingMail($kind, $school, $this->s['admin'], $data))->render();
            $this->assertStringContainsString($school->name, $html, $kind);
            $this->assertStringContainsString('langganan', $html, $kind);
        }
    }

    private function superadmin(): User
    {
        $u = User::factory()->create(['role' => Role::SuperAdmin, 'username' => 'superadmin', 'password' => 'SuperRahasia1']);
        $u->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        return $u;
    }
}
