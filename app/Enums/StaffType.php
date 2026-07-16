<?php

namespace App\Enums;

/**
 * The bookability flag, orthogonal to SalonRole: a member with staff_type
 * 'stylist' performs services (calendar column, availability, bookable);
 * NULL means no operational function. Since the owner/manager/stylist role
 * rework this is the ONLY case — the former manager/front_desk labels died
 * with the roles they described — but it stays a separate column precisely
 * so an OWNER can also be bookable (the owner-who-cuts-hair case) without
 * the role having to express it.
 */
enum StaffType: string
{
    case Stylist = 'stylist';

    public function label(): string
    {
        return match ($this) {
            self::Stylist => 'Stylist',
        };
    }
}
