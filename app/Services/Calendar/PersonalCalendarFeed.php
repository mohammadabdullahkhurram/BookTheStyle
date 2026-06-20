<?php

namespace App\Services\Calendar;

use App\Enums\BookingStatus;
use App\Models\BookingItem;
use App\Models\User;
use App\Support\Ics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds a user's personal ICS feed (Phase 5): one VEVENT per booking in which
 * the user is the assigned stylist, across every salon they work in. One-way
 * only (app → their calendar), salon-named per event, scoped strictly to the
 * user's own items — no other user's or salon's data can appear.
 *
 * The window is bounded (~1 week back to ~6 months ahead) to keep feeds small.
 */
class PersonalCalendarFeed
{
    private const WINDOW_BACK_DAYS = 7;

    private const WINDOW_AHEAD_DAYS = 183; // ~6 months

    public function toIcs(User $user): string
    {
        $now = CarbonImmutable::now('UTC');
        $start = $now->subDays(self::WINDOW_BACK_DAYS);
        $end = $now->addDays(self::WINDOW_AHEAD_DAYS);

        // Only this user's own items (as assigned stylist). No salon scope is
        // active on the /cal route, so this stylist_id filter is the boundary.
        $items = BookingItem::query()
            ->where('stylist_id', $user->id)
            ->whereBetween('starts_at', [$start->toDateTimeString(), $end->toDateTimeString()])
            ->with(['booking.client', 'booking.salon', 'service'])
            ->orderBy('starts_at')
            ->get();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BookTheStyle//Calendar Feed//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.Ics::escape('BookTheStyle — '.$user->name),
        ];

        foreach ($items->groupBy('booking_id') as $group) {
            foreach ($this->event($group, $now) as $line) {
                $lines[] = $line;
            }
        }

        $lines[] = 'END:VCALENDAR';

        return Ics::join($lines);
    }

    /**
     * One VEVENT for the user's items within a single booking.
     *
     * @param  Collection<int, BookingItem>  $items
     * @return list<string>
     */
    private function event(Collection $items, CarbonImmutable $now): array
    {
        $first = $items->first();

        if ($first === null) {
            return [];
        }

        $booking = $first->booking;
        $salon = $booking->salon;

        $starts = $items->map(fn (BookingItem $i): ?CarbonImmutable => $i->starts_at)->filter()->sort()->values();
        $ends = $items->map(fn (BookingItem $i): ?CarbonImmutable => $i->ends_at)->filter()->sort()->values();
        $start = $starts->first();
        $end = $ends->last();

        if ($start === null || $end === null) {
            return [];
        }

        $services = $items->sortBy(fn (BookingItem $i): ?CarbonImmutable => $i->starts_at)
            ->map(fn (BookingItem $i): string => $i->service->name)
            ->unique()
            ->values()
            ->implode(', ');

        $clientName = $booking->client->name;
        $summary = $clientName.' — '.$services;
        $description = "Services: {$services}\nStatus: {$booking->status->label()}\nBooked by: {$booking->booked_by_type->label()}";

        $modified = $booking->updated_at ?? $now;
        $cancelled = $booking->status === BookingStatus::Cancelled;

        return [
            'BEGIN:VEVENT',
            // Stable per booking → edits/cancellations update the same event.
            'UID:bts-booking-'.$booking->id.'@'.config('app.domain'),
            'SEQUENCE:'.$modified->getTimestamp(),
            'DTSTAMP:'.Ics::dt($now),
            'DTSTART:'.Ics::dt($start),
            'DTEND:'.Ics::dt($end),
            'SUMMARY:'.Ics::escape($summary),
            'LOCATION:'.Ics::escape($salon->name),
            'DESCRIPTION:'.Ics::escape($description),
            'STATUS:'.($cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'LAST-MODIFIED:'.Ics::dt($modified),
            'END:VEVENT',
        ];
    }
}
