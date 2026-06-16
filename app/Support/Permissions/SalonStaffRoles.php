<?php

namespace App\Support\Permissions;

use App\Enums\SalonRole;
use App\Models\Salon;
use App\Models\User;

/**
 * The single source of truth for which salon roles an actor may grant when
 * inviting or editing staff — i.e. the anti-privilege-escalation rules.
 *
 * - Agency operators authorised for the salon (owner/admin in the agency, or an
 *   assigned agency_user) may grant any salon role.
 * - A salon owner may grant any salon role.
 * - A salon admin may grant salon_admin or staff (NOT salon_owner) — never a
 *   role above their own.
 * - Everyone else may grant nothing.
 *
 * Actions call canAssign() before persisting, so the UI can never be trusted to
 * bypass this.
 */
class SalonStaffRoles
{
    /**
     * @return list<SalonRole>
     */
    public function assignable(User $actor, Salon $salon): array
    {
        if ($actor->operatesSalon($salon)) {
            return [SalonRole::Owner, SalonRole::Admin, SalonRole::User];
        }

        $role = $actor->membershipFor($salon)?->salon_role;

        return match ($role) {
            SalonRole::Owner => [SalonRole::Owner, SalonRole::Admin, SalonRole::User],
            SalonRole::Admin => [SalonRole::Admin, SalonRole::User],
            default => [],
        };
    }

    public function canAssign(User $actor, Salon $salon, SalonRole $role): bool
    {
        return in_array($role, $this->assignable($actor, $salon), true);
    }

    /**
     * Whether the actor may manage staff in this salon at all.
     */
    public function canManageStaff(User $actor, Salon $salon): bool
    {
        return $this->assignable($actor, $salon) !== [];
    }
}
