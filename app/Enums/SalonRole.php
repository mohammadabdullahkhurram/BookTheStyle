<?php

namespace App\Enums;

/**
 * Sub-account (salon) scope roles, attached per salon via SalonMembership.
 * Three roles carry ALL salon permissions: Owner (protected, provisioned at
 * salon creation), Manager (full salon admin surface; absorbed the former
 * front-desk role, which was functionally identical), and Stylist (bookable,
 * own-scope: Today, calendar, own appointments, own availability).
 *
 * Bookability is the ORTHOGONAL staff_type flag ('stylist' or NULL), not the
 * role: a Stylist always has it, and an OWNER may also carry it — the
 * owner-who-cuts-hair case (common in small salons) — without giving up the
 * owner role. Managers are never bookable.
 */
enum SalonRole: string
{
    case Owner = 'salon_owner';
    case Manager = 'salon_manager';
    case Stylist = 'stylist';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Stylist => 'Stylist',
        };
    }

    /**
     * Owner and Manager manage the salon (services, users, policy, calendar).
     */
    public function isManager(): bool
    {
        return in_array($this, [self::Owner, self::Manager], true);
    }
}
