<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Catatan kas manual Parama Tech (operasional, modal, prive, saldo awal). Data pusat. */
class CashEntry extends Model
{
    /** Kategori pemasukan. "operating" = dihitung sebagai pendapatan di laba/rugi. */
    public const IN = [
        'saldo_awal' => ['Saldo awal', false],
        'pendapatan_lain' => ['Pendapatan lain', true],
        'jasa' => ['Jasa / proyek di luar langganan', true],
        'bunga' => ['Bunga / jasa giro bank', true],
        'modal' => ['Setoran modal pemilik', false],
        'pinjaman' => ['Pinjaman diterima', false],
    ];

    /** Kategori pengeluaran. "operating" = dihitung sebagai beban di laba/rugi. */
    public const OUT = [
        'server' => ['Server & hosting', true],
        'domain' => ['Domain & layanan online', true],
        'software' => ['Langganan software / API', true],
        'pemasaran' => ['Pemasaran & iklan', true],
        'alat' => ['Perlengkapan & alat', true],
        'transport' => ['Transport & perjalanan', true],
        'konsumsi' => ['Konsumsi & rapat', true],
        'gaji_lain' => ['Honor / gaji di luar Penggajian', true],
        'biaya_bank' => ['Biaya bank & admin', true],
        'pajak' => ['Pajak', true],
        'sewa' => ['Sewa tempat / utilitas', true],
        'lain' => ['Pengeluaran lain', true],
        'prive' => ['Penarikan pemilik (prive)', false],
        'cicilan' => ['Pembayaran pinjaman', false],
    ];

    public const METHODS = ['transfer' => 'Transfer bank', 'tunai' => 'Tunai', 'lainnya' => 'Lainnya'];

    protected $fillable = ['code', 'entry_date', 'direction', 'category', 'amount', 'description', 'method', 'ref', 'created_by'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'amount' => 'integer'];
    }

    /**
     * Kode referensi otomatis: KM/YYYY/MM/0001 (masuk) & KK/YYYY/MM/0001 (keluar), urut per arah per bulan.
     * Kode tetap saat catatan diubah, kecuali arah atau bulan tanggalnya berubah.
     */
    protected static function booted(): void
    {
        static::creating(fn (CashEntry $e) => $e->code ??= self::nextCode($e->direction, $e->entry_date));
        static::updating(function (CashEntry $e) {
            if ($e->isDirty('direction') || ($e->isDirty('entry_date') && ! str_contains((string) $e->code, '/'.$e->entry_date->format('Y/m').'/'))) {
                $e->code = self::nextCode($e->direction, $e->entry_date);
            }
        });
    }

    public static function nextCode(string $direction, $date): string
    {
        $prefix = self::codePrefix($direction, \Illuminate\Support\Carbon::parse($date));
        $last = self::query()->where('code', 'like', $prefix.'%')->lockForUpdate()->max('code');

        return $prefix.str_pad((string) (($last ? (int) substr($last, strlen($prefix)) : 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    public static function codePrefix(string $direction, \DateTimeInterface $date): string
    {
        return ($direction === 'in' ? 'KM' : 'KK').'/'.$date->format('Y/m').'/';
    }

    public static function categoryLabel(string $direction, string $key): string
    {
        return ($direction === 'in' ? self::IN : self::OUT)[$key][0] ?? $key;
    }

    /** Apakah kategori ini masuk perhitungan laba/rugi (bukan modal/prive/pinjaman/saldo awal). */
    public static function isOperating(string $direction, string $key): bool
    {
        return ($direction === 'in' ? self::IN : self::OUT)[$key][1] ?? true;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
