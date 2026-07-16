<?php

namespace App\Mail;

use App\Models\Salon;
use App\Support\AppHost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the agency's owners/admins when a salon is created under their
 * agency: the salon's key details, its web address, the contact information
 * captured at creation, and what to do next. Queued + fail-safe at the
 * dispatch site (CreateSalon) — a mail hiccup never blocks salon creation.
 */
class SalonAddedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public Salon $salon,
        public string $agencyName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('New salon created: :salon', ['salon' => $this->salon->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.salon-added',
            with: [
                'name' => $this->recipientName,
                'agencyName' => $this->agencyName,
                'salon' => $this->salon,
                'salonUrl' => AppHost::salon($this->salon->slug),
                'setupUrl' => route('salon.onboarding', $this->salon),
            ],
        );
    }
}
