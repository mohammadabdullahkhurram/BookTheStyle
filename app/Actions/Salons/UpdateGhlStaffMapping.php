<?php

namespace App\Actions\Salons;

use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\StylistProfile;
use Illuminate\Validation\ValidationException;

/**
 * Persist the salon's chosen GHL master calendar and the TWO-TIER staff
 * mapping:
 *
 * - Stylists → a CALENDAR TEAM MEMBER (provider) on the master calendar,
 *   stored on stylist_profiles.ghl_user_id. This is what 6b uses to route
 *   an appointment push to the right GHL provider.
 * - All other staff (front desk, managers, owners, admins) → a GHL LOCATION
 *   USER, stored on salon_memberships.ghl_location_user_id. Identity and
 *   attribution only — it never makes anyone bookable and never routes
 *   bookings.
 *
 * Each map only accepts its own tier: stylist ids must be active stylists of
 * THIS salon, staff ids active non-stylist members of THIS salon. Forged,
 * cross-salon or wrong-tier ids are rejected. Authorisation
 * (SalonPolicy::manageGhlConnection) is enforced by the caller.
 */
class UpdateGhlStaffMapping
{
    /**
     * @param  array<int|string, string|null>  $stylistMap  stylist user id => GHL provider (calendar team member) id ('' clears)
     * @param  array<int|string, string|null>  $staffMap  non-stylist user id => GHL location user id ('' clears)
     */
    public function handle(Salon $salon, ?string $masterCalendarId, array $stylistMap, array $staffMap = []): void
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->hasToken()) {
            throw ValidationException::withMessages([
                'ghl' => __('Connect GoHighLevel before mapping staff.'),
            ]);
        }

        $stylistIds = $salon->stylistUsers()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();

        foreach (array_keys($stylistMap) as $userId) {
            if (! in_array((int) $userId, $stylistIds, true)) {
                throw ValidationException::withMessages([
                    'ghl' => __('That person is not an active stylist in this salon.'),
                ]);
            }
        }

        // Active non-stylist memberships of this salon (owners/admins with no
        // staff type included) — the identity tier.
        $staffMemberships = $salon->memberships()
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('staff_type')->orWhere('staff_type', '!=', StaffType::Stylist->value))
            ->get()
            ->keyBy('user_id');

        foreach (array_keys($staffMap) as $userId) {
            if (! $staffMemberships->has((int) $userId)) {
                throw ValidationException::withMessages([
                    'ghl' => __('That person is not an active non-stylist staff member of this salon.'),
                ]);
            }
        }

        $masterCalendarId = trim((string) $masterCalendarId);
        if ($masterCalendarId !== '') {
            $connection->calendar_id = $masterCalendarId;
            $connection->save();
        }

        foreach ($stylistMap as $userId => $ghlUserId) {
            $ghlUserId = trim((string) $ghlUserId);

            StylistProfile::updateOrCreate(
                ['salon_id' => $salon->id, 'user_id' => (int) $userId],
                ['ghl_user_id' => $ghlUserId === '' ? null : $ghlUserId],
            );
        }

        foreach ($staffMap as $userId => $ghlUserId) {
            $ghlUserId = trim((string) $ghlUserId);

            $staffMemberships->get((int) $userId)->update([
                'ghl_location_user_id' => $ghlUserId === '' ? null : $ghlUserId,
            ]);
        }
    }
}
