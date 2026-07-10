<?php

namespace App\Support\Notifications;

use App\Mail\TemporaryPasswordMail;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailer;

/**
 * Default temporary-password channel: delivers via Laravel Mail. With
 * MAIL_MAILER=log (the local/dev default) the message is written to the log,
 * so staff invites work without any real mail transport configured.
 */
class MailTemporaryPasswordChannel implements TemporaryPasswordChannel
{
    public function __construct(private Mailer $mailer) {}

    public function send(User $user, string $temporaryPassword, string $reason = 'invite'): void
    {
        // Fail-safe: the mailable is queued, and even a synchronous transport
        // failure only gets reported — the caller still returns the plaintext
        // for one-time in-app display, so a broken mailer never locks anyone
        // out. The password itself is never logged.
        rescue(fn () => $this->mailer
            ->to($user->email)
            ->send(new TemporaryPasswordMail($user, $temporaryPassword, $reason)));
    }
}
