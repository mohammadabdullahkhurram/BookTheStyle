<?php

namespace App\Services\Health\Checks;

use App\Enums\AvailabilityKind;
use App\Models\Availability;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

/**
 * Integrity spot check: real bookable stylists with no weekly working hours
 * at all. They appear as choices but never have an open slot — a quiet
 * booking dead-end, usually a stylist added after go-live whose hours were
 * forgotten. Listed by name. Read-only; test records excluded.
 */
class StylistsWithoutHours implements HealthCheck
{
    public function key(): string
    {
        return 'stylists-without-hours';
    }

    public function label(): string
    {
        return __('Stylists with no working hours');
    }

    public function run(HealthContext $context): CheckResult
    {
        $stylists = $context->salon->stylistUsers()
            ->where('users.is_test', false)
            ->pluck('users.name', 'users.id');

        if ($stylists->isEmpty()) {
            return CheckResult::pass(__('No bookable stylists yet — nothing to check. (Salon readiness covers whether that itself is a problem.)'));
        }

        $withHours = Availability::forSalon($context->salon)
            ->where('kind', AvailabilityKind::Work->value)
            ->whereIn('user_id', $stylists->keys())
            ->distinct()
            ->pluck('user_id');

        $missing = $stylists->except($withHours->all())->values();

        if ($missing->isEmpty()) {
            return CheckResult::pass(__('Every bookable stylist has weekly working hours set.'));
        }

        return CheckResult::warn(
            trans_choice(
                ':count bookable stylist has NO working hours, so clients can never book them: :names.|:count bookable stylists have NO working hours, so clients can never book them: :names.',
                $missing->count(),
                ['count' => $missing->count(), 'names' => $missing->implode(', ')],
            ),
            __('Set their weekly hours on the Availability page — or untick “takes bookings” if they should not be bookable.'),
        );
    }
}
