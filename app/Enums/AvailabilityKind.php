<?php

namespace App\Enums;

/**
 * A weekly availability window is either working time or a break carved out of
 * it.
 */
enum AvailabilityKind: string
{
    case Work = 'work';
    case Break = 'break';

    public function label(): string
    {
        return match ($this) {
            self::Work => 'Working hours',
            self::Break => 'Break',
        };
    }
}
