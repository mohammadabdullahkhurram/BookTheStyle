<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class GhlConnectionConfigured implements HealthCheck
{
    public function key(): string
    {
        return 'ghl-connection';
    }

    public function label(): string
    {
        return __('GoHighLevel connection');
    }

    public function run(HealthContext $context): CheckResult
    {
        $connection = $context->salon->ghlConnection()->first();

        if ($connection === null || blank($connection->location_id) || ! $connection->hasToken()) {
            return CheckResult::warn(
                __('The GHL connection is not set up (location id / integration token missing). Bookings still work in BookTheStyle, but nothing syncs to GHL until it is connected.'),
                __('Connect it on Settings → Integrations (SOP part C).'),
            );
        }

        $verified = $connection->last_verified_at !== null
            ? __(' (last verified :when)', ['when' => $connection->last_verified_at->diffForHumans()])
            : '';

        return CheckResult::pass(__('Location and token are configured:verified. Use the Integrations tab\'s own verify buttons for a live GHL API test.', ['verified' => $verified]));
    }
}
