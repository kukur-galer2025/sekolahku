<?php

namespace App\Mail;

use App\Models\School;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerifyEmailMail extends Mailable
{
    public function __construct(public User $user, public School $school, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verifikasi email admin '.$this->school->name.' — MyPresence');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.verify-email');
    }
}
