<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use Illuminate\Support\Facades\DB;

/**
 * Read-only queue state: backlog depth/age from the jobs table and recent
 * failures from failed_jobs. Never triggers or retries anything.
 */
class QueueHealth implements HealthCheck
{
    public function key(): string
    {
        return 'queue';
    }

    public function label(): string
    {
        return __('Queue');
    }

    public function run(HealthContext $context): CheckResult
    {
        $pending = (int) DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('available_at');
        $oldestAge = $oldest !== null ? now()->getTimestamp() - (int) $oldest : 0;
        $recentFailures = (int) DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        if ($oldestAge > 600) {
            return CheckResult::fail(
                __('The queue is stuck: the oldest waiting job has sat for :minutes minutes (:count pending). GHL syncs and mail are not going out.', ['minutes' => (int) floor($oldestAge / 60), 'count' => $pending]),
                __('The per-minute cron drains the queue — check the scheduler line above, then the failed-jobs table.'),
            );
        }

        if ($recentFailures > 0) {
            return CheckResult::warn(
                __(':count job(s) failed in the last 24 hours. Work is flowing, but something is erroring.', ['count' => $recentFailures]),
                __('Ask the technical team to read failed_jobs (payload names the job and the error).'),
            );
        }

        if ($pending > 100) {
            return CheckResult::warn(
                __('The queue is deep (:count pending) but moving — worth a look if it stays this high.', ['count' => $pending]),
            );
        }

        return CheckResult::pass(__('The queue is healthy: :count pending, no failures in the last 24 hours.', ['count' => $pending]));
    }
}
