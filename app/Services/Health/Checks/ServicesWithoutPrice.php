<?php

namespace App\Services\Health\Checks;

use App\Models\Service;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

/**
 * Integrity spot check: active services with no price. NULL is a supported
 * state ("price varies") so this only WARNS — but a whole menu of missing
 * prices is usually an oversight, and clients see it. Read-only; test
 * records excluded.
 */
class ServicesWithoutPrice implements HealthCheck
{
    public function key(): string
    {
        return 'services-without-price';
    }

    public function label(): string
    {
        return __('Services with no price');
    }

    public function run(HealthContext $context): CheckResult
    {
        $names = Service::forSalon($context->salon)
            ->where('active', true)
            ->where('is_test', false)
            ->whereNull('price_cents')
            ->orderBy('name')
            ->pluck('name');

        if ($names->isEmpty()) {
            return CheckResult::pass(__('Every active service has a price set.'));
        }

        return CheckResult::warn(
            trans_choice(
                ':count active service has no price and shows as “price varies”: :names. Fine if intentional — worth a look if not.|:count active services have no price and show as “price varies”: :names. Fine if intentional — worth a look if not.',
                $names->count(),
                ['count' => $names->count(), 'names' => $names->implode(', ')],
            ),
            __('Set prices on the Services page; leave blank only where the price genuinely varies.'),
        );
    }
}
