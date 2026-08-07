<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class ApiTokenIssued implements HealthCheck
{
    public function key(): string
    {
        return 'api-token';
    }

    public function label(): string
    {
        return __('Booking API token');
    }

    public function run(HealthContext $context): CheckResult
    {
        if ($context->salon->api_token_hash === null) {
            return CheckResult::fail(
                __('No booking API token has been generated — the Voice AI cannot call this salon.'),
                __('Open Settings → Integrations → Voice-AI Booking API, generate a token, and paste it into both GHL Custom Actions.'),
            );
        }

        return CheckResult::pass(__('A token was generated on :date. Its correctness is proven by the GHL round-trip below — BookTheStyle only stores a fingerprint, so make sure GHL holds the latest copy.', [
            'date' => $context->salon->api_token_generated_at?->toFormattedDateString() ?? '—',
        ]));
    }
}
