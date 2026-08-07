<?php

namespace App\Services\Health\Checks;

use App\Models\Service;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

/**
 * Integrity spot check: active services no stylist is qualified for. They
 * show in menus but every booking attempt dead-ends ("no availability",
 * ever). Listed by name. Read-only; test records excluded.
 */
class ServicesWithoutStylists implements HealthCheck
{
    public function key(): string
    {
        return 'services-without-stylists';
    }

    public function label(): string
    {
        return __('Services nobody can perform');
    }

    public function run(HealthContext $context): CheckResult
    {
        $names = Service::forSalon($context->salon)
            ->where('active', true)
            ->where('is_test', false)
            ->whereDoesntHave('stylists')
            ->orderBy('name')
            ->pluck('name');

        if ($names->isEmpty()) {
            return CheckResult::pass(__('Every active service has at least one qualified stylist.'));
        }

        return CheckResult::fail(
            trans_choice(
                ':count active service has no qualified stylist, so it can never be booked: :names.|:count active services have no qualified stylist, so they can never be booked: :names.',
                $names->count(),
                ['count' => $names->count(), 'names' => $names->implode(', ')],
            ),
            __('On the Services page, open each one and tick the stylists who perform it — or deactivate the service.'),
        );
    }
}
