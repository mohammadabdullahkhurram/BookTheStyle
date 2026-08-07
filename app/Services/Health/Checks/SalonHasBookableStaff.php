<?php

namespace App\Services\Health\Checks;

use App\Enums\StaffType;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class SalonHasBookableStaff implements HealthCheck
{
    public function key(): string
    {
        return 'bookable-staff';
    }

    public function label(): string
    {
        return __('Bookable staff');
    }

    public function run(HealthContext $context): CheckResult
    {
        // REAL bookable people only — the disposable test stylist never counts.
        $count = $context->salon->memberships()
            ->where('staff_type', StaffType::Stylist->value)
            ->where('active', true)
            ->whereHas('user', fn ($q) => $q->where('is_test', false))
            ->count();

        if ($count === 0) {
            return CheckResult::fail(
                __('Nobody real takes bookings here — no stylists, and no owner/manager with "Takes bookings" on.'),
                __('Add stylists, or tick Takes bookings for an owner/manager (SOP part A, step 3).'),
            );
        }

        return CheckResult::pass(__(':count real person(s) take bookings at this salon.', ['count' => $count]));
    }
}
