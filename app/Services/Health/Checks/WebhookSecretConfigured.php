<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class WebhookSecretConfigured implements HealthCheck
{
    public function key(): string
    {
        return 'webhook-secret';
    }

    public function label(): string
    {
        return __('Inbound webhook secret');
    }

    public function run(HealthContext $context): CheckResult
    {
        $connection = $context->salon->ghlConnection()->first();

        if (filled($connection?->webhook_secret)) {
            return CheckResult::pass(__('A webhook secret is set — GHL events sent with the matching X-Webhook-Secret header will be accepted.'));
        }

        return CheckResult::fail(
            __('No webhook secret is set, so GHL-side booking events cannot flow back in.'),
            __('Set it on Settings → Integrations and in the GHL workflow that posts to the webhook.'),
        );
    }
}
