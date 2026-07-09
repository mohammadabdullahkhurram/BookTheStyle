<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared hosting has no always-on queue worker (SPEC §8): the GHL sync jobs
// run on the database queue, drained every minute by the scheduler. Retry
// counts/backoff live on the job classes. In production (Phase 7) one
// crontab line drives this:
//   * * * * * php artisan schedule:run >> /dev/null 2>&1
// Locally `composer dev` runs queue:listen instead, so jobs process live.
Schedule::command('queue:work --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping();

// Close out elapsed bookings: still-booked → no-show (mirrored to GHL via
// the queue above) and checked-in → completed (no GHL change — both map to
// "showed"). Same cron drives it in production; locally use
// `php artisan schedule:work` or run bookings:close-elapsed directly.
Schedule::command('bookings:close-elapsed')
    ->everyFiveMinutes()
    ->withoutOverlapping();
