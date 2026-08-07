<?php

use App\Services\Health\Heartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared hosting has no always-on queue worker (SPEC §8): the GHL sync jobs
// run on the database queue, drained every minute by the scheduler. One
// crontab line drives everything in production (see docs/DEPLOY.md):
//   * * * * * php artisan schedule:run >> /dev/null 2>&1
// Flags: --stop-when-empty exits once the queue drains (no lingering
// process), --max-time=55 caps a busy drain under the next cron tick so
// withoutOverlapping() releases on time, --tries=3 retries transient GHL
// failures before failed_jobs. Net effect: jobs run within ~1 minute of
// dispatch — that delay on GHL syncs is expected and acceptable. Locally
// `composer dev` runs queue:listen instead, so jobs process live.
// Health-check heartbeat: proves the cron itself is alive. The scheduler
// stamps this cache every tick; the SchedulerHeartbeat check reads it —
// the direct guard against the past cron-silently-dead failure.
Schedule::call(fn () => Heartbeat::beat(Heartbeat::SCHEDULER))
    ->name('health-heartbeat')
    ->everyMinute();

Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

// Close out elapsed bookings: still-booked → no-show (mirrored to GHL via
// the queue above) and checked-in → completed (no GHL change — both map to
// "showed"). Same cron drives it in production; locally use
// `php artisan schedule:work` or run bookings:close-elapsed directly.
Schedule::command('bookings:close-elapsed')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onSuccess(fn () => Heartbeat::beat(Heartbeat::taskKey('bookings:close-elapsed')));

// Safety net for missed webhooks: hourly, pull each connected salon's GHL
// appointments (±7 days) and repair any drift — apply missed changes, import
// unknown appointments, flag vanished ones. One throttled API call per salon
// per run; the same Phase-7 crontab line drives it in production. Run
// `php artisan ghl:reconcile` any time for an on-demand pass.
Schedule::command('ghl:reconcile')
    ->hourly()
    ->withoutOverlapping()
    ->onSuccess(fn () => Heartbeat::beat(Heartbeat::taskKey('ghl:reconcile')));

// Retention pruning (shared hosting: unbounded rows eat the inode/disk
// budget). webhook_events prunes via the model's Prunable contract — 30
// days by default (GHL_WEBHOOK_RETENTION_DAYS), never PENDING rows, and
// every runtime lookback is far shorter (replay dedupe = 1 hour; sync
// state lives on bookings/clients columns). failed_jobs keeps 30 days of
// failures visible for debugging (QUEUE_FAILED_RETENTION_HOURS), old ones
// go. The same single crontab line drives both.
// Expired public-demo salons are hard-deleted hourly (blast-radius control).
Schedule::command('demo:sweep')->hourly()->withoutOverlapping();

// Abandoned "Check connections" test records (stylist/service/client)
// are torn down after 24h so no live salon keeps a phantom test stylist.
Schedule::command('diagnostics:sweep-test-records')->hourly()->withoutOverlapping()
    ->onSuccess(fn () => Heartbeat::beat(Heartbeat::taskKey('diagnostics:sweep-test-records')));

// The demo showcase resets nightly: visitor-created bookings (the demo's
// one try-it exemption) are cleared and the seeded calendar re-anchors to
// "now", so every day's demo looks current and tidy. Same single cron line.
// Automatic health monitor: the READ-ONLY checks against every live salon,
// hourly — history recorded, green→red regressions emailed to agency
// admins. Never creates test records, never books, never emails clients.
Schedule::command('health:monitor')
    ->hourly()
    ->withoutOverlapping()
    ->onSuccess(fn () => Heartbeat::beat(Heartbeat::taskKey('health:monitor')));

Schedule::command('demo:reset-showcase')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onSuccess(fn () => Heartbeat::beat(Heartbeat::taskKey('demo:reset-showcase')));

Schedule::command('model:prune')
    ->dailyAt('03:10')
    ->withoutOverlapping();

Schedule::command('queue:prune-failed --hours='.(int) config('queue.failed_retention_hours', 720))
    ->dailyAt('03:20')
    ->withoutOverlapping();
