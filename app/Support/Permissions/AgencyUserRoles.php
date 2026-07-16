<?php

namespace App\Support\Permissions;

use App\Enums\AgencyRole;
use App\Models\User;

/**
 * Which agency roles an actor may grant when creating/editing agency users.
 *
 * THERE IS EXACTLY ONE AGENCY OWNER, EVER. Owner is never assignable — not
 * by the owner, not by anyone — so no path creates, promotes to, or invites
 * a second owner. Because actions also check canAssign() against a TARGET's
 * current role, the owner is equally untouchable: no one (agency admins
 * included) can edit, demote, deactivate or delete them. The owner manages
 * their own account through account settings.
 *
 * - agency_owner → may grant Admin and User.
 * - agency_admin → may grant User only (never a peer/superior).
 * - Anyone else → nothing.
 */
class AgencyUserRoles
{
    /**
     * @return list<AgencyRole>
     */
    public function assignable(User $actor): array
    {
        return match ($actor->agency_role) {
            AgencyRole::Owner => [AgencyRole::Admin, AgencyRole::User],
            AgencyRole::Admin => [AgencyRole::User],
            default => [],
        };
    }

    public function canAssign(User $actor, AgencyRole $role): bool
    {
        return in_array($role, $this->assignable($actor), true);
    }
}
