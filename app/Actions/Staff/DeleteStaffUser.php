<?php

namespace App\Actions\Staff;

use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Delete a staff member from a salon. Tenant-safe by construction: the salon
 * surface removes THIS salon's membership only; the account itself is
 * soft-deleted only when nothing else references it (no other salon
 * membership, no agency role) — a person working at two salons deleted from
 * one keeps logging in to the other.
 *
 * Authorization mirrors every other staff action: the actor must have
 * authority over the target's CURRENT role (SalonStaffRoles::canAssign), so
 * the salon owner is undeletable here by anyone — owner accounts leave only
 * via self-deletion. Self-deletion from the staff screen is refused too; it
 * lives in account settings under its own rule (User::canDeleteOwnAccount).
 *
 * Booking history survives: bookings/status events keep their user id, and
 * the history relations resolve names withTrashed(). Passkeys are removed on
 * account deletion (User model event) so a deleted user cannot passkey in.
 */
class DeleteStaffUser
{
    public function __construct(private SalonStaffRoles $roles) {}

    /**
     * @return bool whether the ACCOUNT was deleted (vs membership only)
     */
    public function handle(User $actor, Salon $salon, SalonMembership $membership): bool
    {
        if ($membership->salon_id !== $salon->id) {
            throw new AuthorizationException('That staff member is not in this salon.');
        }

        if ($membership->user_id === $actor->id) {
            throw new AuthorizationException('You cannot delete yourself from the staff screen. Account deletion lives in your account settings.');
        }

        if (! $this->roles->canAssign($actor, $salon, $membership->salon_role)) {
            throw new AuthorizationException('You may not manage that staff member.');
        }

        $user = $membership->user;

        return DB::transaction(function () use ($membership, $user): bool {
            $membership->delete();

            $accountRemovable = $user->agency_role === null
                && ! $user->salonMemberships()->exists();

            if ($accountRemovable) {
                $user->delete();
            }

            return $accountRemovable;
        });
    }
}
