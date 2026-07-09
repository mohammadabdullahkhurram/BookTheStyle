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

// Elapsed, still-booked bookings become no-shows (and mirror to GHL via the
// queue above). Same cron drives it in production; locally use
// `php artisan schedule:work` or run bookings:auto-no-show directly.
Schedule::command('bookings:auto-no-show')
    ->everyFiveMinutes()
    ->withoutOverlapping();
