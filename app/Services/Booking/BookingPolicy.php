<?php

namespace App\Services\Booking;

use App\Models\Salon;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Temporal booking-policy rules (SPEC §5.5): minimum notice, same-day, max
 * advance, walk-ins. Used both to filter offered slots and to authoritatively
 * enforce policy at booking time. All comparisons are in the salon's timezone.
 */
class BookingPolicy
{
    /**
     * Whether a scheduled (non-walk-in) slot at $start may be offered/booked.
     */
    public function slotIsOfferable(Salon $salon, CarbonImmutable $start): bool
    {
        $tz = $salon->timezone;
        $now = CarbonImmutable::now($tz);

        if ($start->lt($now->addMinutes($salon->min_notice_minutes))) {
            return false;
        }

        $today = $now->startOfDay();
        $day = $start->setTimezone($tz)->startOfDay();

        if ($day->lt($today)) {
            return false;
        }

        if ($day->gt($today->addDays($salon->max_advance_days))) {
            return false;
        }

        if ($day->eq($today) && ! $salon->allow_same_day) {
            return false;
        }

        return true;
    }

    /**
     * Authoritatively enforce policy when creating a booking. Throws a
     * ValidationException with a clear message on any violation.
     */
    public function assertCreatable(Salon $salon, CarbonImmutable $start, bool $isWalkin): void
    {
        $tz = $salon->timezone;
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();
        $day = $start->setTimezone($tz)->startOfDay();

        if ($isWalkin) {
            // Walk-ins are immediate; they need allow_walkins but bypass
            // min-notice / same-day / advance (those govern scheduled bookings).
            if (! $salon->allow_walkins) {
                $this->fail(__('Walk-ins are not allowed at this salon.'));
            }

            return;
        }

        if ($day->lt($today)) {
            $this->fail(__('You cannot book in the past.'));
        }

        if ($start->lt($now->addMinutes($salon->min_notice_minutes))) {
            $this->fail(__('This is too soon — the salon requires at least :n minutes notice.', ['n' => $salon->min_notice_minutes]));
        }

        if ($day->gt($today->addDays($salon->max_advance_days))) {
            $this->fail(__('That date is too far in advance (max :n days).', ['n' => $salon->max_advance_days]));
        }

        if ($day->eq($today) && ! $salon->allow_same_day) {
            $this->fail(__('Same-day booking is not allowed at this salon.'));
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['start' => $message]);
    }
}
