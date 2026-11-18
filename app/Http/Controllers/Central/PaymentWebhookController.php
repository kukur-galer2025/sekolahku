<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifikasi pembayaran dari gateway (domain pusat, tanpa CSRF/sesi).
 * Midtrans:  Payment Notification URL  → https://mypresence.id/webhooks/midtrans
 * Xendit:    Invoice paid callback     → https://mypresence.id/webhooks/xendit
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, BillingService $billing): JsonResponse
    {
        [$code, $msg] = $billing->handleWebhook($gateway, $request);

        return response()->json(['status' => $msg], $code);
    }
}
