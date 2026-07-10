<?php

namespace App\Actions\Staff;

use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Notifications\TemporaryPasswordChannel;
use App\Support\Permissions\SalonStaffRoles;
use App\Support\TemporaryPassword;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Admin-initiated password reset for a staff member: issue a fresh temporary
 * password and force a change on next login. Returns the plaintext for one-time
 * display; it is also emailed.
 *
 * Delivery is app-direct transactional mail by decision (supersedes SPEC §5.1's
 * original GHL-routed plan): login-critical mail never depends on a salon's GHL
 * connection. TemporaryPasswordChannel stays swappable if that ever changes.
 */
class ResetStaffPassword
{
    public function __construct(
        private SalonStaffRoles $roles,
        private TemporaryPasswordChannel $channel,
    ) {}

    public function handle(User $actor, Salon $salon, SalonMembership $membership): string
    {
        if ($membership->salon_id !== $salon->id) {
            throw new AuthorizationException('That staff member is not in this salon.');
        }

        if (! $this->roles->canAssign($actor, $salon, $membership->salon_role)) {
            throw new AuthorizationException('You may not manage that staff member.');
        }

        $temporaryPassword = TemporaryPassword::generate();

        $user = $membership->user;
        $user->forceFill([
            'password' => $temporaryPassword,
            'must_change_password' => true,
        ])->save();

        $this->channel->send($user, $temporaryPassword, 'reset');

        return $temporaryPassword;
    }
}
