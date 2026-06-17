<?php

namespace App\Services\Calendar;

use App\Enums\AvailabilityKind;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the calendar feed (stylist "calendars", booking events, blocked
 * time, and the working-hours envelope) for a date range, in the salon's
 * timezone. Output is a plain array ready to JSON-encode for Toast UI Calendar.
 *
 * Tenant isolation: every query is scoped to the given salon EXPLICITLY (the
 * salon relationship or forSalon()), never via the active-salon global scope —
 * the calendar is fed by Livewire polling whose /livewire/update requests do
 * not run ResolveSalon, so `currentSalon` is not bound there. A stylist-scoped
 * feed additionally filters to that one stylist, so another stylist's bookings
 * are never serialised to the browser.
 *
 * Times: bookings/time-off are absolute UTC instants, emitted as ISO-8601 "Z"
 * strings; Toast UI renders them in the configured salon timezone. Weekly work
 * windows / breaks (minutes-from-midnight, salon-local) are expanded per day.
 */
class CalendarData
{
    /**
     * Restrained, earthy per-stylist palette (design tokens — never neon).
     *
     * @var list<string>
     */
    public const PALETTE = [
        '#1F6F6B', // accent teal
        '#B7791F', // amber
        '#2B6CB0', // info blue
        '#7C5CBF', // muted violet
        '#2F855A', // success green
        '#B23A2E', // clay red
        '#4B7F6F', // sage
        '#9C6B3F', // terracotta
    ];

    private const BLOCK_COLOR = '#6B6660'; // secondary ink for blocked time

    /**
     * @return array{
     *     timezone: string,
     *     hourStart: int,
     *     hourEnd: int,
     *     calendars: list<array{id: string, name: string, color: string}>,
     *     events: list<array<string, mixed>>,
     *     blocks: list<array<string, mixed>>,
     * }
     */
    public function build(Salon $salon, CarbonImmutable $from, CarbonImmutable $to, ?int $onlyStylistId): array
    {
        $tz = $salon->timezone;

        $stylists = $this->stylists($salon, $onlyStylistId);
        $calendars = [];
        $colorById = [];
        $i = 0;
        foreach ($stylists as $stylist) {
            $color = self::PALETTE[$i % count(self::PALETTE)];
            $colorById[$stylist->id] = $color;
            $calendars[] = ['id' => (string) $stylist->id, 'name' => $stylist->name, 'color' => $color];
            $i++;
        }

        return [
            'timezone' => $tz,
            'calendars' => $calendars,
            'events' => $this->events($salon, $from, $to, $onlyStylistId, $colorById),
            // Per-stylist breaks only make sense on a single-stylist calendar;
            // time off is shown in both (an "away" marker).
            'blocks' => $this->blocks($salon, $from, $to, $onlyStylistId),
            ...$this->workingHours($salon, $from, $to, $onlyStylistId),
        ];
    }

    /**
     * Active stylists in scope (all of them, or just the one for a per-stylist
     * feed). Ordered by name for stable colour assignment.
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
     * Booking items overlapping [$from, $to), as Toast UI time events. Each
     * service item is its own event (unique id = item id) coloured by stylist;
     * the booking id travels in `raw` for the click-to-open detail panel.
     *
     * @param  array<int, string>  $colorById
     * @return list<array<string, mixed>>
     */
    private function events(Salon $salon, CarbonImmutable $from, CarbonImmutable $to, ?int $onlyStylistId, array $colorById): array
    {
        $bookings = $salon->bookings()
            ->with(['client:id,name', 'items' => fn ($q) => $q
                ->where('starts_at', '<', $to->utc())
                ->where('ends_at', '>', $from->utc())
                ->when($onlyStylistId !== null, fn ($w) => $w->where('stylist_id', $onlyStylistId))
                ->with(['service:id,name,color', 'stylist:id,name'])])
            ->whereHas('items', fn ($q) => $q
                ->where('starts_at', '<', $to->utc())
                ->where('ends_at', '>', $from->utc())
                ->when($onlyStylistId !== null, fn ($w) => $w->where('stylist_id', $onlyStylistId)))
            ->get();

        $events = [];

        foreach ($bookings as $booking) {
            foreach ($booking->items as $item) {
                $color = $colorById[$item->stylist_id] ?? self::PALETTE[0];
                $cancelledLook = in_array($booking->status->value, ['cancelled', 'no_show'], true);

                $events[] = [
                    'id' => (string) $item->id,
                    'calendarId' => (string) $item->stylist_id,
                    'title' => $booking->client->name,
                    'body' => $item->service->name,
                    'start' => $item->starts_at?->toIso8601ZuluString(),
                    'end' => $item->ends_at?->toIso8601ZuluString(),
                    'category' => 'time',
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'color' => '#FFFFFF',
                    'customStyle' => $cancelledLook
                        ? ['opacity' => '0.5', 'textDecoration' => 'line-through']
                        : [],
                    'raw' => [
                        'type' => 'booking',
                        'bookingId' => $booking->id,
                        'status' => $booking->status->value,
                        'statusLabel' => $booking->status->label(),
                        'stylist' => $item->stylist->name,
                        'service' => $item->service->name,
                        'isWalkin' => $booking->is_walkin,
                    ],
                ];
            }
        }

        return $events;
    }

    /**
     * Blocked time as read-only grey events: one-off time off (both feeds) and,
     * on a per-stylist feed, weekly breaks expanded across the range.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(Salon $salon, CarbonImmutable $from, CarbonImmutable $to, ?int $onlyStylistId): array
    {
        $blocks = [];

        $timeOff = TimeOff::forSalon($salon)
            ->with('user:id,name')
            ->when($onlyStylistId !== null, fn ($q) => $q->where('user_id', $onlyStylistId))
            ->where('starts_at', '<', $to->utc())
            ->where('ends_at', '>', $from->utc())
            ->get();

        foreach ($timeOff as $off) {
            $blocks[] = $this->blockEvent(
                'off-'.$off->id,
                $onlyStylistId !== null ? __('Time off') : $off->user->name.' · '.__('Time off'),
                $off->starts_at?->toIso8601ZuluString(),
                $off->ends_at?->toIso8601ZuluString(),
            );
        }

        // Breaks are weekly recurring (minutes-from-midnight, salon-local); only
        // surfaced on the single-stylist calendar to keep the master view clean.
        if ($onlyStylistId !== null) {
            $breaks = Availability::forSalon($salon)
                ->where('user_id', $onlyStylistId)
                ->where('kind', AvailabilityKind::Break->value)
                ->get(['weekday', 'start_minute', 'end_minute']);

            $tz = $salon->timezone;
            $cursor = $from->setTimezone($tz)->startOfDay();
            $last = $to->setTimezone($tz)->startOfDay();

            for ($day = $cursor; $day->lte($last); $day = $day->addDay()) {
                $weekday = $day->dayOfWeekIso - 1;
                foreach ($breaks->where('weekday', $weekday) as $break) {
                    $blocks[] = $this->blockEvent(
                        'break-'.$day->format('Ymd').'-'.$break->start_minute,
                        __('Break'),
                        $this->minuteInstant($day, $break->start_minute)->utc()->toIso8601ZuluString(),
                        $this->minuteInstant($day, $break->end_minute)->utc()->toIso8601ZuluString(),
                    );
                }
            }
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function blockEvent(string $id, string $title, ?string $start, ?string $end): array
    {
        return [
            'id' => $id,
            'calendarId' => 'blocked',
            'title' => $title,
            'start' => $start,
            'end' => $end,
            'category' => 'time',
            'isReadOnly' => true,
            'backgroundColor' => 'rgba(107, 102, 96, 0.14)',
            'borderColor' => 'rgba(107, 102, 96, 0.28)',
            'color' => self::BLOCK_COLOR,
            'raw' => ['type' => 'block'],
        ];
    }

    /**
     * The working-hours envelope (whole hours) across the in-scope stylists for
     * the weekdays present in the range — frames the grid so non-working time is
     * minimised. Falls back to 8–20 when no work windows exist.
     *
     * @return array{hourStart: int, hourEnd: int}
     */
    private function workingHours(Salon $salon, CarbonImmutable $from, CarbonImmutable $to, ?int $onlyStylistId): array
    {
        $stylistIds = $this->stylists($salon, $onlyStylistId)->pluck('id')->all();

        if ($stylistIds === []) {
            return ['hourStart' => 8, 'hourEnd' => 20];
        }

        $tz = $salon->timezone;
        $weekdays = [];
        for ($day = $from->setTimezone($tz)->startOfDay(); $day->lte($to->setTimezone($tz)); $day = $day->addDay()) {
            $weekdays[$day->dayOfWeekIso - 1] = true;
        }

        $windows = Availability::forSalon($salon)
            ->whereIn('user_id', $stylistIds)
            ->where('kind', AvailabilityKind::Work->value)
            ->whereIn('weekday', array_keys($weekdays))
            ->get(['start_minute', 'end_minute']);

        if ($windows->isEmpty()) {
            return ['hourStart' => 8, 'hourEnd' => 20];
        }

        $minStart = (int) $windows->min('start_minute');
        $maxEnd = (int) $windows->max('end_minute');

        return [
            'hourStart' => max(0, intdiv($minStart, 60)),
            'hourEnd' => min(24, (int) ceil($maxEnd / 60)),
        ];
    }

    private function minuteInstant(CarbonImmutable $day, int $minutes): CarbonImmutable
    {
        if ($minutes >= 1440) {
            return $day->addDay()->startOfDay();
        }

        return $day->setTime(intdiv($minutes, 60), $minutes % 60, 0);
    }
}
