<?php

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
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

// Close out elapsed bookings: still-booked → no-show (mirrored to GHL via
// the queue above) and checked-in → completed (no GHL change — both map to
// "showed"). Same cron drives it in production; locally use
// `php artisan schedule:work` or run bookings:close-elapsed directly.
Schedule::command('bookings:close-elapsed')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Safety net for missed webhooks: hourly, pull each connected salon's GHL
// appointments (±7 days) and repair any drift — apply missed changes, import
// unknown appointments, flag vanished ones. One throttled API call per salon
// per run; the same Phase-7 crontab line drives it in production. Run
// `php artisan ghl:reconcile` any time for an on-demand pass.
Schedule::command('ghl:reconcile')
    ->hourly()
    ->withoutOverlapping();

// Retention pruning (shared hosting: unbounded rows eat the inode/disk
// budget). webhook_events prunes via the model's Prunable contract — 30
// days by default (GHL_WEBHOOK_RETENTION_DAYS), never PENDING rows, and
// every runtime lookback is far shorter (replay dedupe = 1 hour; sync
// state lives on bookings/clients columns). failed_jobs keeps 30 days of
// failures visible for debugging (QUEUE_FAILED_RETENTION_HOURS), old ones
// go. The same single crontab line drives both.
// Expired public-demo salons are hard-deleted hourly (blast-radius control).
Schedule::command('demo:sweep')->hourly()->withoutOverlapping();

Schedule::command('model:prune')
    ->dailyAt('03:10')
    ->withoutOverlapping();

Schedule::command('queue:prune-failed --hours='.(int) config('queue.failed_retention_hours', 720))
    ->dailyAt('03:20')
    ->withoutOverlapping();
