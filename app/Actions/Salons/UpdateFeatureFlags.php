<?php

namespace App\Actions\Salons;

use App\Jobs\SyncGhlCalendarSlotSettings;
use App\Models\Salon;

/**
 * Replace a salon's feature flags (salon settings). Flags are stored as a flat
 * map of name => bool in salons.feature_flags.
 */
class UpdateFeatureFlags
{
    /**
     * @param  array<string, bool>  $flags
     */
    public function handle(Salon $salon, array $flags): Salon
    {
        $clean = [];
        foreach ($flags as $name => $enabled) {
            $clean[$name] = (bool) $enabled;
        }

        $salon->update(['feature_flags' => $clean === [] ? null : $clean]);

        // The stylist_buffers flag feeds GHL's slot buffer — mirror it.
        SyncGhlCalendarSlotSettings::queueFor($salon);

        return $salon;
    }
}
