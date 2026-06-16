<?php

namespace App\Enums;

/**
 * Staff type for a salon membership whose role is SalonRole::User.
 * Owners/admins generally leave this null unless they also take bookings.
 */
enum StaffType: string
{
    case Stylist = 'stylist';
    case FrontDesk = 'front_desk';

    public function label(): string
    {
        return match ($this) {
            self::Stylist => 'Stylist',
            self::FrontDesk => 'Front Desk',
        };
    }
}
