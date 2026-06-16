<?php

namespace App\Support\Notifications;

use App\Models\User;

/**
 * The one outbound message BookTheStyle sends directly: a user's temporary
 * password (on invite, or on an admin password reset). SPEC §5.1 keeps this
 * behind a swappable sender so the path can move to GoHighLevel later without
 * touching the call sites.
 *
 * The default binding (MailTemporaryPasswordChannel) delivers via Laravel Mail,
 * which writes to the log when MAIL_MAILER=log — so invites work with no real
 * mail configured. A future GhlTemporaryPasswordChannel can replace it via the
 * container binding in AppServiceProvider.
 *
 * @see MailTemporaryPasswordChannel
 */
interface TemporaryPasswordChannel
{
    /**
     * @param  'invite'|'reset'  $reason
     */
    public function send(User $user, string $temporaryPassword, string $reason = 'invite'): void;
}
