<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Heads-up to the agency's owners/admins that a new salon was created
 * under their agency.
 */
class SalonAddedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $salonName,
        public string $agencyName,
        public string $salonUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('New salon added: :salon', ['salon' => $this->salonName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.salon-added',
            with: [
                'name' => $this->recipientName,
                'salonName' => $this->salonName,
                'agencyName' => $this->agencyName,
                'salonUrl' => $this->salonUrl,
            ],
        );
    }
}
