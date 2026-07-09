<?php

namespace App\Services\Ghl;

use App\Enums\BookingStatus;

/**
 * THE status mapping between app bookings and GHL appointments, both
 * directions in one place.
 *
 * App → GHL (outbound push):
 *   booked/confirmed(legacy) → confirmed (every new booking lands in GHL
 *   auto-confirmed) · arrived(checked in)/in_service(legacy)/completed →
 *   showed · no_show → noshow · cancelled → cancelled
 *
 * GHL → app (inbound webhook):
 *   confirmed/new/unconfirmed/booked → booked (active) · showed → checked
 *   in (arrived) · noshow → no_show · cancelled/invalid → cancelled
 */
class GhlStatusMap
{
    public static function toGhl(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Cancelled => 'cancelled',
            BookingStatus::NoShow => 'noshow',
            BookingStatus::Arrived => 'showed',
            BookingStatus::InService => 'showed',
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
            'showed' => BookingStatus::Arrived,
            'confirmed' => BookingStatus::Booked,
            'new' => BookingStatus::Booked,
            'unconfirmed' => BookingStatus::Booked,
            // calendar.status fallback vocabulary (the live field is the
            // misspelled appoinmentStatus; see GhlWebhookPayload).
            'booked' => BookingStatus::Booked,
            default => null,
        };
    }
}
