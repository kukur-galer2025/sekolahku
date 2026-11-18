<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Services\ClassQrCode;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * PWA MyPresence: satu aplikasi untuk admin, guru, dan siswa di {sekolah}.mypresence.id/app
 * Tanpa build step: HTML shell + ES modules di public/pwa, service worker di /sw.js.
 */
class PwaController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    /** Ikon per aplikasi (warna varian): /pwa/icons/{admin|guru|siswa}-192.png; tanpa aplikasi = ikon putih bawaan. */
    public static function icon(?string $app, string $kind): string
    {
        if (! $app || ! array_key_exists($app, self::APPS)) {
            return ['192' => '/pwa/icons/icon-192.png', '512' => '/pwa/icons/icon-512.png', 'maskable' => '/pwa/icons/maskable-512.png', 'apple' => '/pwa/icons/apple-touch-icon.png'][$kind];
        }

        return '/pwa/icons/'.$app.'-'.['192' => '192', '512' => '512', 'maskable' => 'maskable-512', 'apple' => 'apple-touch'][$kind].'.png';
    }

    public static function version(): string
    {
        $files = ['pwa/app.js', 'pwa/pages.js', 'pwa/student.js', 'pwa/features.js', 'pwa/cbt.js', 'pwa/biodata.js', 'pwa/denah.js', 'pwa/denah-poster.js', 'pwa/library-viewer.js', 'pwa/daftar-nilai.js', 'pwa/kas.js', 'pwa/camera.js', 'pwa/app.css', 'css/portal.css', 'sw.js', 'pwa/ti.svg'];

        return substr(md5(implode('|', array_map(fn ($f) => @filemtime(public_path($f)) ?: 0, $files))), 0, 10);
    }

    /** Tiga aplikasi terpisah di satu backend: /admin, /guru, /siswa. /app = pintu lama yang mengarahkan sesuai peran. */
    public const APPS = [
        // color = warna varian identitas ParamaEdu.Tech per aplikasi (theme-color & ikon): Admin Blue, Guru Teal, Siswa Amber.
        'admin' => ['label' => 'Admin', 'roles' => ['admin'], 'hint' => 'Username atau email admin', 'color' => '#0E7BFF'],
        'guru' => ['label' => 'Guru', 'roles' => ['teacher'], 'hint' => 'NIK guru', 'color' => '#00A890'],
        'siswa' => ['label' => 'Siswa', 'roles' => ['student'], 'hint' => 'NIS siswa', 'color' => '#FFC107'],
    ];

    /** Aplikasi yang sesuai untuk peran pengguna: student → siswa, teacher → guru, admin → admin. */
    public static function appForRole(string $role): string
    {
        return ['student' => 'siswa', 'teacher' => 'guru'][$role] ?? 'admin';
    }

    public function shell(TenantFiles $files, ?string $app = null): Response
    {
        $s = $this->tenant->get();
        $app = array_key_exists((string) $app, self::APPS) ? $app : null;
        $html = view('pwa.app', [
            'school' => $s, 'version' => self::version(), 'app' => $app, 'appLabel' => $app ? self::APPS[$app]['label'] : null,
            'boot' => [
                'app' => $app, 'app_label' => $app ? self::APPS[$app]['label'] : null, 'login_hint' => $app ? self::APPS[$app]['hint'] : null,
                'school' => ['name' => $s->name, 'subdomain' => $s->subdomain, 'timezone' => $s->timezone, 'logo' => $files->url($s->logo_path, 60 * 24), 'verified' => $s->isVerified(),
                    'plan' => \App\Support\Plan::normalize($s->plan), 'plan_label' => \App\Support\Plan::label($s->plan), 'locked' => \App\Support\Plan::locked($s->plan),
                    'has_map' => app(\App\Services\Platform\SchoolMapImage::class)->get($s) !== null,
                    'menu' => \App\Support\SchoolMenu::of($s)], // nama menu per portal & menu disembunyikan (diatur panel)
                'central' => config('tenancy.central_domain'),
                'version' => self::version(),
                'portal' => [
                    'imports' => route('portal.imports'), 'billing' => route('portal.billing'), 'privacy' => route('portal.privacy'),
                    'school' => route('portal.school'), 'audit' => route('portal.audit'), 'print_qr' => route('pwa.print-qr'),
                    'forgot' => route('portal.password.forgot'), 'classrooms' => route('portal.classrooms'), 'year' => route('portal.year'), 'share' => route('portal.share'),
                ],
            ],
        ])->render();

        // Shell tidak berisi data pribadi; tetap tidak di-cache proxy agar versi baru langsung terambil.
        return response($html)->header('Cache-Control', 'no-cache');
    }

    public function manifest(?string $app = null): JsonResponse
    {
        $s = $this->tenant->get();
        $app = array_key_exists((string) $app, self::APPS) ? $app : null;
        $label = $app ? self::APPS[$app]['label'] : null;
        $start = $app ? '/'.$app : '/app';
        $short = $label ? 'MP '.$label : (mb_strlen($s->name) > 12 ? 'MyPresence' : $s->name);

        return response()->json([
            // id berbeda = aplikasi terpisah di HP (ikon "MP Siswa", "MP Guru", "MP Admin").
            'id' => $start,
            'name' => 'MyPresence'.($label ? ' '.$label : '').' · '.$s->name,
            'short_name' => $short,
            'description' => 'Presensi QR, jadwal, jurnal, dan tugas '.$s->name,
            'lang' => 'id',
            'start_url' => $start,
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#F4F7FC',
            'theme_color' => $app ? self::APPS[$app]['color'] : '#0B2D6B',
            'categories' => ['education', 'productivity'],
            'icons' => [
                ['src' => self::icon($app, '192'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => self::icon($app, '512'), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => self::icon($app, 'maskable'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Absensi QR', 'url' => $start.'#/qr', 'icons' => [['src' => self::icon($app, '192'), 'sizes' => '192x192']]],
                ['name' => 'Jadwal', 'url' => $start.'#/jadwal', 'icons' => [['src' => self::icon($app, '192'), 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** QR (SVG) untuk teks/URL apa pun — dipakai halaman Bagikan Aplikasi, poster, dan kartu aktivasi. */
    public static function qr(string $data): string
    {
        $o = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG, 'outputBase64' => false,
            'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M, 'addQuietzone' => true, 'svgUseFillAttributes' => true]);

        return (new \chillerlan\QRCode\QRCode($o))->render($data);
    }

    /** Pesan WhatsApp siap kirim. Link boleh disebar ke grup: tidak memuat rahasia apa pun. */
    public static function shareText(\App\Models\School $s, string $app): string
    {
        $who = $app === 'siswa' ? 'NIS dan password awal dari wali kelas' : ($app === 'guru' ? 'NIK dan password awal dari admin sekolah' : 'akun admin');

        return "📱 Pasang aplikasi *MyPresence ".self::APPS[$app]['label']."* – {$s->name}\n".$s->url($app)."\n\n"
            ."Atau buka ".\App\Models\School::codeEntryUrl($app)." dan ketik kode sekolah *".\App\Support\SchoolCode::format($s->access_code)."*.\n\n"
            ."Android: buka link, ketuk *Pasang aplikasi*.\niPhone: buka di Safari, ketuk *Bagikan → Tambahkan ke Layar Utama*.\n"
            ."Masuk dengan {$who}, lalu ganti password.";
    }

    /** Menu admin: link, QR, WhatsApp, dan poster untuk aplikasi Guru & Siswa. */
    public function share(): View
    {
        $s = $this->tenant->get();
        $apps = collect(['siswa', 'guru'])->mapWithKeys(fn ($a) => [$a => [
            'label' => self::APPS[$a]['label'], 'url' => $s->url($a), 'qr' => self::qr($s->url($a)),
            'wa' => 'https://wa.me/?text='.rawurlencode(self::shareText($s, $a)),
        ]]);

        return view('portal.share', ['school' => $s, 'apps' => $apps, 'adminUrl' => $s->url('admin')]);
    }

    /** Poster A4 siap cetak (ruang guru / kelas / mading). */
    public function poster(TenantFiles $files, string $app): View
    {
        abort_unless(in_array($app, ['siswa', 'guru'], true), 404);
        $s = $this->tenant->get();

        return view('portal.share-poster', ['school' => $s, 'app' => $app, 'label' => self::APPS[$app]['label'],
            'url' => $s->url($app), 'qr' => self::qr($s->url($app)), 'logo' => $files->url($s->logo_path, 60)]);
    }

    /** Lembar QR statis semua kelas (atau satu kelas) untuk dicetak & ditempel. Khusus admin. */
    public function printQr(ClassQrCode $qr): View
    {
        $school = $this->tenant->get();
        $q = Classroom::query()->orderBy('name');
        if ($id = request()->integer('kelas')) {
            $q->whereKey($id);
        }
        $o = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG, 'outputBase64' => false,
            'eccLevel' => \chillerlan\QRCode\QRCode::ECC_M, 'addQuietzone' => true, 'svgUseFillAttributes' => true]);

        $cards = $q->get()->map(fn ($c) => [
            'name' => $c->name,
            'payload' => $p = $qr->current($c, ClassQrCode::STATIC)['payload'],
            'svg' => (new \chillerlan\QRCode\QRCode($o))->render($p),
        ]);

        // QR kehadiran guru (langkah ①) ikut dicetak bila mencetak semua kelas atau diminta khusus (?guru=1).
        $gate = null;
        if (! request()->integer('kelas') || request()->boolean('guru')) {
            $gp = app(\App\Services\TeacherAttendanceService::class)->gatePayload($school);
            $gate = ['payload' => $gp, 'svg' => (new \chillerlan\QRCode\QRCode($o))->render($gp)];
            if (request()->boolean('guru')) {
                $cards = collect();
            }
        }

        return view('pwa.print-qr', ['school' => $school, 'cards' => $cards, 'gate' => $gate, 'dynamic' => $school->qrMode() === ClassQrCode::DYNAMIC]);
    }
}
