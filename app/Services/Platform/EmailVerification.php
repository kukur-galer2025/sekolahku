<?php

namespace App\Services\Platform;

use App\Mail\VerifyEmailMail;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/** Verifikasi email admin sekolah lewat tautan bertanda tangan ke subdomain sekolahnya. */
class EmailVerification
{
    public function url(User $user, School $school): string
    {
        return URL::temporarySignedRoute('portal.verify', now()->addDays(3), [
            'tenant' => $school->subdomain, 'id' => $user->id, 'hash' => sha1((string) $user->email),
        ]);
    }

    public function send(User $user, School $school): void
    {
        if ($user->email && ! $user->email_verified_at) {
            Mail::to($user->email)->send(new VerifyEmailMail($user, $school, $this->url($user, $school)));
        }
    }
}
