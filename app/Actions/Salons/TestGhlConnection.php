<?php

namespace App\Actions\Salons;

use App\Models\Salon;
use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlClient;
use App\Services\Ghl\GhlConnectionCheck;

/**
 * Verify a salon's stored GHL credentials with a real read call (list the
 * location's calendars) and stamp last_verified_at on success. Authorisation
 * (SalonPolicy::manageGhlConnection) is enforced by the caller, like the
 * sibling UpdateGhlConnection. Server-side only — the token never leaves.
 */
class TestGhlConnection
{
    public function handle(Salon $salon): GhlConnectionCheck
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null) {
            return new GhlConnectionCheck(false, __('Add the location ID and private integration token first.'));
        }

        try {
            $calendars = GhlClient::fromConnection($connection)->calendars();
        } catch (GhlApiException $e) {
            return new GhlConnectionCheck(false, $e->getMessage());
        }

        // The API filters by the requested location, but be explicit: a
        // calendar reporting a different location means the token/location
        // pair does not belong together.
        foreach ($calendars as $calendar) {
            if ($calendar->locationId !== null && $calendar->locationId !== $connection->location_id) {
                return new GhlConnectionCheck(false, __('The token works, but the calendars belong to a different location — check the location ID.'));
            }
        }

        $connection->last_verified_at = now();
        $connection->save();

        $count = count($calendars);

        return new GhlConnectionCheck(
            true,
            $count === 0
                ? __('Connected — no calendars in this location yet. Create the master calendar in GoHighLevel first.')
                : trans_choice('Connected — :count calendar visible.|Connected — :count calendars visible.', $count, ['count' => $count]),
            $count,
        );
    }
}
