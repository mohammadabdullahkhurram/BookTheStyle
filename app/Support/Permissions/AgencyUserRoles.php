<?php

namespace App\Support\Permissions;

use App\Enums\AgencyRole;
use App\Models\User;

/**
 * Which agency roles an actor may grant when creating/editing agency users.
 *
 * - Only an agency_owner may create agency owners or admins.
 * - An agency_admin may create agency_users only (never a peer/superior).
 * - Anyone else may grant nothing.
 */
class AgencyUserRoles
{
    /**
     * @return list<AgencyRole>
     */
    public function assignable(User $actor): array
    {
        return match ($actor->agency_role) {
            AgencyRole::Owner => [AgencyRole::Owner, AgencyRole::Admin, AgencyRole::User],
            AgencyRole::Admin => [AgencyRole::User],
            default => [],
        };
    }

    public function canAssign(User $actor, AgencyRole $role): bool
    {
        return in_array($role, $this->assignable($actor), true);
    }
}
