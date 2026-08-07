<?php

namespace App\Services\Health\Checks;

use App\Enums\AvailabilityKind;
use App\Enums\StaffType;
use App\Models\Availability;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class SalonHasHours implements HealthCheck
{
    public function key(): string
    {
        return 'hours';
    }

    public function label(): string
    {
        return __('Working hours');
    }

    public function run(HealthContext $context): CheckResult
    {
        $realBookableIds = $context->salon->memberships()
            ->where('staff_type', StaffType::Stylist->value)
            ->where('active', true)
            ->whereHas('user', fn ($q) => $q->where('is_test', false))
            ->pluck('user_id');

        if ($realBookableIds->isEmpty()) {
            return CheckResult::warn(__('No real bookable staff yet, so there are no hours to check — fix the Bookable staff line first.'));
        }

        $withHours = Availability::withoutGlobalScopes()
            ->where('salon_id', $context->salon->id)
            ->whereIn('user_id', $realBookableIds)
            ->where('kind', AvailabilityKind::Work)
            ->distinct()
            ->count('user_id');

        if ($withHours === 0) {
            return CheckResult::fail(
                __('Nobody has working hours set — with no hours, no times can ever be offered.'),
                __('Set each bookable person\'s weekly hours (SOP part A, step 5).'),
            );
        }

        if ($withHours < $realBookableIds->count()) {
            return CheckResult::warn(
                __(':with of :total bookable people have hours — the others can never be booked.', ['with' => $withHours, 'total' => $realBookableIds->count()]),
                __('Set weekly hours for the rest on the Availability page.'),
            );
        }

        return CheckResult::pass(__('Every bookable person has working hours set.'));
    }
}
