<?php

namespace App\Services\Ghl;

use App\Enums\BookingStatus;

/**
 * THE status mapping between app bookings and GHL appointments, both
 * directions in one place.
 *
 * App → GHL (outbound push):
 *   cancelled → cancelled · no_show → noshow · completed → showed ·
 *   everything else (booked/confirmed/arrived/in_service) → confirmed
 *
 * GHL → app (inbound webhook):
 *   cancelled/invalid → cancelled · noshow → no_show · showed → completed ·
 *   confirmed → confirmed · new → booked
 *
 * The app's richer in-salon lifecycle (arrived, in_service) has no GHL
 * counterpart — those states never leave the app and an inbound "confirmed"
 * for a booking already arrived/in service is NOT a downgrade (handled by
 * the inbound sync, which only applies real changes).
 */
class GhlStatusMap
{
    public static function toGhl(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Cancelled => 'cancelled',
            BookingStatus::NoShow => 'noshow',
            BookingStatus::Completed => 'showed',
            default => 'confirmed',
        };
    }

    public static function toApp(string $ghlStatus): ?BookingStatus
    {
        return match (mb_strtolower(trim($ghlStatus))) {
            'cancelled' => BookingStatus::Cancelled,
            'invalid' => BookingStatus::Cancelled,
            'noshow' => BookingStatus::NoShow,
            'showed' => BookingStatus::Completed,
            'confirmed' => BookingStatus::Confirmed,
            'new' => BookingStatus::Booked,
            // calendar.status fallback vocabulary (the live field is the
            // misspelled appoinmentStatus; see GhlWebhookPayload).
            'booked' => BookingStatus::Booked,
            default => null,
        };
    }
}
