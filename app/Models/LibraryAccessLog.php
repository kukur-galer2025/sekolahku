<?php

namespace App\Models;

use App\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Jejak audit MP-Library: siapa membuka / membaca / mengunduh apa, kapan, dan percobaan yang diblokir. */
class LibraryAccessLog extends Model
{
    use BelongsToSchool;

    public const UPDATED_AT = null;

    /** Peristiwa yang boleh dilaporkan oleh penampil (frontend). Sisanya hanya dicatat server. */
    public const CLIENT_EVENTS = ['view', 'copy', 'print', 'screenshot', 'devtools', 'hidden', 'contextmenu', 'shortcut', 'drag'];

    public const LABELS = [
        'open' => 'Membuka', 'view' => 'Selesai membaca', 'download' => 'Mengunduh', 'blocked_download' => 'Unduhan ditolak',
        'link' => 'Membuka tautan', 'copy' => 'Coba menyalin', 'print' => 'Coba mencetak', 'screenshot' => 'Coba tangkap layar',
        'devtools' => 'Membuka DevTools', 'hidden' => 'Pindah aplikasi/tab', 'contextmenu' => 'Klik kanan', 'shortcut' => 'Pintasan diblokir',
        'drag' => 'Coba menyeret', 'ticket_invalid' => 'Tiket tidak sah', 'policy' => 'Ubah kebijakan',
    ];

    /** Peristiwa yang dianggap mencurigakan (ditandai merah di laporan). */
    public const SUSPICIOUS = ['blocked_download', 'copy', 'print', 'screenshot', 'devtools', 'shortcut', 'drag', 'ticket_invalid'];

    protected $fillable = ['library_item_id', 'user_id', 'user_name', 'role', 'event', 'trace', 'detail', 'ip', 'device'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LibraryItem::class, 'library_item_id');
    }
}
