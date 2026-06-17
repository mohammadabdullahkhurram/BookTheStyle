<?php

namespace App\Enums;

/**
 * Lifecycle of a client visit (SPEC §4).
 */
enum BookingStatus: string
{
    case Booked = 'booked';
    case Confirmed = 'confirmed';
    case Arrived = 'arrived';
    case InService = 'in_service';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::Confirmed => 'Confirmed',
            self::Arrived => 'Arrived',
            self::InService => 'In service',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    /**
     * Flux badge colour.
     */
    public function color(): string
    {
        return match ($this) {
            self::Booked => 'zinc',
            self::Confirmed => 'blue',
            self::Arrived => 'amber',
            self::InService => 'blue',
            self::Completed => 'green',
            self::Cancelled => 'zinc',
            self::NoShow => 'red',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::NoShow], true);
    }

    /**
     * Allowed forward transitions (server-enforced).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Booked => [self::Confirmed, self::Arrived, self::Cancelled, self::NoShow],
            self::Confirmed => [self::Arrived, self::Cancelled, self::NoShow],
            self::Arrived => [self::InService, self::Completed, self::Cancelled],
            self::InService => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
