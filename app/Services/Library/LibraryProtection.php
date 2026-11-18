<?php

namespace App\Services\Library;

use App\Enums\Role;
use App\Models\LibraryAccessLog;
use App\Models\LibraryItem;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proteksi konten MP-Library.
 *
 *  - Tiket sekali pakai (2 menit) untuk mengambil isi berkas: terikat ke pengguna, koleksi, dan tujuan (baca / unduh).
 *    Alamat berkas asli tidak pernah dikirim ke peramban.
 *  - Watermark "both": (1) dibakar ke piksel di server untuk gambar, (2) overlay dinamis + dibakar ke kanvas di penampil PDF.
 *    Setiap pembukaan punya kode jejak unik sehingga tangkapan layar yang bocor bisa dilacak ke log audit.
 *  - Pembatasan unduh: default hanya baca di aplikasi; pengunggah/admin dapat mengizinkan unduh dengan batas harian per pengguna.
 */
class LibraryProtection
{
    public const TICKET_TTL = 120; // detik

    public function __construct(private TenantContext $tenant, private TenantFiles $files) {}

    /** Kode jejak 8 karakter (tanpa huruf/angka yang mirip: 0/O, 1/I). */
    public function trace(): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= $abc[random_int(0, strlen($abc) - 1)];
        }

        return $s;
    }

    /** Identitas yang tercetak di watermark. NIK guru tidak pernah dicetak (data pribadi); siswa memakai NIS. */
    public function identity(User $u): array
    {
        $id = match ($u->role) {
            Role::Student => 'NIS '.($u->student?->nis ?? '-'),
            Role::Teacher => 'Guru',
            default => 'Admin',
        };

        return ['name' => Str::limit((string) $u->name, 40, ''), 'id' => $id, 'school' => Str::limit((string) $this->tenant->get()?->name, 50, '')];
    }

    /** Baris watermark: "Nama · NIS 1001", "Sekolah", "11-11-2026 20:15 · #TRACE". */
    public function watermarkLines(User $u, string $trace): array
    {
        $i = $this->identity($u);
        $tz = $this->tenant->get()?->timezone ?: config('app.timezone');

        return [$i['name'].' · '.$i['id'], $i['school'], now($tz)->format('d-m-Y H:i').' · #'.$trace];
    }

    public function canManage(LibraryItem $item, User $u): bool
    {
        return $u->role === Role::Admin || $item->uploaded_by === $u->id;
    }

    /** Sisa jatah unduh hari ini (null = tanpa batas: admin/pengunggah). */
    public function downloadsLeft(LibraryItem $item, User $u): ?int
    {
        if ($this->canManage($item, $u)) {
            return null;
        }
        if (! $item->allow_download || $item->type === 'link') {
            return 0;
        }
        $used = LibraryAccessLog::query()->where('library_item_id', $item->id)->where('user_id', $u->id)
            ->where('event', 'download')->where('created_at', '>=', now()->startOfDay())->count();

        return max(0, $item->download_limit - $used);
    }

    public function issueTicket(LibraryItem $item, User $u, string $purpose, string $trace): string
    {
        $token = Str::random(40);
        Cache::put($this->key($token), ['school' => $this->tenant->id(), 'item' => $item->id, 'user' => $u->id, 'purpose' => $purpose, 'trace' => $trace], self::TICKET_TTL);

        return $token;
    }

    /** Tiket hanya bisa dipakai sekali; tiket milik pengguna/koleksi/sekolah lain ditolak. */
    public function consumeTicket(?string $token, LibraryItem $item, User $u): ?array
    {
        if (! is_string($token) || strlen($token) !== 40) {
            return null;
        }
        $t = Cache::pull($this->key($token));
        if (! $t || $t['school'] !== $this->tenant->id() || $t['item'] !== $item->id || $t['user'] !== $u->id) {
            return null;
        }

        return $t;
    }

    public function log(LibraryItem $item, ?User $u, string $event, ?string $trace = null, ?string $detail = null, ?Request $r = null): void
    {
        LibraryAccessLog::create([
            'library_item_id' => $item->id, 'user_id' => $u?->id, 'user_name' => Str::limit((string) ($u?->name ?? '-'), 120, ''),
            'role' => $u?->role?->value ?? '-', 'event' => $event, 'trace' => $trace, 'detail' => $detail ? Str::limit($detail, 190, '') : null,
            'ip' => $r?->ip(), 'device' => $r ? $this->device((string) $r->userAgent()) : null,
        ]);
    }

    /** Ringkasan perangkat yang mudah dibaca, contoh "Android · Chrome". */
    public function device(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'Android') => 'Android', str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => 'Lainnya',
        };
        $br = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Safari/') => 'Safari', default => 'Peramban',
        };

        return "$os · $br";
    }

    public function contents(LibraryItem $item): string
    {
        $c = $item->file_path ? $this->files->disk()->get($item->file_path) : null;
        if ($c === null) {
            throw new RuntimeException('Berkas tidak ditemukan.');
        }

        return $c;
    }

    /**
     * Watermark dibakar ke piksel gambar: teks miring berulang (semi-transparan) + pita identitas di bawah.
     * Hasil selalu JPEG agar metadata asli (EXIF) ikut terbuang.
     */
    public function stampImage(string $bytes, array $lines): string
    {
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagecopy($img, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);
        imagealphablending($img, true);

        $font = resource_path('fonts/DejaVuSans-Bold.ttf');
        $ttf = function_exists('imagettftext') && is_file($font);
        $text = implode('  ·  ', $lines);
        $size = max(10, (int) round(min($w, $h) / 38));
        $ink = imagecolorallocatealpha($img, 120, 120, 120, 96);   // ~25% tampak
        $shade = imagecolorallocatealpha($img, 255, 255, 255, 104);

        if ($ttf) {
            $box = imagettfbbox($size, 30, $font, $text);
            $tw = abs($box[4] - $box[0]) + $size * 6;
            $th = abs($box[5] - $box[1]) + $size * 5;
            for ($y = -$th; $y < $h + $th; $y += max(40, (int) ($th * 0.9))) {
                for ($x = -$tw; $x < $w + $tw; $x += max(80, (int) ($tw * 0.75))) {
                    imagettftext($img, $size, 30, $x + 1, $y + 1, $shade, $font, $text);
                    imagettftext($img, $size, 30, $x, $y, $ink, $font, $text);
                }
            }
        } else {
            for ($y = 10; $y < $h; $y += 48) {
                for ($x = (intdiv($y, 48) % 2) * 120; $x < $w; $x += strlen($text) * 7 + 60) {
                    imagestring($img, 3, (int) $x, $y, $text, $ink);
                }
            }
        }

        // Pita identitas di tepi bawah: selalu terbaca walau gambar dipotong sebagian.
        $band = max(18, (int) round($size * 1.9));
        imagefilledrectangle($img, 0, $h - $band, $w, $h, imagecolorallocatealpha($img, 15, 23, 42, 40));
        $white = imagecolorallocate($img, 255, 255, 255);
        if ($ttf) {
            imagettftext($img, max(8, (int) round($size * 0.8)), 0, 10, $h - (int) round($band * 0.32), $white, $font, $text);
        } else {
            imagestring($img, 2, 8, $h - $band + 3, $text, $white);
        }

        ob_start();
        imagejpeg($img, null, 86);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function key(string $token): string
    {
        return 'mp-lib-ticket:'.hash('sha256', $token);
    }
}
