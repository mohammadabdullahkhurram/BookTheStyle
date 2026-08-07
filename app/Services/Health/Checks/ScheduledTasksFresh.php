<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use App\Services\Health\Heartbeat;

/**
 * Per-task freshness: key scheduled tasks stamp a heartbeat on success
 * (routes/console.php). Stale beyond ~3× their cadence → flagged. A task
 * that has never stamped only warns — a fresh deploy legitimately starts
 * empty; the scheduler check above catches a dead cron outright.
 */
class ScheduledTasksFresh implements HealthCheck
{
    /** @var array<string, array{label: string, minutes: int}> task → cadence */
    private const TASKS = [
        'bookings:close-elapsed' => ['label' => 'No-show / completion automation', 'minutes' => 15],
        'ghl:reconcile' => ['label' => 'GHL drift repair', 'minutes' => 180],
        'diagnostics:sweep-test-records' => ['label' => 'Test-record sweep', 'minutes' => 180],
        'demo:reset-showcase' => ['label' => 'Demo nightly reset', 'minutes' => 3 * 24 * 60],
    ];

    public function key(): string
    {
        return 'scheduled-tasks';
    }

    public function label(): string
    {
        return __('Key scheduled tasks');
    }

    public function run(HealthContext $context): CheckResult
    {
        $stale = [];
        $neverRan = [];

        foreach (self::TASKS as $task => $meta) {
            $seen = Heartbeat::lastSeen(Heartbeat::taskKey($task));

            if ($seen === null) {
                $neverRan[] = __($meta['label']);
            } elseif ($seen->lt(now()->subMinutes($meta['minutes']))) {
                $stale[] = __($meta['label']).' ('.$seen->diffForHumans().')';
            }
        }

        if ($stale !== []) {
            return CheckResult::fail(
                __('These scheduled tasks have gone stale: :tasks.', ['tasks' => implode('; ', $stale)]),
                __('If the scheduler line above is green, check the task\'s own output: php artisan <task> by hand and read the error.'),
            );
        }

        if ($neverRan !== []) {
            return CheckResult::warn(
                __('No success recorded yet for: :tasks — normal right after a deploy; re-check within a few hours.', ['tasks' => implode('; ', $neverRan)]),
            );
        }

        return CheckResult::pass(__('All key scheduled tasks (no-show automation, GHL repair, sweeps, demo reset) have run recently.'));
    }
}
