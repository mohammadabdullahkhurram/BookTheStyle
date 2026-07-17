<?php

namespace App\Enums;

/**
 * A stylist's working arrangement with the salon (SPEC §2), carried on the
 * membership. Employees see the shared salon calendar but never create
 * bookings or open clients/reports; booth renters are separate businesses —
 * they create and manage their OWN bookings, see only their own book, their
 * own clients (derived from bookings served), and their own revenue. Only
 * meaningful on stylist-role memberships; owners/managers ignore it.
 */
enum StylistArrangement: string
{
    case Employee = 'employee';
    case BoothRental = 'booth_rental';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Employee',
            self::BoothRental => 'Booth renter',
        };
    }
}
