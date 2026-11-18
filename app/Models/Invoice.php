<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToSchool;

    public const TYPES = ['new' => 'Langganan', 'renewal' => 'Perpanjangan', 'upgrade' => 'Tambah kuota', 'custom' => 'Tagihan khusus'];

    protected $fillable = [
        'school_id', 'number', 'type', 'status', 'student_count', 'months', 'unit_price', 'gross', 'discount', 'tax', 'unique_code', 'total', 'paid_amount', 'activated_at',
        'description', 'lines', 'period_start', 'period_end', 'due_at', 'paid_at', 'payment_method', 'payment_ref',
        'gateway', 'gateway_ref', 'gateway_invoice_id', 'payment_url', 'checkout_attempts', 'transfer_proof_path', 'transfer_proof_at',
        'created_by', 'notes',
    ];

    protected $hidden = ['transfer_proof_path'];

    protected function casts(): array
    {
        return [
            'lines' => 'array', 'period_start' => 'date', 'period_end' => 'date', 'due_at' => 'datetime',
            'paid_at' => 'datetime', 'transfer_proof_at' => 'datetime', 'activated_at' => 'datetime', 'paid_amount' => 'integer',
            'student_count' => 'integer', 'months' => 'integer', 'unit_price' => 'integer',
            'gross' => 'integer', 'discount' => 'integer', 'tax' => 'integer', 'total' => 'integer',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPayable(): bool
    {
        return $this->status === 'unpaid';
    }

    /** Sudah ada DP / pembayaran sebagian, tapi belum lunas. */
    public function isPartial(): bool
    {
        return $this->status === 'unpaid' && (int) $this->paid_amount > 0;
    }

    /** Sisa yang harus dibayar (0 bila lunas/batal). */
    public function remaining(): int
    {
        return $this->status === 'unpaid' ? max(0, (int) $this->total - (int) $this->paid_amount) : 0;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_on')->orderBy('id');
    }

    /** Jumlah per jenis untuk ringkasan invoice: [dp, tambahan (termasuk pelunasan)]. */
    public function paymentBreakdown(): array
    {
        $p = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();
        $dp = (int) $p->where('kind', 'dp')->sum('amount');

        return ['dp' => $dp, 'other' => (int) $p->sum('amount') - $dp, 'first_dp' => $p->firstWhere('kind', 'dp')?->paid_on];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public static function rupiah(int|float $n): string
    {
        return 'Rp'.number_format((float) $n, 0, ',', '.');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
