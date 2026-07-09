<?php

namespace App\Actions\Availability;

use App\Enums\AvailabilityKind;
use App\Jobs\SyncAvailabilityToGhl;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\User;
use App\Support\Permissions\AvailabilityAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replace a stylist's whole week of WORK windows in one go — the write path for
 * the inline weekly-hours grid. Each day carries zero or more work windows; a
 * day with two windows is a split shift (the gap between them is unbookable, so
 * a midday break needs no separate record). Only WORK availability is touched:
 * any BREAK windows and one-off time off are left exactly as they were, so the
 * slot engine reads the same records it always has — this is a UX change, not a
 * storage or engine change.
 *
 * Enforces server-side: the actor may manage this stylist, the target is an
 * active stylist of the salon, every window has a positive in-day duration, and
 * a day's windows don't overlap each other.
 */
class SaveWeeklyHours
{
    public function __construct(private AvailabilityAccess $access) {}

    /**
     * @param  array<int, list<array{start_minute: int, end_minute: int}>>  $week
     *                                                                             Keyed by weekday (0 = Monday … 6 = Sunday).
     */
    public function handle(User $actor, Salon $salon, int $stylistUserId, array $week): void
    {
        if (! $this->access->canManage($actor, $salon, $stylistUserId)) {
            throw new AuthorizationException('You may not manage this stylist\'s availability.');
        }

        if (! $salon->stylistUsers()->whereKey($stylistUserId)->exists()) {
            throw ValidationException::withMessages([
                'stylist' => __('That person is not an active stylist in this salon.'),
            ]);
        }

        $clean = $this->validateWeek($week);

        DB::transaction(function () use ($salon, $stylistUserId, $clean): void {
            Availability::query()
                ->where('salon_id', $salon->id)
                ->where('user_id', $stylistUserId)
                ->where('kind', AvailabilityKind::Work->value)
                ->delete();

            foreach ($clean as $weekday => $windows) {
                foreach ($windows as $window) {
                    Availability::create([
                        'salon_id' => $salon->id,
                        'user_id' => $stylistUserId,
                        'weekday' => $weekday,
                        'kind' => AvailabilityKind::Work,
                        'start_minute' => $window['start_minute'],
                        'end_minute' => $window['end_minute'],
                    ]);
                }
            }
        });

        // Mirror the new week into GHL so its AI books within these hours
        // (queued after commit; a no-op when unconnected or unmapped).
        SyncAvailabilityToGhl::queueForStylist($salon, $stylistUserId);
    }

    /**
     * Normalise + validate the submitted week, returning sorted windows keyed by
     * weekday. Throws on any invalid duration or intra-day overlap.
     *
     * @param  array<int, list<array{start_minute: int, end_minute: int}>>  $week
     * @return array<int, list<array{start_minute: int, end_minute: int}>>
     */
    private function validateWeek(array $week): array
    {
        $clean = [];

        foreach ($week as $weekday => $windows) {
            $weekday = (int) $weekday;

            if ($weekday < 0 || $weekday > 6) {
                throw ValidationException::withMessages(['weekly' => __('Invalid weekday.')]);
            }

            $normalised = [];

            foreach ($windows as $window) {
                $start = (int) $window['start_minute'];
                $end = (int) $window['end_minute'];

                if ($start < 0 || $end > 1440 || $end <= $start) {
                    throw ValidationException::withMessages([
                        'weekly' => __('Each working window must end after it starts.'),
                    ]);
                }

                $normalised[] = ['start_minute' => $start, 'end_minute' => $end];
            }

            usort($normalised, fn (array $a, array $b): int => $a['start_minute'] <=> $b['start_minute']);

            $previousEnd = null;
            foreach ($normalised as $window) {
                if ($previousEnd !== null && $window['start_minute'] < $previousEnd) {
                    throw ValidationException::withMessages([
                        'weekly' => __('A day\'s working windows can\'t overlap. Use a gap for a break.'),
                    ]);
                }
                $previousEnd = $window['end_minute'];
            }

            $clean[$weekday] = $normalised;
        }

        return $clean;
    }
}
