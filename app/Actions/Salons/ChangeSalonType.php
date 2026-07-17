<?php

namespace App\Actions\Salons;

use App\Enums\SalonRole;
use App\Enums\SalonType;
use App\Models\Salon;
use Illuminate\Support\Facades\DB;

/**
 * Change a salon's type — this changes REAL PEOPLE'S permissions, so the UI
 * confirms with the exact consequences before calling. Authorisation
 * (AgencyPolicy::manageSalons) is enforced by the caller. Transition rules:
 *
 *   → employee:     EVERY stylist becomes an employee. Booth renters lose
 *                   self-booking, their client list, and their reports view
 *                   (no data is deleted — their bookings, clients and history
 *                   remain; visibility narrows) and gain the shared board.
 *   → booth_rental: EVERY stylist becomes a booth renter — they gain
 *                   self-booking/clients/reports and LOSE the shared board.
 *   → mix:          nothing changes — existing arrangements are preserved
 *                   (mix permits both); adjust per stylist afterwards.
 */
class ChangeSalonType
{
    /**
     * @return int how many stylist memberships changed arrangement
     */
    public function handle(Salon $salon, SalonType $to): int
    {
        return DB::transaction(function () use ($salon, $to): int {
            $salon->update(['salon_type' => $to]);

            $forced = $to->forcedArrangement();

            if ($forced === null) {
                return 0; // Mix preserves every existing arrangement.
            }

            return $salon->memberships()
                ->where('salon_role', SalonRole::Stylist->value)
                ->where('arrangement', '!=', $forced->value)
                ->update(['arrangement' => $forced->value]);
        });
    }

    /**
     * How many stylists a transition WOULD flip — for the confirm copy.
     */
    public function affectedCount(Salon $salon, SalonType $to): int
    {
        $forced = $to->forcedArrangement();

        if ($forced === null) {
            return 0;
        }

        return $salon->memberships()
            ->where('salon_role', SalonRole::Stylist->value)
            ->where('arrangement', '!=', $forced->value)
            ->count();
    }
}
