<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TemporaryPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  'invite'|'reset'  $reason
     */
    public function __construct(
        public User $user,
        public string $temporaryPassword,
        public string $reason = 'invite',
    ) {}

    public function envelope(): Envelope
    {
        $app = config('app.name');

        return new Envelope(
            subject: $this->reason === 'reset'
                ? __('Your :app password was reset', ['app' => $app])
                : __('Your :app account is ready', ['app' => $app]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.temporary-password',
            with: [
                'name' => $this->user->name,
                'temporaryPassword' => $this->temporaryPassword,
                'reason' => $this->reason,
                'loginUrl' => route('login'),
            ],
        );
    }
}
