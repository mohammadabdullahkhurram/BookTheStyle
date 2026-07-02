<?php

namespace App\Enums;

/**
 * A salon member's operational function, orthogonal to their SalonRole:
 * stylists perform services (calendar column, availability, bookable),
 * front desk runs check-ins, and managers have no operational function —
 * what a manager may do comes entirely from their role. Null = no staff
 * function (the historical default for owners/admins).
 */
enum StaffType: string
{
    case Stylist = 'stylist';
    case FrontDesk = 'front_desk';
    case Manager = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::Stylist => 'Stylist',
            self::FrontDesk => 'Front desk',
            self::Manager => 'Manager',
        };
    }
}
