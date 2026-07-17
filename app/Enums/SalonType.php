<?php

namespace App\Enums;

/**
 * How a salon engages its stylists (SPEC §2). Governs which per-stylist
 * ARRANGEMENT (StylistArrangement) memberships may carry:
 * Employee → every stylist is an employee; BoothRental → every stylist is a
 * booth renter; Mix → chosen per stylist. Owners/managers are unaffected by
 * type — they see and manage everything, always.
 */
enum SalonType: string
{
    case Employee = 'employee';
    case BoothRental = 'booth_rental';
    case Mix = 'mix';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Employee salon',
            self::BoothRental => 'Booth rental',
            self::Mix => 'Mixed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Employee => 'Stylists work for the salon. They see their own schedule; the front desk books clients in.',
            self::BoothRental => 'Each stylist runs their own business under your roof — they book their own clients and see only their own book.',
            self::Mix => 'Some stylists are employees, some rent a booth — choose for each stylist when you add them.',
        };
    }

    /**
     * The arrangement a stylist membership must carry under this salon type.
     * Null = the salon leaves it selectable (Mix).
     */
    public function forcedArrangement(): ?StylistArrangement
    {
        return match ($this) {
            self::Employee => StylistArrangement::Employee,
            self::BoothRental => StylistArrangement::BoothRental,
            self::Mix => null,
        };
    }
}
