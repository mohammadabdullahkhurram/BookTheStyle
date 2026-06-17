<?php

namespace App\Enums;

/**
 * Reason a one-off time off overrides the weekly schedule.
 */
enum TimeOffType: string
{
    case Vacation = 'vacation';
    case Sick = 'sick';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Vacation => 'Vacation',
            self::Sick => 'Sick',
            self::Blocked => 'Blocked',
        };
    }
}
