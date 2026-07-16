<?php

namespace App\Support\Permissions;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The single source of truth for which salon roles an actor may grant when
 * inviting or editing staff — the anti-privilege-escalation rules.
 *
 * OWNER IS NEVER GRANTED THROUGH STAFF MANAGEMENT. A salon's owner is
 * provisioned at salon creation (from the contact person) and is protected:
 * because canAssign() is also checked against a TARGET's current role, no
 * actor can edit, demote, deactivate or reset the owner — the owner manages
 * their own account through account settings. The single exception keeps
 * provisioning/backfill possible: an agency owner/admin may grant Owner to a
 * salon that has NO active owner yet (salon creation, or repairing an
 * ownerless salon).
 *
 * Everyone with management rights grants Admin and Staff:
 * - Agency owner/admin (this salon's agency) → Admin, Staff (+ Owner iff none exists).
 * - Salon owner → Admin, Staff.
 * - Salon admin → Admin, Staff (full salon admin surface; only the owner is out of reach).
 * - Assigned agency_user (a delegated salon manager) → Staff only.
 * - Everyone else (staff, non-members, cross-agency) → nothing.
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
                // Delegated agency_user: staff only.
                return [SalonRole::Staff];
            }

            return $this->salonHasOwner($salon)
                ? [SalonRole::Admin, SalonRole::Staff]
                : [SalonRole::Owner, SalonRole::Admin, SalonRole::Staff];
        }

        return match ($actor->membershipFor($salon)?->salon_role) {
            SalonRole::Owner, SalonRole::Admin => [SalonRole::Admin, SalonRole::Staff],
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

    private function salonHasOwner(Salon $salon): bool
    {
        return $salon->memberships()
            ->where('salon_role', SalonRole::Owner->value)
            ->where('active', true)
            ->exists();
    }

    /**
     * The role follows the staff TYPE (SPEC §2): stylists are Staff
     * (bookable, own-scope); managers and front desk are Admin; a member
     * with no functional type is a plain Admin. Owner is exempt — an owner
     * may also be a working stylist. Enforced server-side on every invite
     * and edit so the pairing can never drift.
     *
     * @throws ValidationException
     */
    public function assertRoleMatchesType(SalonRole $role, ?StaffType $type): void
    {
        if ($role === SalonRole::Owner) {
            return;
        }

        $required = $type === StaffType::Stylist ? SalonRole::Staff : SalonRole::Admin;

        if ($role !== $required) {
            throw ValidationException::withMessages([
                'salon_role' => __('That role does not match the staff type: stylists are Staff; managers and front desk are Admins.'),
            ]);
        }
    }
}
