<?php

namespace App\Actions\Salons;

use App\Jobs\SyncAvailabilityToGhl;
use App\Models\Salon;

/**
 * Change the salon's IANA timezone. Safe by design: booking instants are
 * stored in UTC and never move — the slot engine, calendar grids, ICS feeds
 * and GHL pushes all read $salon->timezone at call time (never a cached
 * copy), so only the DISPLAYED local times and the interpretation of weekly
 * availability windows follow the new zone. Authorisation
 * (SalonPolicy::manage) is enforced by the caller.
 */
class UpdateTimezone
{
    public function handle(Salon $salon, string $timezone): Salon
    {
        $salon->update(['timezone' => $timezone]);

        // GHL availability schedules store the timezone — re-push them all.
        SyncAvailabilityToGhl::queueForSalon($salon);

        return $salon;
    }
}
