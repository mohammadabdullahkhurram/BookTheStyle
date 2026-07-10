<?php

namespace App\Services\Ghl;

use App\Enums\AvailabilityKind;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\StylistProfile;
use App\Models\TimeOff;
use App\Services\Booking\DurationResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mirror one stylist's availability INTO GoHighLevel, so GHL's own booking
 * surfaces (voice AI, chat widget, calendar pages) only offer times the app
 * would allow. The app stays the source of truth and the strongest
 * enforcement; this is a faithful-as-possible one-way mirror.
 *
 * Representation: each mapped stylist gets ONE GHL "user availability
 * schedule" (POST/PUT /calendars/schedules — rules + IANA timezone, per
 * user) applied to the salon's master calendar:
 *
 * - Weekly hours → wday rules: per weekday, the stylist's WORK windows minus
 *   their BREAK windows, as HH:MM wall-clock intervals in the salon's
 *   timezone. Wall-clock intervals are inherently DST-safe — "09:00-17:00"
 *   means local time on every date, exactly like the app's minutes-from-
 *   midnight windows. Splits (e.g. a lunch gap) map 1:1 as multiple
 *   intervals.
 * - Time off → date rules (date-specific overrides beat weekly rules in
 *   GHL): for every future date touched by a time-off block (up to
 *   TIME_OFF_HORIZON_DAYS out), the rule carries what REMAINS of that day's
 *   weekly hours after subtracting the time off — empty intervals for a full
 *   day off.
 *
 * CONSERVATIVE MAPPING (never over-offer): anything GHL can't represent
 * exactly is rounded toward LESS availability — time-off edges widen to
 * whole minutes, a window ending at 24:00 becomes 23:59, and per-service /
 * per-stylist durations (below) block for the LONGEST service. GHL may offer
 * fewer times than the app; it must never offer more.
 *
 * Slot settings (PUT /calendars/{id}) are calendar-level in GHL, so the
 * app's per-stylist-per-service granularity cannot map 1:1. The master
 * calendar gets: slotDuration = the LONGEST active service duration (incl.
 * per-stylist overrides), slotInterval = the app engine's 15-minute
 * granularity, slotBuffer (post-appointment) = the longest cleanup buffer
 * when the salon's stylist_buffers flag is on (0 when off — buffers are
 * dormant until a salon opts in). preBuffer is left untouched: the app has
 * no pre-buffer, and whatever GHL holds can only under-offer.
 *
 * Idempotent: the rules payload is hashed on the stylist profile; an
 * unchanged re-sync makes no API call, a changed one UPDATES the stored
 * schedule (never duplicates), and a schedule deleted inside GHL is
 * recreated transparently. Sync state (status/error/last success) lives on
 * stylist_profiles and is surfaced in Settings → Integrations.
 */
class GhlAvailabilityPusher
{
    public const STATUS_SYNCED = 'synced';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    /** How far ahead time off is materialised as date rules. */
    public const TIME_OFF_HORIZON_DAYS = 365;

    /** The app engine offers slots on a 15-minute grid. */
    public const SLOT_INTERVAL_MINUTES = 15;

    private const DAY_NAMES = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @throws GhlApiException on API failure (the queued job retries, then
     *                         records the failure on the stylist profile)
     */
    public function push(StylistProfile $profile): void
    {
        $salon = $profile->salon;
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->isConnected() || blank($connection->calendar_id)) {
            $this->mark($profile, self::STATUS_SKIPPED, __('GoHighLevel is not connected (or no master calendar is chosen).'));

            return;
        }

        if (blank($profile->ghl_user_id)) {
            $this->mark($profile, self::STATUS_SKIPPED, __('The stylist is not mapped to a GoHighLevel calendar provider.'));

            return;
        }

        $schedule = [
            'name' => 'BookTheStyle availability — '.$profile->user->name,
            'timezone' => $salon->timezone,
            'rules' => self::rulesFor($salon, $profile->user_id),
        ];

        $hash = hash('sha256', (string) json_encode([$schedule, $profile->ghl_user_id, (string) $connection->calendar_id]));

        if ($profile->ghl_schedule_id !== null && $profile->ghl_availability_hash === $hash
            && in_array($profile->ghl_availability_status, [self::STATUS_SYNCED, self::STATUS_PENDING], true)) {
            // Unchanged — no API call. A pending flag settles back to synced.
            if ($profile->ghl_availability_status === self::STATUS_PENDING) {
                StylistProfile::query()->whereKey($profile->id)->toBase()
                    ->update(['ghl_availability_status' => self::STATUS_SYNCED]);
            }

            return;
        }

        $client = GhlClient::fromConnection($connection);

        // No stored schedule id? GHL may still HAVE one for this provider —
        // a previous run whose id never got persisted (crash, partial
        // deploy), or a hand-made schedule. Adopt it: updating an existing
        // schedule keeps the sync idempotent; a blind create would twin it.
        if ($profile->ghl_schedule_id === null) {
            $existing = $client->schedulesForUser((string) $profile->ghl_user_id);
            $adopted = collect($existing)->pluck('id')->first(fn ($id): bool => is_string($id) && $id !== '');

            if (is_string($adopted)) {
                $profile->ghl_schedule_id = $adopted;
            }
        }

        if ($profile->ghl_schedule_id !== null) {
            try {
                $client->updateSchedule($profile->ghl_schedule_id, $schedule);
            } catch (GhlApiException $e) {
                if ($e->reason !== GhlApiException::NOT_FOUND) {
                    throw $e;
                }

                // The schedule was deleted inside GHL — recreate it below.
                $profile->ghl_schedule_id = null;
            }
        }

        if ($profile->ghl_schedule_id === null) {
            $created = $client->createSchedule([
                ...$schedule,
                'userId' => $profile->ghl_user_id,
                'calendarIds' => [(string) $connection->calendar_id],
            ]);

            $id = $created['id'] ?? $created['_id'] ?? null;

            if (! is_string($id) || $id === '') {
                // Without the id every future sync would duplicate schedules.
                throw GhlApiException::fromStatus(500);
            }

            $profile->ghl_schedule_id = $id;
        }

        // Keep the schedule attached to the CURRENT master calendar (the
        // salon may have re-pointed calendar_id since the schedule was made).
        $client->applyScheduleToCalendar((string) $profile->ghl_schedule_id, (string) $connection->calendar_id);

        $this->mark($profile, self::STATUS_SYNCED, null, hash: $hash, touchSyncedAt: true);
    }

    /**
     * Calendar-level slot settings on the master calendar. GHL has ONE slot
     * duration/buffer per calendar, so the conservative mapping blocks for
     * the WORST case: the longest active service (incl. per-stylist duration
     * overrides) and, when the buffers flag is on, the longest cleanup
     * buffer. Shorter services under-offer in GHL; nothing over-offers.
     */
    public function pushCalendarSlotSettings(Salon $salon): void
    {
        $connection = $salon->ghlConnection()->first();

        if ($connection === null || ! $connection->isConnected() || blank($connection->calendar_id)) {
            return;
        }

        $durations = $salon->services()->where('active', true)->pluck('duration_min');

        if ($durations->isEmpty()) {
            return; // nothing bookable — leave the calendar untouched
        }

        $overrides = DB::table('service_stylist')
            ->join('services', 'services.id', '=', 'service_stylist.service_id')
            ->where('services.salon_id', $salon->id)
            ->where('services.active', true)
            ->get(['service_stylist.duration_override', 'service_stylist.buffer_override']);

        $slotDuration = max((int) $durations->max(), (int) $overrides->max('duration_override'));

        $slotBuffer = $salon->hasFeature(DurationResolver::BUFFER_FLAG)
            ? (int) $overrides->max('buffer_override')
            : 0;

        GhlClient::fromConnection($connection)->updateCalendar((string) $connection->calendar_id, [
            'slotDuration' => $slotDuration,
            'slotDurationUnit' => 'mins',
            'slotInterval' => self::SLOT_INTERVAL_MINUTES,
            'slotIntervalUnit' => 'mins',
            'slotBuffer' => $slotBuffer,
        ]);
    }

    /**
     * The stylist's availability as GHL schedule rules — pure and
     * deterministic (stable ordering, so the payload hash only changes when
     * the availability does).
     *
     * @return list<array<string, mixed>>
     */
    public static function rulesFor(Salon $salon, int $stylistUserId): array
    {
        $weekly = self::weeklyFreeMinutes($salon, $stylistUserId);

        $rules = [];

        foreach ($weekly as $weekday => $intervals) {
            if ($intervals === []) {
                continue;
            }

            $rules[] = [
                'type' => 'wday',
                'day' => self::DAY_NAMES[$weekday],
                'intervals' => self::formatIntervals($intervals),
            ];
        }

        foreach (self::timeOffDateRules($salon, $stylistUserId, $weekly) as $rule) {
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * Per weekday (0 = Monday … 6 = Sunday): WORK windows minus BREAK
     * windows, in minutes from midnight.
     *
     * @return array<int, list<array{0: int, 1: int}>>
     */
    private static function weeklyFreeMinutes(Salon $salon, int $stylistUserId): array
    {
        $windows = Availability::forSalon($salon)
            ->where('user_id', $stylistUserId)
            ->orderBy('start_minute')
            ->get(['weekday', 'kind', 'start_minute', 'end_minute']);

        $free = [];

        foreach (range(0, 6) as $weekday) {
            $ofDay = $windows->where('weekday', $weekday);

            $work = array_values($ofDay->where('kind', AvailabilityKind::Work)
                ->map(fn (Availability $a): array => [$a->start_minute, $a->end_minute])->all());
            $breaks = array_values($ofDay->where('kind', AvailabilityKind::Break)
                ->map(fn (Availability $a): array => [$a->start_minute, $a->end_minute])->all());

            $free[$weekday] = self::subtractMinutes(self::mergeMinutes($work), $breaks);
        }

        return $free;
    }

    /**
     * One date rule per future date with any date-specific entry:
     *
     * - HOURS entries are that date's schedule: they replace the weekly
     *   windows entirely (exactly like the slot engine), even on a weekly
     *   day off. Conservative rounding SHRINKS them to whole minutes.
     * - OFF entries carve out of the base (the hours override when present,
     *   else the weekly hours) — empty intervals = fully off. Conservative
     *   rounding WIDENS them.
     *
     * Boundaries are the salon's wall-clock — DST-safe.
     *
     * @param  array<int, list<array{0: int, 1: int}>>  $weekly
     * @return list<array<string, mixed>>
     */
    private static function timeOffDateRules(Salon $salon, int $stylistUserId, array $weekly): array
    {
        $tz = $salon->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $horizon = $today->addDays(self::TIME_OFF_HORIZON_DAYS);

        $blocks = TimeOff::forSalon($salon)
            ->where('user_id', $stylistUserId)
            ->where('ends_at', '>', $today->utc())
            ->where('starts_at', '<', $horizon->utc())
            ->orderBy('starts_at')
            ->get(['kind', 'starts_at', 'ends_at']);

        /** @var array<string, list<array{0: int, 1: int}>> $offByDate */
        $offByDate = [];
        /** @var array<string, list<array{0: int, 1: int}>> $hoursByDate */
        $hoursByDate = [];

        foreach ($blocks as $block) {
            $isHours = $block->kind === TimeOff::KIND_HOURS;
            $start = $block->starts_at->setTimezone($tz);
            $end = $block->ends_at->setTimezone($tz);

            if ($isHours) {
                // Availability may only SHRINK to whole minutes.
                $start = ($start->second > 0 || $start->microsecond > 0) ? $start->addMinute()->startOfMinute() : $start;
                $end = $end->startOfMinute();
            } else {
                // Unavailability may only GROW to whole minutes.
                $start = $start->startOfMinute();
                $end = ($end->second > 0 || $end->microsecond > 0) ? $end->addMinute()->startOfMinute() : $end;
            }

            for ($day = $start->startOfDay(); $day->lt($end) && $day->lte($horizon); $day = $day->addDay()) {
                if ($day->lt($today)) {
                    continue;
                }

                $fromMinute = $start->isSameDay($day) ? $start->hour * 60 + $start->minute : 0;
                $toMinute = $end->isSameDay($day) ? $end->hour * 60 + $end->minute : 1440;

                if ($toMinute > $fromMinute) {
                    if ($isHours) {
                        $hoursByDate[$day->toDateString()][] = [$fromMinute, $toMinute];
                    } else {
                        $offByDate[$day->toDateString()][] = [$fromMinute, $toMinute];
                    }
                }
            }
        }

        $dates = array_unique([...array_keys($offByDate), ...array_keys($hoursByDate)]);
        sort($dates);

        $rules = [];

        foreach ($dates as $date) {
            if (isset($hoursByDate[$date])) {
                // The override IS the day's schedule.
                $sorted = $hoursByDate[$date];
                usort($sorted, fn (array $a, array $b): int => $a[0] <=> $b[0]);
                $base = self::mergeMinutes($sorted);
            } else {
                $weekday = CarbonImmutable::parse($date, $tz)->dayOfWeekIso - 1;
                $base = $weekly[$weekday] ?? [];

                if ($base === []) {
                    continue; // time off on a weekly day off — nothing to carve
                }
            }

            $rules[] = [
                'type' => 'date',
                'date' => $date,
                'intervals' => self::formatIntervals(self::subtractMinutes($base, $offByDate[$date] ?? [])),
            ];
        }

        return $rules;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{from: string, to: string}>
     */
    private static function formatIntervals(array $intervals): array
    {
        return array_map(fn (array $interval): array => [
            'from' => self::minuteToHhmm($interval[0]),
            'to' => self::minuteToHhmm(min($interval[1], 1439)), // 24:00 isn't valid HH:MM — 23:59 under-offers one minute
        ], $intervals);
    }

    private static function minuteToHhmm(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    /**
     * Merge overlapping/adjacent minute intervals (input sorted by start).
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    private static function mergeMinutes(array $intervals): array
    {
        $merged = [];

        foreach ($intervals as [$start, $end]) {
            if ($end <= $start) {
                continue;
            }

            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * Subtract the busy intervals from the base intervals (minute-level twin
     * of SlotEngine::subtract).
     *
     * @param  list<array{0: int, 1: int}>  $base
     * @param  list<array{0: int, 1: int}>  $busy
     * @return list<array{0: int, 1: int}>
     */
    private static function subtractMinutes(array $base, array $busy): array
    {
        $result = $base;

        foreach ($busy as [$busyStart, $busyEnd]) {
            $next = [];

            foreach ($result as [$start, $end]) {
                if ($end <= $busyStart || $start >= $busyEnd) {
                    $next[] = [$start, $end];

                    continue;
                }

                if ($start < $busyStart) {
                    $next[] = [$start, $busyStart];
                }

                if ($end > $busyEnd) {
                    $next[] = [$busyEnd, $end];
                }
            }

            $result = $next;
        }

        return array_values(array_filter($result, fn (array $interval): bool => $interval[0] < $interval[1]));
    }

    private function mark(StylistProfile $profile, string $status, ?string $error, ?string $hash = null, bool $touchSyncedAt = false): void
    {
        $profile->forceFill([
            'ghl_availability_status' => $status,
            'ghl_availability_error' => $error,
            ...($hash !== null ? ['ghl_availability_hash' => $hash] : []),
            ...($touchSyncedAt ? ['ghl_availability_synced_at' => now()] : []),
        ])->save();
    }
}
