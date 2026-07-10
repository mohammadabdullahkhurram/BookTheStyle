<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Welcome email for a freshly created account (agency user or salon staff):
 * who set it up, where to sign in. Credentials travel separately (the
 * temporary-password / staff-invite email), so this one carries no secrets.
 */
class AccountCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $workplaceName,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Welcome to :app', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.account-created',
            with: [
                'name' => $this->recipientName,
                'workplace' => $this->workplaceName,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
