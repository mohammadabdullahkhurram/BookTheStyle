<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use App\Services\Health\Heartbeat;

/**
 * The direct guard against the past production failure: the cron silently
 * not running. routes/console.php stamps a heartbeat every scheduler tick;
 * if it is missing or stale, NOTHING scheduled is happening — queue,
 * reminders, reconcile, sweeps.
 */
class SchedulerHeartbeat implements HealthCheck
{
    public function key(): string
    {
        return 'scheduler';
    }

    public function label(): string
    {
        return __('Scheduler (cron)');
    }

    public function run(HealthContext $context): CheckResult
    {
        $seen = Heartbeat::lastSeen(Heartbeat::SCHEDULER);

        if ($seen === null) {
            return CheckResult::fail(
                __('The scheduler has never reported in — the cron is not running, so NO background work happens (queue, reminders, GHL sync, sweeps).'),
                __('Check hPanel → Advanced → Cron Jobs for the schedule:run line (docs/DEPLOY.md).'),
            );
        }

        if ($seen->lt(now()->subMinutes(5))) {
            return CheckResult::fail(
                __('The scheduler last ran :when — it should tick every minute. Background work has stopped.', ['when' => $seen->diffForHumans()]),
                __('Check hPanel → Advanced → Cron Jobs; run php artisan schedule:run by hand and watch for errors.'),
            );
        }

        return CheckResult::pass(__('The scheduler is running (last tick :when) — background work is happening on time.', ['when' => $seen->diffForHumans()]));
    }
}
