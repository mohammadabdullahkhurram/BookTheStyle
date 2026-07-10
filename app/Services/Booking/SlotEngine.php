<?php

namespace App\Services\Booking;

use App\Enums\AvailabilityKind;
use App\Enums\BookingStatus;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pure slot-generation engine. Given a stylist, a duration, and a date (in the
 * salon's timezone), it returns the bookable start instants by combining the
 * stylist's weekly work windows, minus breaks, minus one-off time off, minus
 * their existing (non-cancelled) bookings, constrained by the salon's booking
 * policy. The full *blocked* duration must fit in one continuous free stretch
 * inside a work window. Default granularity is 15 minutes, anchored at each
 * window's start. No state — it reads availability/time-off/bookings each call.
 *
 * "Blocked" minutes = service + cleanup buffer (resolved per stylist by
 * DurationResolver). The engine is duration-agnostic: callers pass resolved
 * blocked minutes, and existing bookings already occupy their own service +
 * stored buffer (bookingIntervals extends each item by booking_items.buffer_min).
 *
 * Internally it works with absolute instants (CarbonImmutable). Work-window
 * minutes-from-midnight are turned into wall-clock instants via setTime (so the
 * result is correct across DST transitions); time off and existing bookings are
 * already absolute. An interval represents [start, end).
 */
class SlotEngine
{
    public function __construct(
        private BookingPolicy $policy,
        private int $granularityMinutes = 15,
    ) {}

    /**
     * @return list<CarbonImmutable> bookable start instants, ascending
     */
    public function slotsFor(Salon $salon, int $stylistUserId, int $blockedMinutes, CarbonImmutable|Carbon|string $date, ?int $ignoreBookingId = null): array
    {
        $day = $this->resolveDay($salon, $date);
        $weekday = $day->dayOfWeekIso - 1;

        // Date-specific HOURS entries replace the weekly schedule (work
        // windows AND breaks) for that date — the day's own schedule.
        $overrides = $this->dateHoursOverrides($salon, $stylistUserId, $day);

        $work = $overrides !== []
            ? $overrides
            : $this->windows($salon, $stylistUserId, $weekday, AvailabilityKind::Work, $day);
        if ($work === []) {
            return [];
        }

        $busy = array_merge(
            $overrides === [] ? $this->windows($salon, $stylistUserId, $weekday, AvailabilityKind::Break, $day) : [],
            $this->timeOffIntervals($salon, $stylistUserId, $day),
            $this->bookingIntervals($salon, $stylistUserId, $day, $ignoreBookingId),
        );

        $slots = [];

        foreach ($work as [$windowStart, $windowEnd]) {
            $free = $this->subtract([[$windowStart, $windowEnd]], $busy);

            for (
                $start = $windowStart;
                $start->addMinutes($blockedMinutes)->lte($windowEnd);
                $start = $start->addMinutes($this->granularityMinutes)
            ) {
                $end = $start->addMinutes($blockedMinutes);

                if (! $this->fitsInFree($start, $end, $free)) {
                    continue;
                }

                if (! $this->policy->slotIsOfferable($salon, $start)) {
                    continue;
                }

                $slots[$start->getTimestamp()] = $start;
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * Whether a specific block [start, start+duration) is structurally bookable
     * for the stylist: fully inside one work window, clear of breaks, time off,
     * and existing bookings. Ignores temporal policy (checked separately).
     */
    public function isAvailable(Salon $salon, int $stylistUserId, CarbonImmutable $start, int $blockedMinutes, ?int $ignoreBookingId = null): bool
    {
        $start = $start->setTimezone($salon->timezone);
        $end = $start->addMinutes($blockedMinutes);
        $day = $start->startOfDay();
        $weekday = $day->dayOfWeekIso - 1;

        $overrides = $this->dateHoursOverrides($salon, $stylistUserId, $day);

        $work = $overrides !== []
            ? $overrides
            : $this->windows($salon, $stylistUserId, $weekday, AvailabilityKind::Work, $day);

        $insideWork = false;
        foreach ($work as [$ws, $we]) {
            if ($start->gte($ws) && $end->lte($we)) {
                $insideWork = true;
                break;
            }
        }

        if (! $insideWork) {
            return false;
        }

        $busy = array_merge(
            $overrides === [] ? $this->windows($salon, $stylistUserId, $weekday, AvailabilityKind::Break, $day) : [],
            $this->timeOffIntervals($salon, $stylistUserId, $day),
            $this->bookingIntervals($salon, $stylistUserId, $day, $ignoreBookingId),
        );

        foreach ($busy as [$bs, $be]) {
            if ($start->lt($be) && $bs->lt($end)) {
                return false;
            }
        }

        return true;
    }

    private function resolveDay(Salon $salon, CarbonImmutable|Carbon|string $date): CarbonImmutable
    {
        $tz = $salon->timezone;

        $instant = is_string($date)
            ? CarbonImmutable::parse($date, $tz)
            : CarbonImmutable::parse($date)->setTimezone($tz);

        return $instant->startOfDay();
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windows(Salon $salon, int $stylistUserId, int $weekday, AvailabilityKind $kind, CarbonImmutable $day): array
    {
        return array_values(
            Availability::forSalon($salon)
                ->where('user_id', $stylistUserId)
                ->where('weekday', $weekday)
                ->where('kind', $kind->value)
                ->orderBy('start_minute')
                ->get(['start_minute', 'end_minute'])
                ->map(fn (Availability $a): array => [
                    $this->minuteToInstant($day, $a->start_minute),
                    $this->minuteToInstant($day, $a->end_minute),
                ])
                ->all()
        );
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function timeOffIntervals(Salon $salon, int $stylistUserId, CarbonImmutable $day): array
    {
        $dayStart = $day;
        $dayEnd = $day->addDay();

        return array_values(
            TimeOff::forSalon($salon)
                ->where('user_id', $stylistUserId)
                ->where('kind', TimeOff::KIND_OFF)
                ->where('starts_at', '<', $dayEnd)
                ->where('ends_at', '>', $dayStart)
                ->get(['starts_at', 'ends_at'])
                ->map(fn (TimeOff $t): array => [
                    CarbonImmutable::parse($t->starts_at),
                    CarbonImmutable::parse($t->ends_at),
                ])
                ->all()
        );
    }

    /**
     * Date-specific HOURS entries for this day (clamped to it): when any
     * exist they ARE the stylist's schedule for the date, replacing the
     * weekly windows — including on a weekly day off.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function dateHoursOverrides(Salon $salon, int $stylistUserId, CarbonImmutable $day): array
    {
        $dayStart = $day;
        $dayEnd = $day->addDay();

        return array_values(
            TimeOff::forSalon($salon)
                ->where('user_id', $stylistUserId)
                ->where('kind', TimeOff::KIND_HOURS)
                ->where('starts_at', '<', $dayEnd)
                ->where('ends_at', '>', $dayStart)
                ->orderBy('starts_at')
                ->get(['starts_at', 'ends_at'])
                ->map(fn (TimeOff $t): array => [
                    CarbonImmutable::parse($t->starts_at)->max($dayStart),
                    CarbonImmutable::parse($t->ends_at)->min($dayEnd),
                ])
                ->filter(fn (array $i): bool => $i[0]->lt($i[1]))
                ->values()
                ->all()
        );
    }

    /**
     * Existing non-cancelled booking blocks for the stylist on this day. Each
     * block occupies its service time PLUS its stored cleanup buffer
     * (booking_items.buffer_min), so the next appointment can't start during the
     * buffer. Uses a raw join so it is independent of the active-salon scope.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function bookingIntervals(Salon $salon, int $stylistUserId, CarbonImmutable $day, ?int $ignoreBookingId = null): array
    {
        $rows = DB::table('booking_items')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->where('booking_items.salon_id', $salon->id)
            ->where('booking_items.stylist_id', $stylistUserId)
            // A reschedule must not collide with the booking being moved.
            ->when($ignoreBookingId !== null, fn ($q) => $q->where('booking_items.booking_id', '!=', $ignoreBookingId))
            ->where('bookings.status', '!=', BookingStatus::Cancelled->value)
            ->where('booking_items.starts_at', '<', $day->addDay()->utc())
            // The buffer can run past ends_at, so widen the day overlap window
            // by it too (a booking whose service ends before the day but whose
            // buffer reaches into it still blocks).
            ->where('booking_items.ends_at', '>', $day->utc())
            ->get(['booking_items.starts_at as s', 'booking_items.ends_at as e', 'booking_items.buffer_min as b']);

        return array_values(
            $rows
                ->map(fn ($r): array => [
                    CarbonImmutable::parse($r->s, 'UTC'),
                    CarbonImmutable::parse($r->e, 'UTC')->addMinutes((int) $r->b),
                ])
                ->all()
        );
    }

    private function minuteToInstant(CarbonImmutable $day, int $minutes): CarbonImmutable
    {
        if ($minutes >= 1440) {
            return $day->addDay()->startOfDay();
        }

        return $day->setTime(intdiv($minutes, 60), $minutes % 60, 0);
    }

    /**
     * Subtract the busy intervals from the base intervals, returning the free
     * sub-intervals (zero-length removed).
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $base
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function subtract(array $base, array $busy): array
    {
        $result = $base;

        foreach ($busy as [$bStart, $bEnd]) {
            $next = [];

            foreach ($result as [$iStart, $iEnd]) {
                // No overlap.
                if ($iEnd->lte($bStart) || $iStart->gte($bEnd)) {
                    $next[] = [$iStart, $iEnd];

                    continue;
                }

                if ($iStart->lt($bStart)) {
                    $next[] = [$iStart, $bStart];
                }

                if ($iEnd->gt($bEnd)) {
                    $next[] = [$bEnd, $iEnd];
                }
            }

            $result = $next;
        }

        return array_values(array_filter($result, fn (array $i): bool => $i[0]->lt($i[1])));
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $free
     */
    private function fitsInFree(CarbonImmutable $start, CarbonImmutable $end, array $free): bool
    {
        foreach ($free as [$fStart, $fEnd]) {
            if ($start->gte($fStart) && $end->lte($fEnd)) {
                return true;
            }
        }

        return false;
    }
}
