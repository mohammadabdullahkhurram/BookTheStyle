<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class SalonHasServices implements HealthCheck
{
    public function key(): string
    {
        return 'services';
    }

    public function label(): string
    {
        return __('Services');
    }

    public function run(HealthContext $context): CheckResult
    {
        $count = $context->salon->services()->where('active', true)->where('is_test', false)->count();

        if ($count === 0) {
            return CheckResult::fail(
                __('The salon has no real services yet — there is nothing a client could book.'),
                __('Add services with prices and durations (SOP part A, step 4).'),
            );
        }

        return CheckResult::pass(__('The salon offers :count active service(s).', ['count' => $count]));
    }
}
