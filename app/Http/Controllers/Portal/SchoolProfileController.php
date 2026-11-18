<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\ImageSanitizer;
use App\Services\Platform\AuditLogger;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Profil sekolah (admin) dan foto profil pengguna (API). */
class SchoolProfileController extends Controller
{
    public function __construct(private TenantContext $tenant, private TenantFiles $files, private ImageSanitizer $images) {}

    public function edit(): View
    {
        $s = $this->tenant->get();

        return view('portal.school-profile', ['school' => $s, 'logoUrl' => $this->files->url($s->logo_path)]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $d = $request->validate([
            'address' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'regex:/^\+?[0-9\s-]{8,16}$/'],
            'logo' => ['nullable', 'file', 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
            'npsn' => ['nullable', 'digits:8'],
        ], ['contact_phone.regex' => 'Nomor telepon harus 8–16 digit.', 'npsn.digits' => 'NPSN harus 8 digit angka.']);

        $s = $this->tenant->get();
        $s->fill(['address' => $d['address'] ?? null, 'contact_phone' => $d['contact_phone'] ?? null]);

        // NPSN bisa diisi admin selama sekolah belum diverifikasi; setelah itu dikunci (diubah lewat Parama Tech).
        if (! $s->isVerified() && array_key_exists('npsn', $d)) {
            $s->npsn = $d['npsn'];
        }

        if ($request->hasFile('logo')) {
            $this->files->delete($s->logo_path);
            $s->logo_path = $this->files->put('logo/'.Str::random(12).'.webp', $this->images->webp($request->file('logo'), 512));
        } elseif ($request->boolean('remove_logo')) {
            $this->files->delete($s->logo_path);
            $s->logo_path = null;
        }

        $s->save();
        $audit->log('school.profile', 'Profil sekolah diperbarui', $s);

        return back()->with('ok', 'Profil sekolah tersimpan.');
    }

    /** POST /api/me/photo */
    public function storePhoto(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:5120']], [], ['photo' => 'foto']);
        $u = $request->user();
        $this->files->delete($u->photo_path);
        $u->forceFill(['photo_path' => $this->files->put('photos/'.$u->id.'-'.Str::random(10).'.webp', $this->images->webp($request->file('photo'), 384, true))])->save();

        return response()->json(['photo_url' => \App\Support\Avatar::of($u)]);
    }

    /** DELETE /api/me/photo */
    public function destroyPhoto(Request $request): JsonResponse
    {
        $u = $request->user();
        $this->files->delete($u->photo_path);
        $u->forceFill(['photo_path' => null])->save();

        return response()->json(['photo_url' => null]);
    }
}
