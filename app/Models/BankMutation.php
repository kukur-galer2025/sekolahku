<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu baris mutasi rekening bisnis yang diimpor ke panel. Data pusat. */
class BankMutation extends Model
{
    public const STATUSES = ['pending' => 'Belum diproses', 'matched' => 'Cocok dengan invoice', 'recorded' => 'Dicatat di buku kas', 'ignored' => 'Diabaikan'];

    protected $fillable = ['hash', 'trx_date', 'description', 'direction', 'amount', 'status', 'suggested_invoice_id', 'suggest_reason',
        'invoice_id', 'invoice_payment_id', 'cash_entry_id', 'batch', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return ['trx_date' => 'date', 'amount' => 'integer', 'handled_at' => 'datetime'];
    }

    public function suggested(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'suggested_invoice_id')->withoutGlobalScopes();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScopes();
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(CashEntry::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
