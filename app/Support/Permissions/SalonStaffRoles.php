<?php

namespace App\Support\Permissions;

use App\Enums\SalonRole;
use App\Models\Salon;
use App\Models\User;

/**
 * The single source of truth for which salon roles an actor may grant when
 * inviting or editing staff — i.e. the anti-privilege-escalation rules.
 *
 * Adding/removing a salon OWNER or ADMIN requires an agency owner/admin or the
 * salon's own owner; adding/removing a STYLIST or front-desk (the `user` role)
 * additionally allows a salon admin (and an assigned agency_user). Concretely:
 *
 * - Agency owner/admin (for this salon's agency) → any salon role.
 * - Salon owner → any salon role.
 * - Salon admin → the `user` role only (staff) — never owner/admin.
 * - Assigned agency_user (a delegated salon manager) → the `user` role only.
 * - Everyone else (stylist, front desk, non-members, cross-agency) → nothing.
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
            // Agency owner/admin may grant any role; an assigned agency_user is a
            // delegated salon manager and may grant staff roles only.
            return $actor->isAgencyOperator()
                ? [SalonRole::Owner, SalonRole::Admin, SalonRole::User]
                : [SalonRole::User];
        }

        return match ($actor->membershipFor($salon)?->salon_role) {
            SalonRole::Owner => [SalonRole::Owner, SalonRole::Admin, SalonRole::User],
            // A salon admin manages staff (stylists/front desk) but cannot add,
            // remove, or edit owners/admins.
            SalonRole::Admin => [SalonRole::User],
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
