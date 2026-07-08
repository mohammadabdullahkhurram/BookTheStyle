<?php

namespace App\Actions\Salons;

use App\Models\Salon;
use App\Models\StylistProfile;
use Illuminate\Validation\ValidationException;

/**
 * Persist the salon's chosen GHL master calendar and the stylist ↔ GHL user
 * (team member) mapping that 6b will route appointment pushes with.
 *
 * Every mapped id must be an active stylist of THIS salon — forged or
 * non-stylist ids are rejected, so the mapping can never reach across salons
 * or attach a GHL identity to a front-desk/manager member. Authorisation
 * (SalonPolicy::manageGhlConnection) is enforced by the caller.
 */
class UpdateGhlStylistMapping
{
    /**
     * @param  array<int|string, string|null>  $map  stylist user id => GHL user id ('' clears)
     */
    public function handle(Salon $salon, ?string $masterCalendarId, array $map): void
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->hasToken()) {
            throw ValidationException::withMessages([
                'ghl' => __('Connect GoHighLevel before mapping stylists.'),
            ]);
        }

        $stylistIds = $salon->stylistUsers()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_keys($map) as $userId) {
            if (! in_array((int) $userId, $stylistIds, true)) {
                throw ValidationException::withMessages([
                    'ghl' => __('That person is not an active stylist in this salon.'),
                ]);
            }
        }

        $masterCalendarId = trim((string) $masterCalendarId);
        if ($masterCalendarId !== '') {
            $connection->calendar_id = $masterCalendarId;
            $connection->save();
        }

        foreach ($map as $userId => $ghlUserId) {
            $ghlUserId = trim((string) $ghlUserId);

            StylistProfile::updateOrCreate(
                ['salon_id' => $salon->id, 'user_id' => (int) $userId],
                ['ghl_user_id' => $ghlUserId === '' ? null : $ghlUserId],
            );
        }
    }
}
