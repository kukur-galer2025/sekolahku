<?php

namespace App\Mail;

use App\Models\School;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email langganan: invoice | receipt | reminder | expired | suspended
 */
class BillingMail extends Mailable
{
    public function __construct(public string $kind, public School $school, public User $user, public array $data = []) {}

    public function envelope(): Envelope
    {
        $inv = $this->data['invoice'] ?? null;
        $subject = match ($this->kind) {
            'invoice' => "Tagihan {$inv?->number} — MyPresence {$this->school->name}",
            'receipt' => "Pembayaran diterima ({$inv?->number}) — MyPresence",
            'reminder' => 'Masa '.($this->data['trial'] ?? false ? 'trial' : 'langganan').' MyPresence berakhir '.($this->data['days'] <= 1 ? 'besok' : "{$this->data['days']} hari lagi"),
            'expired' => 'Masa '.($this->data['trial'] ?? false ? 'trial' : 'langganan').' MyPresence sudah berakhir — masa tenggang',
            'suspended' => 'Layanan MyPresence '.$this->school->name.' diblokir sementara',
            default => 'MyPresence',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.billing', with: ['billingUrl' => $this->school->url('langganan')]);
    }
}
