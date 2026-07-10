<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A staff member was invited to a salon. New users get their temporary
 * password here (login-critical — the same plaintext is also shown once
 * in-app so a failed email never locks anyone out); existing users just
 * learn they now have access to the salon.
 */
class StaffInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $salonName,
        public string $roleLabel,
        public ?string $temporaryPassword,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You have been invited to :salon', ['salon' => $this->salonName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.staff-invite',
            with: [
                'name' => $this->recipientName,
                'salonName' => $this->salonName,
                'roleLabel' => $this->roleLabel,
                'temporaryPassword' => $this->temporaryPassword,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }
}
