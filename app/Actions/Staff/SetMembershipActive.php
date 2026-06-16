<?php

namespace App\Actions\Staff;

use App\Enums\SalonRole;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use App\Support\Permissions\SalonStaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Deactivate/reactivate a staff member's salon membership (no hard delete).
 * The actor must have authority over the membership's role and may not strand
 * the salon without an active owner.
 */
class SetMembershipActive
{
    public function __construct(private SalonStaffRoles $roles) {}

    public function handle(User $actor, Salon $salon, SalonMembership $membership, bool $active): SalonMembership
    {
        if ($membership->salon_id !== $salon->id) {
            throw new AuthorizationException('That staff member is not in this salon.');
        }

        if (! $this->roles->canAssign($actor, $salon, $membership->salon_role)) {
            throw new AuthorizationException('You may not manage that staff member.');
        }

        if (! $active
            && $membership->salon_role === SalonRole::Owner
            && $this->isLastActiveOwner($salon, $membership)) {
            throw ValidationException::withMessages([
                'active' => __('A salon must keep at least one active owner.'),
            ]);
        }

        $membership->update(['active' => $active]);

        return $membership;
    }

    private function isLastActiveOwner(Salon $salon, SalonMembership $membership): bool
    {
        return $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->where('active', true)
            ->whereKeyNot($membership->id)
            ->doesntExist();
    }
}
