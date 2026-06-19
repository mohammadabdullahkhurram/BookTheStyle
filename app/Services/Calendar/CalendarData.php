<?php

namespace App\Services\Calendar;

use App\Enums\AvailabilityKind;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Models\User;
use App\Support\PastelPalette;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the per-stylist column ("chair") calendar feed — server-side, salon-
 * scoped and role-filtered — for the day/week grid view.
 *
 * Tenant isolation: every query is scoped to the given salon EXPLICITLY (the
 * salon relationship or forSalon()), never via the active-salon global scope —
 * the calendar refreshes by Livewire polling whose /livewire/update requests do
 * not run ResolveSalon. A single-stylist feed ($onlyStylistId) additionally
 * filters to that one stylist, so another stylist's bookings are never sent.
 *
 * Positions are minutes-from-midnight in the salon's timezone (DST-safe wall
 * clock, same basis the slot engine uses); the view turns minutes into pixels.
 * Block colours come from the rotating pastel families (PastelPalette), keyed
 * by stylist id so a stylist's blocks match their avatar everywhere.
 */
class CalendarData
{
    private const SLOT_MINUTES = 30;

    private const DEFAULT_START_HOUR = 8;

    private const DEFAULT_END_HOUR = 20;

    /**
     * Day view: one column per in-scope stylist.
     *
     * @return array<string, mixed>
     */
    public function day(Salon $salon, CarbonImmutable $day, ?int $onlyStylistId): array
    {
        $tz = $salon->timezone;
        $day = $day->setTimezone($tz)->startOfDay();
        $weekday = $day->dayOfWeekIso - 1;

        $stylists = $this->stylists($salon, $onlyStylistId);
        $bookingsByStylist = $this->bookingsByStylist($salon, $day, $onlyStylistId);

        $columns = [];
        $minStart = null;
        $maxEnd = null;

        foreach ($stylists as $stylist) {
            $work = $this->windows($salon, $stylist->id, $weekday, AvailabilityKind::Work);
            $breaks = $this->windows($salon, $stylist->id, $weekday, AvailabilityKind::Break);
            $timeOff = $this->timeOffIntervals($salon, $stylist->id, $day);
            $bookings = $bookingsByStylist[$stylist->id] ?? [];

            foreach ($work as [$s, $e]) {
                $minStart = $minStart === null ? $s : min($minStart, $s);
                $maxEnd = $maxEnd === null ? $e : max($maxEnd, $e);
            }
            foreach ($bookings as $b) {
                $minStart = $minStart === null ? $b['startMin'] : min($minStart, $b['startMin']);
                $maxEnd = $maxEnd === null ? $b['endMin'] : max($maxEnd, $b['endMin']);
            }

            $columns[] = [
                'stylistId' => $stylist->id,
                'name' => $stylist->name,
                'family' => PastelPalette::forSeed($stylist->id),
                'work' => $work,
                'blocked' => $this->mergeBlocked($breaks, $timeOff),
                'bookings' => $bookings,
            ];
        }

        [$startMin, $endMin] = $this->envelope($minStart, $maxEnd);

        foreach ($columns as &$col) {
            $col['slots'] = $this->slots($day, $startMin, $endMin, $col['work'], $col['blocked']);
        }
        unset($col);

        return $this->frame('day', $tz, $day, $startMin, $endMin, $columns);
    }

    /**
     * Week view: one column per day of the week (Mon–Sun), each aggregating the
     * in-scope stylists' bookings (coloured per stylist). A lighter variant —
     * shading is the salon's union working hours; per-stylist breaks aren't shown.
     *
     * @return array<string, mixed>
     */
    public function week(Salon $salon, CarbonImmutable $anyDay, ?int $onlyStylistId): array
    {
        $tz = $salon->timezone;
        $weekStart = $anyDay->setTimezone($tz)->startOfWeek();

        $stylistIds = $this->stylists($salon, $onlyStylistId)->pluck('id')->all();

        $columns = [];
        $minStart = null;
        $maxEnd = null;
        $perDay = [];

        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->addDays($i);
            $weekday = $day->dayOfWeekIso - 1;
            $unionWork = $this->unionWindows($salon, $stylistIds, $weekday, AvailabilityKind::Work);

            $blocks = [];
            foreach ($this->bookingsByStylist($salon, $day, $onlyStylistId) as $stylistBlocks) {
                foreach ($stylistBlocks as $b) {
                    $blocks[] = $b;
                    $minStart = $minStart === null ? $b['startMin'] : min($minStart, $b['startMin']);
                    $maxEnd = $maxEnd === null ? $b['endMin'] : max($maxEnd, $b['endMin']);
                }
            }
            foreach ($unionWork as [$s, $e]) {
                $minStart = $minStart === null ? $s : min($minStart, $s);
                $maxEnd = $maxEnd === null ? $e : max($maxEnd, $e);
            }

            $perDay[$i] = ['day' => $day, 'work' => $unionWork, 'bookings' => $blocks];
        }

        [$startMin, $endMin] = $this->envelope($minStart, $maxEnd);

        foreach ($perDay as $d) {
            $columns[] = [
                'stylistId' => $onlyStylistId,
                'name' => $d['day']->translatedFormat('D'),
                'sublabel' => $d['day']->translatedFormat('j M'),
                'isToday' => $d['day']->isSameDay(CarbonImmutable::now($tz)),
                'family' => null,
                'work' => $d['work'],
                'blocked' => [],
                'bookings' => $d['bookings'],
                'slots' => $this->slots($d['day'], $startMin, $endMin, $d['work'], []),
            ];
        }

        return $this->frame('week', $tz, $weekStart, $startMin, $endMin, $columns)
            + ['rangeLabel' => $weekStart->translatedFormat('j M').' – '.$weekStart->addDays(6)->translatedFormat('j M Y')];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private function frame(string $view, string $tz, CarbonImmutable $anchor, int $startMin, int $endMin, array $columns): array
    {
        return [
            'view' => $view,
            'timezone' => $tz,
            'date' => $anchor->format('Y-m-d'),
            'dateLabel' => $anchor->translatedFormat('l, j F Y'),
            'dayStartMin' => $startMin,
            'dayEndMin' => $endMin,
            'hourStart' => intdiv($startMin, 60),
            'hourEnd' => intdiv($endMin, 60),
            'hours' => $this->hours($startMin, $endMin),
            'columns' => $columns,
        ];
    }

    /**
     * In-scope active stylists (all, or just one). Ordered by name.
     *
     * @return Collection<int, User>
     */
    private function stylists(Salon $salon, ?int $onlyStylistId)
    {
        return $salon->stylistUsers()
            ->when($onlyStylistId !== null, fn ($q) => $q->where('users.id', $onlyStylistId))
            ->orderBy('name')
            ->get(['users.id', 'name']);
    }

    /**
     * Booking blocks for the day, bucketed by stylist id. One block per service
     * item (a multi-stylist visit appears in each stylist's column).
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function bookingsByStylist(Salon $salon, CarbonImmutable $day, ?int $onlyStylistId): array
    {
        $tz = $salon->timezone;
        $dayStartUtc = $day->utc();
        $dayEndUtc = $day->addDay()->utc();

        $bookings = $salon->bookings()
            ->with(['client:id,name', 'items' => fn ($q) => $q
                ->where('starts_at', '<', $dayEndUtc)
                ->where('ends_at', '>', $dayStartUtc)
                ->when($onlyStylistId !== null, fn ($w) => $w->where('stylist_id', $onlyStylistId))
                ->with('service:id,name,color')])
            ->whereHas('items', fn ($q) => $q
                ->where('starts_at', '<', $dayEndUtc)
                ->where('ends_at', '>', $dayStartUtc)
                ->when($onlyStylistId !== null, fn ($w) => $w->where('stylist_id', $onlyStylistId)))
            ->get();

        $out = [];

        foreach ($bookings as $booking) {
            foreach ($booking->items as $item) {
                $startLocal = $item->starts_at?->setTimezone($tz);
                $endLocal = $item->ends_at?->setTimezone($tz);
                if ($startLocal === null || $endLocal === null) {
                    continue;
                }

                $startMin = $startLocal->isSameDay($day) ? $startLocal->hour * 60 + $startLocal->minute : 0;
                $endMin = $endLocal->isSameDay($day) ? $endLocal->hour * 60 + $endLocal->minute : 1440;
                if ($endMin <= $startMin) {
                    $endMin = min(1440, $startMin + 15);
                }

                $out[$item->stylist_id][] = [
                    'bookingId' => $booking->id,
                    'startMin' => $startMin,
                    'endMin' => $endMin,
                    'startLabel' => $startLocal->format('g:i'),
                    'endLabel' => $endLocal->format('g:i A'),
                    'client' => $booking->client->name,
                    'service' => $item->service->name,
                    'status' => $booking->status->value,
                    'statusLabel' => $booking->status->label(),
                    'isWalkin' => $booking->is_walkin,
                    'family' => PastelPalette::forSeed($item->stylist_id),
                ];
            }
        }

        return $out;
    }

    /**
     * Weekly availability windows (minutes-from-midnight) for a stylist + kind.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function windows(Salon $salon, int $stylistId, int $weekday, AvailabilityKind $kind): array
    {
        return array_values(
            Availability::forSalon($salon)
                ->where('user_id', $stylistId)
                ->where('weekday', $weekday)
                ->where('kind', $kind->value)
                ->orderBy('start_minute')
                ->get(['start_minute', 'end_minute'])
                ->map(fn (Availability $a): array => [(int) $a->start_minute, (int) $a->end_minute])
                ->all()
        );
    }

    /**
     * Merged (overlap-collapsed) work windows across several stylists.
     *
     * @param  array<int, int>  $stylistIds
     * @return list<array{0: int, 1: int}>
     */
    private function unionWindows(Salon $salon, array $stylistIds, int $weekday, AvailabilityKind $kind): array
    {
        if ($stylistIds === []) {
            return [];
        }

        $intervals = Availability::forSalon($salon)
            ->whereIn('user_id', $stylistIds)
            ->where('weekday', $weekday)
            ->where('kind', $kind->value)
            ->orderBy('start_minute')
            ->get(['start_minute', 'end_minute'])
            ->map(fn (Availability $a): array => [(int) $a->start_minute, (int) $a->end_minute])
            ->all();

        $merged = [];
        foreach ($intervals as [$s, $e]) {
            if ($merged !== [] && $s <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $e);
            } else {
                $merged[] = [$s, $e];
            }
        }

        return $merged;
    }

    /**
     * One-off time off overlapping the day, clamped to [0, 1440] local minutes.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function timeOffIntervals(Salon $salon, int $stylistId, CarbonImmutable $day): array
    {
        $tz = $salon->timezone;

        return array_values(
            TimeOff::forSalon($salon)
                ->where('user_id', $stylistId)
                ->where('starts_at', '<', $day->addDay()->utc())
                ->where('ends_at', '>', $day->utc())
                ->get(['starts_at', 'ends_at'])
                ->map(function (TimeOff $t) use ($tz, $day): array {
                    $s = $t->starts_at?->setTimezone($tz);
                    $e = $t->ends_at?->setTimezone($tz);
                    $startMin = ($s !== null && $s->isSameDay($day)) ? $s->hour * 60 + $s->minute : 0;
                    $endMin = ($e !== null && $e->isSameDay($day)) ? $e->hour * 60 + $e->minute : 1440;

                    return [$startMin, max($startMin + 1, $endMin)];
                })
                ->all()
        );
    }

    /**
     * @param  list<array{0: int, 1: int}>  $breaks
     * @param  list<array{0: int, 1: int}>  $timeOff
     * @return list<array{startMin: int, endMin: int, label: string}>
     */
    private function mergeBlocked(array $breaks, array $timeOff): array
    {
        $blocked = [];
        foreach ($breaks as [$s, $e]) {
            $blocked[] = ['startMin' => $s, 'endMin' => $e, 'label' => __('Break')];
        }
        foreach ($timeOff as [$s, $e]) {
            $blocked[] = ['startMin' => $s, 'endMin' => $e, 'label' => __('Time off')];
        }

        return $blocked;
    }

    /**
     * Clickable 30-minute slots across the envelope; bookable = inside a work
     * window and clear of breaks/time off (the slot engine re-validates on save).
     *
     * @param  list<array{0: int, 1: int}>  $work
     * @param  list<array{startMin: int, endMin: int, label: string}>  $blocked
     * @return list<array{min: int, iso: string, bookable: bool}>
     */
    private function slots(CarbonImmutable $day, int $startMin, int $endMin, array $work, array $blocked): array
    {
        $slots = [];

        for ($m = $startMin; $m + self::SLOT_MINUTES <= $endMin; $m += self::SLOT_MINUTES) {
            $bookable = $this->withinAny($work, $m, $m + self::SLOT_MINUTES)
                && ! $this->overlapsBlocked($blocked, $m, $m + self::SLOT_MINUTES);

            $slots[] = [
                'min' => $m,
                'iso' => $day->setTime(intdiv($m, 60), $m % 60)->utc()->toIso8601ZuluString(),
                'bookable' => $bookable,
            ];
        }

        return $slots;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     */
    private function withinAny(array $intervals, int $a, int $b): bool
    {
        foreach ($intervals as [$s, $e]) {
            if ($s <= $a && $b <= $e) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{startMin: int, endMin: int, label: string}>  $blocked
     */
    private function overlapsBlocked(array $blocked, int $a, int $b): bool
    {
        foreach ($blocked as $block) {
            if ($block['startMin'] < $b && $a < $block['endMin']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole-hour envelope around the content, falling back to 8–20.
     *
     * @return array{0: int, 1: int}
     */
    private function envelope(?int $minStart, ?int $maxEnd): array
    {
        if ($minStart === null || $maxEnd === null || $maxEnd <= $minStart) {
            return [self::DEFAULT_START_HOUR * 60, self::DEFAULT_END_HOUR * 60];
        }

        $start = max(0, (intdiv($minStart, 60)) * 60);
        $end = min(1440, (int) ceil($maxEnd / 60) * 60);

        return [$start, max($start + 60, $end)];
    }

    /**
     * @return list<array{min: int, label: string}>
     */
    private function hours(int $startMin, int $endMin): array
    {
        $hours = [];
        for ($h = intdiv($startMin, 60); $h <= intdiv($endMin, 60); $h++) {
            $suffix = $h < 12 ? 'am' : 'pm';
            $display = $h % 12 === 0 ? 12 : $h % 12;
            $hours[] = ['min' => $h * 60, 'label' => $display.' '.$suffix];
        }

        return $hours;
    }
}
