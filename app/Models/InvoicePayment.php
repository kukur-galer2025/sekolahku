<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pembayaran bertahap sebuah invoice: DP / uang muka, pembayaran tambahan, pelunasan. Data pusat (panel Parama Tech). */
class InvoicePayment extends Model
{
    public const KINDS = ['dp' => 'DP / Uang muka', 'tambahan' => 'Pembayaran tambahan', 'pelunasan' => 'Pelunasan'];

    public const METHODS = ['transfer_manual' => 'Transfer bank', 'tunai' => 'Tunai', 'lainnya' => 'Lainnya', 'gateway' => 'Pembayaran online'];

    protected $fillable = ['invoice_id', 'kind', 'amount', 'paid_on', 'method', 'ref', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'date'];
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
