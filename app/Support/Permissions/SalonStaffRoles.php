<?php

namespace App\Support\Permissions;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The single source of truth for which salon roles an actor may grant when
 * inviting or editing users — the anti-privilege-escalation rules.
 *
 * OWNER IS NEVER GRANTED THROUGH USER MANAGEMENT. A salon's owner is
 * provisioned at salon creation (from the contact person) and is protected:
 * because canAssign() is also checked against a TARGET's current role, no
 * actor can edit, demote, deactivate or reset the owner — the owner manages
 * their own account through account settings. The single exception keeps
 * provisioning/backfill possible: an agency owner/admin may grant Owner to a
 * salon that has NO active owner yet (salon creation, or repairing an
 * ownerless salon).
 *
 * Everyone with management rights grants Manager and Stylist:
 * - Agency owner/admin (this salon's agency) → Manager, Stylist (+ Owner iff none exists).
 * - Salon owner → Manager, Stylist.
 * - Salon manager → Manager, Stylist (full salon surface; only the owner is out of reach).
 * - Assigned agency_user (a delegated salon manager) → Stylist only.
 * - Everyone else (stylists, non-members, cross-agency) → nothing.
 */
class SalonStaffRoles
{
    /**
     * @return list<SalonRole>
     */
    public function assignable(User $actor, Salon $salon): array
    {
        if ($actor->operatesSalon($salon)) {
            if (! $actor->isAgencyOperator()) {
                // Delegated agency_user: stylists only.
                return [SalonRole::Stylist];
            }

            return $this->salonHasOwner($salon)
                ? [SalonRole::Manager, SalonRole::Stylist]
                : [SalonRole::Owner, SalonRole::Manager, SalonRole::Stylist];
        }

        return match ($actor->membershipFor($salon)?->salon_role) {
            SalonRole::Owner, SalonRole::Manager => [SalonRole::Manager, SalonRole::Stylist],
            default => [],
        };
    }

    public function canAssign(User $actor, Salon $salon, SalonRole $role): bool
    {
        return in_array($role, $this->assignable($actor, $salon), true);
    }

    /**
     * Whether the actor may manage users in this salon at all.
     */
    public function canManageStaff(User $actor, Salon $salon): bool
    {
        return $this->assignable($actor, $salon) !== [];
    }

    private function salonHasOwner(Salon $salon): bool
    {
        return $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->where('active', true)
            ->exists();
    }

    /**
     * staff_type is the bookability flag and must agree with the role:
     * a Stylist is always bookable (type 'stylist'); a Manager never is
     * (type NULL). Owner is exempt — an owner may also be a working stylist
     * (the owner-who-cuts-hair case). Enforced server-side on every invite
     * and edit so the pairing can never drift.
     *
     * @throws ValidationException
     */
    public function assertRoleMatchesType(SalonRole $role, ?StaffType $type): void
    {
        if ($role === SalonRole::Owner) {
            return;
        }

        $valid = ($role === SalonRole::Stylist) === ($type === StaffType::Stylist);

        if (! $valid) {
            throw ValidationException::withMessages([
                'salon_role' => __('That role does not match: stylists are bookable; managers are not.'),
            ]);
        }
    }

    /**
     * The bookability flag a role implies for non-owner members.
     */
    public function impliedType(SalonRole $role): ?StaffType
    {
        return $role === SalonRole::Stylist ? StaffType::Stylist : null;
    }
}
