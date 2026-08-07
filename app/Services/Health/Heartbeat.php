<?php

namespace App\Services\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduler/task heartbeats: routes/console.php stamps these caches (the
 * scheduler itself every minute; key tasks on success), and the schedule
 * checks read them. This is the direct guard against the past production
 * failure where the cron silently was not running.
 */
final class Heartbeat
{
    public const SCHEDULER = 'health:heartbeat:scheduler';

    public static function taskKey(string $task): string
    {
        return 'health:heartbeat:task:'.$task;
    }

    public static function beat(string $key): void
    {
        Cache::put($key, now()->toIso8601String(), now()->addDays(3));
    }

    public static function lastSeen(string $key): ?Carbon
    {
        $at = Cache::get($key);

        return is_string($at) ? Carbon::parse($at) : null;
    }
}
