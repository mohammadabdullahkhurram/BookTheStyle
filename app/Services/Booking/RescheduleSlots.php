<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Salon;
use Carbon\CarbonImmutable;

/**
 * Start times where a booking's ENTIRE visit fits on a date — every service
 * item, in order, keeping the visit's internal spacing (each item shifts by
 * the same delta, exactly how RescheduleBooking later commits the move).
 * Candidate starts come from the slot engine for the first item; each is then
 * kept only if every remaining item's shifted block is also available for its
 * own stylist. The booking's current slots are ignored as conflicts so
 * nearby times stay offered.
 */
class RescheduleSlots
{
    public function __construct(private SlotEngine $engine) {}

    /**
     * @param  string  $date  'Y-m-d' in the salon timezone
     * @return list<CarbonImmutable> bookable visit start instants, ascending
     */
    public function startTimes(Salon $salon, Booking $booking, string $date): array
    {
        $items = $booking->items->sortBy('starts_at')->values();
        $first = $items->first();

        if ($first === null) {
            return [];
        }

        $anchor = $first->starts_at;
        $candidates = $this->engine->slotsFor(
            $salon,
            (int) $first->stylist_id,
            $this->blockedMinutes($first),
            $date,
            $booking->id,
        );

        $rest = $items->slice(1);

        if ($rest->isEmpty()) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            function (CarbonImmutable $start) use ($salon, $booking, $rest, $anchor): bool {
                foreach ($rest as $item) {
                    $offsetSeconds = (int) round($anchor->diffInSeconds($item->starts_at));

                    $fits = $this->engine->isAvailable(
                        $salon,
                        (int) $item->stylist_id,
                        $start->addSeconds($offsetSeconds),
                        $this->blockedMinutes($item),
                        $booking->id,
                    );

                    if (! $fits) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /** Service minutes plus the stored cleanup buffer — what the item blocks. */
    private function blockedMinutes(BookingItem $item): int
    {
        return (int) round($item->starts_at->diffInMinutes($item->ends_at)) + (int) $item->buffer_min;
    }
}
