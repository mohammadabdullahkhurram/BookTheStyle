<?php

namespace App\Enums;

/**
 * Agency-scope roles. The agency is the operator that owns many salons.
 */
enum AgencyRole: string
{
    case Owner = 'agency_owner';
    case Admin = 'agency_admin';
    case User = 'agency_user';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Agency Owner',
            self::Admin => 'Agency Admin',
            self::User => 'Agency User',
        };
    }

    /**
     * Owner and Admin have near-full reach across the agency's salons.
     */
    public function isPrivileged(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }
}
