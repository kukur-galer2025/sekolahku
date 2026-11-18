<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\TenantFiles;
use Symfony\Component\HttpFoundation\Response;

/** GET /api/avatar/{user} — foto profil pengguna satu sekolah, untuk semua pengguna yang masuk. */
class AvatarController extends Controller
{
    public function __invoke(int $user, TenantFiles $files): Response
    {
        $u = User::query()->find($user); // tercakup sekolah aktif (BelongsToSchool)
        abort_unless($u && $u->photo_path && $files->owns($u->photo_path) && $files->disk()->exists($u->photo_path), 404);

        return response($files->disk()->get($u->photo_path), 200, [
            'Content-Type' => 'image/webp',
            // URL memuat versi (?v=), jadi aman disimpan lama di browser; private = tidak disimpan proxy/CDN.
            'Cache-Control' => 'private, max-age=2592000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
