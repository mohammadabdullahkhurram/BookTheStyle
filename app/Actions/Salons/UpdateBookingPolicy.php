<?php

namespace App\Actions\Salons;

use App\Models\Salon;

/**
 * Update a salon's booking policy (salon settings). Authorisation
 * (SalonPolicy::manage) is enforced by the caller; values are validated there.
 */
class UpdateBookingPolicy
{
    /**
     * @param  array{allow_walkins: bool, allow_same_day: bool, max_advance_days: int, min_notice_minutes: int}  $data
     */
    public function handle(Salon $salon, array $data): Salon
    {
        $salon->update([
            'allow_walkins' => $data['allow_walkins'],
            'allow_same_day' => $data['allow_same_day'],
            'max_advance_days' => $data['max_advance_days'],
            'min_notice_minutes' => $data['min_notice_minutes'],
        ]);

        return $salon;
    }
}
