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
        $this->mailer
            ->to($user->email)
            ->send(new TemporaryPasswordMail($user, $temporaryPassword, $reason));
    }
}
