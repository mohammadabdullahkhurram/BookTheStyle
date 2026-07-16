<?php

namespace App\Enums;

/**
 * Sub-account (salon) scope roles, attached per salon via SalonMembership.
 *
 * The ROLE carries permissions; the staff TYPE (stylist / manager / front
 * desk) is functional — only stylists are bookable. Types map to roles:
 * stylist → Staff, manager/front desk → Admin (enforced server-side in
 * InviteStaff / UpdateStaffMembership). Owner is never granted through
 * staff management — it is provisioned at salon creation and protected.
 */
enum SalonRole: string
{
    case Owner = 'salon_owner';
    case Admin = 'salon_admin';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Salon Owner',
            self::Admin => 'Salon Admin',
            self::Staff => 'Staff',
        };
    }

    /**
     * Owner and Admin manage the salon (services, staff, policy, calendar).
     */
    public function isManager(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
