<?php

namespace App\Services\Billing\Gateways;

use App\Models\Invoice;
use App\Models\School;
use Illuminate\Http\Request;

interface PaymentGateway
{
    public function name(): string;

    /** Buat sesi pembayaran di gateway. @return array{ref:string,url:string,gateway_invoice_id:?string} */
    public function checkout(Invoice $invoice, School $school, string $orderId, string $finishUrl): array;

    /**
     * Baca & verifikasi notifikasi. Melempar WebhookRejected bila tanda tangan/token tidak sah.
     * @return array{ref:string,status:string,paid:bool,amount:int,method:?string,payment_ref:?string}
     *   status: paid | pending | failed
     */
    public function webhook(Request $request): array;

    /** Tanyakan status terbaru langsung ke gateway (sumber kebenaran). Format sama dengan webhook(). */
    public function status(Invoice $invoice): array;
}
