<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Xendit Invoice.
 * - Buat invoice: POST https://api.xendit.co/v2/invoices (Basic auth: secret key) → invoice_url
 * - Callback: header X-CALLBACK-TOKEN wajib sama dengan token verifikasi akun
 * - Status dicek ulang ke GET /v2/invoices/{id}; lunas bila PAID atau SETTLED.
 */
class XenditGateway implements PaymentGateway
{
    private const BASE = 'https://api.xendit.co';

    public function name(): string
    {
        return 'xendit';
    }

    private function key(): string
    {
        return (string) config('billing.xendit.secret_key') ?: throw new RuntimeException('XENDIT_SECRET_KEY belum diisi.');
    }

    public function checkout(Invoice $invoice, School $school, string $orderId, string $finishUrl): array
    {
        $res = Http::withBasicAuth($this->key(), '')->acceptJson()->timeout(20)->post(self::BASE.'/v2/invoices', [
            'external_id' => $orderId,
            'amount' => $invoice->total,
            'currency' => 'IDR',
            'description' => $invoice->description.' — '.$school->name,
            'payer_email' => $invoice->creator?->email,
            'invoice_duration' => 7 * 24 * 3600,
            'success_redirect_url' => $finishUrl,
            'failure_redirect_url' => $finishUrl,
        ]);
        if (! $res->successful() || ! $res->json('invoice_url')) {
            throw new RuntimeException('Xendit menolak membuat invoice: '.($res->json('message') ?? $res->status()));
        }

        return ['ref' => $orderId, 'url' => $res->json('invoice_url'), 'gateway_invoice_id' => $res->json('id')];
    }

    public function webhook(Request $request): array
    {
        $token = (string) config('billing.xendit.callback_token');
        if ($token === '' || ! hash_equals($token, (string) $request->header('X-CALLBACK-TOKEN'))) {
            throw new WebhookRejected('Token callback Xendit tidak sah.');
        }
        $p = $request->json()->all();
        if (empty($p['id']) || empty($p['external_id'])) {
            throw new WebhookRejected('Data callback tidak lengkap.');
        }

        return $this->fetch((string) $p['id'], (string) $p['external_id'], $p);
    }

    public function status(Invoice $invoice): array
    {
        return $this->fetch((string) $invoice->gateway_invoice_id, (string) $invoice->gateway_ref);
    }

    private function fetch(string $id, string $externalId, array $fallback = []): array
    {
        try {
            $st = Http::withBasicAuth($this->key(), '')->acceptJson()->timeout(15)->get(self::BASE.'/v2/invoices/'.rawurlencode($id));
        } catch (\Throwable $e) {
            $st = null; // API tidak terjangkau
        }
        if (! $st?->successful()) {
            if (! $fallback) {
                throw new RuntimeException('Status Xendit tidak bisa dibaca'.($st ? ' ('.$st->status().')' : '').'.');
            }
            // Notifikasi sudah lolos verifikasi tanda tangan/token; dipakai sebagai cadangan, dicatat untuk audit.
            \Illuminate\Support\Facades\Log::warning('Cek ulang status Xendit gagal, memakai data notifikasi terverifikasi', ['ref' => $fallback['order_id'] ?? $fallback['external_id'] ?? null]);
        }
        $s = $st?->successful() ? $st->json() : $fallback;
        // external_id dari API harus cocok dengan callback; mencegah id invoice lain disisipkan.
        if (isset($s['external_id']) && (string) $s['external_id'] !== $externalId) {
            throw new WebhookRejected('external_id tidak cocok dengan invoice Xendit.');
        }
        $status = strtoupper((string) ($s['status'] ?? ''));
        $paid = in_array($status, ['PAID', 'SETTLED'], true);

        return [
            'ref' => $externalId,
            'status' => $paid ? 'paid' : ($status === 'PENDING' || $status === '' ? 'pending' : 'failed'),
            'paid' => $paid,
            'amount' => (int) round((float) ($s['paid_amount'] ?? $s['amount'] ?? 0)),
            'method' => 'xendit:'.($s['payment_channel'] ?? $s['payment_method'] ?? 'unknown'),
            'payment_ref' => $s['payment_id'] ?? ($s['id'] ?? null),
            'raw_status' => $status,
        ];
    }
}
