<?php

namespace App\Enums;

/**
 * Sub-account (salon) scope roles, attached per salon via SalonMembership.
 */
enum SalonRole: string
{
    case Owner = 'salon_owner';
    case Admin = 'salon_admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Salon Owner',
            self::Admin => 'Salon Admin',
            self::User => 'Staff',
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
