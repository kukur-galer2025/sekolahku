<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Midtrans Snap.
 * - Buat transaksi: POST {app}/snap/v1/transactions (Basic auth: server key) → redirect_url
 * - Notifikasi: signature_key = sha512(order_id + status_code + gross_amount + server_key)
 * - Setelah tanda tangan sah, status DICEK ULANG ke GET {api}/v2/{order_id}/status (sumber kebenaran).
 */
class MidtransGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'midtrans';
    }

    private function key(): string
    {
        return (string) config('billing.midtrans.server_key') ?: throw new RuntimeException('MIDTRANS_SERVER_KEY belum diisi.');
    }

    private function appBase(): string
    {
        return config('billing.midtrans.production') ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
    }

    private function apiBase(): string
    {
        return config('billing.midtrans.production') ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }

    public function checkout(Invoice $invoice, School $school, string $orderId, string $finishUrl): array
    {
        $admin = $invoice->creator;
        $res = Http::withBasicAuth($this->key(), '')->acceptJson()->timeout(20)->post($this->appBase().'/snap/v1/transactions', [
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $invoice->total],
            'item_details' => [['id' => $invoice->type, 'price' => $invoice->total, 'quantity' => 1, 'name' => mb_substr($invoice->description, 0, 50)]],
            'customer_details' => ['first_name' => mb_substr($school->name, 0, 50), 'email' => $admin?->email, 'phone' => $school->contact_phone],
            'callbacks' => ['finish' => $finishUrl],
        ]);
        if (! $res->successful() || ! $res->json('redirect_url')) {
            throw new RuntimeException('Midtrans menolak membuat transaksi: '.($res->json('error_messages.0') ?? $res->status()));
        }

        return ['ref' => $orderId, 'url' => $res->json('redirect_url'), 'gateway_invoice_id' => $res->json('token')];
    }

    public function webhook(Request $request): array
    {
        $p = $request->json()->all();
        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key'] as $k) {
            if (! isset($p[$k])) {
                throw new WebhookRejected("Kolom $k tidak ada.");
            }
        }
        $expected = hash('sha512', $p['order_id'].$p['status_code'].$p['gross_amount'].$this->key());
        if (! hash_equals($expected, (string) $p['signature_key'])) {
            throw new WebhookRejected('Tanda tangan Midtrans tidak sah.');
        }

        return $this->fetch((string) $p['order_id'], $p);
    }

    public function status(Invoice $invoice): array
    {
        return $this->fetch((string) $invoice->gateway_ref);
    }

    private function fetch(string $orderId, array $fallback = []): array
    {
        try {
            $st = Http::withBasicAuth($this->key(), '')->acceptJson()->timeout(15)->get($this->apiBase().'/v2/'.rawurlencode($orderId).'/status');
        } catch (\Throwable $e) {
            $st = null; // API tidak terjangkau
        }
        if (! $st?->successful()) {
            if (! $fallback) {
                throw new RuntimeException('Status Midtrans tidak bisa dibaca'.($st ? ' ('.$st->status().')' : '').'.');
            }
            // Notifikasi sudah lolos verifikasi tanda tangan/token; dipakai sebagai cadangan, dicatat untuk audit.
            \Illuminate\Support\Facades\Log::warning('Cek ulang status Midtrans gagal, memakai data notifikasi terverifikasi', ['ref' => $fallback['order_id'] ?? $fallback['external_id'] ?? null]);
        }
        $s = $st?->successful() ? $st->json() : $fallback;
        $tx = (string) ($s['transaction_status'] ?? '');
        $fraud = (string) ($s['fraud_status'] ?? 'accept');
        $paid = $tx === 'settlement' || ($tx === 'capture' && $fraud === 'accept');

        return [
            'ref' => $orderId,
            'status' => $paid ? 'paid' : (in_array($tx, ['pending', 'authorize', ''], true) ? 'pending' : 'failed'),
            'paid' => $paid,
            'amount' => (int) round((float) ($s['gross_amount'] ?? 0)),
            'method' => 'midtrans:'.($s['payment_type'] ?? 'unknown'),
            'payment_ref' => $s['transaction_id'] ?? null,
            'raw_status' => $tx,
        ];
    }
}
