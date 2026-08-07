<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

/**
 * Honest wiring check: client confirmations and reminders ride the salon's
 * GHL workflows, not app mail. BTS can verify the pieces IT holds (the
 * connection + webhook); whether the workflows are switched ON lives in
 * GHL and is proven by the round-trip test call, and this says so.
 */
class ClientMessagingPathway implements HealthCheck
{
    public function key(): string
    {
        return 'client-messaging';
    }

    public function label(): string
    {
        return __('Client confirmations & reminders');
    }

    public function run(HealthContext $context): CheckResult
    {
        $connection = $context->salon->ghlConnection()->first();
        $connected = $connection !== null && $connection->hasToken();
        $webhook = filled($connection?->webhook_secret);

        if (! $connected) {
            return CheckResult::warn(
                __('Client texts and emails (confirmations, reminders, no-show follow-ups) are sent by the salon\'s GHL workflows — and GHL is not connected yet, so clients currently get nothing.'),
                __('Connect GHL (SOP part B–C), then confirm the workflows are ON in GHL.'),
            );
        }

        return CheckResult::pass(__('The pathway is wired on this side: bookings sync to GHL:webhook. Whether the confirmation/reminder workflows are switched ON lives in GHL — verify there, and prove the whole loop with the round-trip below.', [
            'webhook' => $webhook ? __(' and GHL events flow back (webhook secret set)') : '',
        ]));
    }
}
