<?php

use App\Models\Salon;
use App\Models\WebhookEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Retention pruning (shared hosting: finite inode/disk budget — unbounded
| rows eventually bite). webhook_events and failed_jobs are the two tables
| that grow without limit; both get a daily scheduled sweep. The pruning
| must NEVER remove state the sync depends on: replay dedupe looks back one
| hour, echo-loop state lives on bookings/clients columns, and PENDING
| events (not yet processed) are kept regardless of age.
*/

function webhookEventAgedDays(Salon $salon, int $days, string $status = WebhookEvent::STATUS_APPLIED): WebhookEvent
{
    $event = WebhookEvent::create([
        'salon_id' => $salon->id,
        'event_type' => 'appointment',
        'payload' => ['type' => 'appointment'],
        'payload_hash' => hash('sha256', Str::uuid()->toString()),
        'status' => $status,
        'processed_at' => now()->subDays($days),
    ]);
    $event->forceFill(['created_at' => now()->subDays($days)])->save();

    return $event;
}

it('prunes processed webhook events past the retention window, keeps fresh and PENDING ones', function () {
    $salon = Salon::factory()->create();

    $ancient = webhookEventAgedDays($salon, 45);
    $ancientPending = webhookEventAgedDays($salon, 45, WebhookEvent::STATUS_PENDING);
    $recent = webhookEventAgedDays($salon, 5);
    $today = webhookEventAgedDays($salon, 0);

    $this->artisan('model:prune')->assertExitCode(0);

    // Old processed row: gone. Old PENDING row: kept (never vanish an
    // unprocessed event). Everything inside the window: kept.
    expect(WebhookEvent::query()->whereKey($ancient->id)->exists())->toBeFalse();
    expect(WebhookEvent::query()->whereKey($ancientPending->id)->exists())->toBeTrue();
    expect(WebhookEvent::query()->whereKey($recent->id)->exists())->toBeTrue();
    expect(WebhookEvent::query()->whereKey($today->id)->exists())->toBeTrue();
});

it('respects the configurable webhook retention window', function () {
    config(['ghl.webhook_retention_days' => 7]);
    $salon = Salon::factory()->create();

    $overWindow = webhookEventAgedDays($salon, 10);
    $underWindow = webhookEventAgedDays($salon, 5);

    $this->artisan('model:prune')->assertExitCode(0);

    expect(WebhookEvent::query()->whereKey($overWindow->id)->exists())->toBeFalse();
    expect(WebhookEvent::query()->whereKey($underWindow->id)->exists())->toBeTrue();
});

it('prunes old failed jobs but keeps recent failures visible for debugging', function () {
    $insert = fn (int $daysAgo) => DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'Test exception',
        'failed_at' => now()->subDays($daysAgo),
    ]);

    $old = $insert(45);
    $recent = $insert(2);

    $this->artisan('queue:prune-failed', ['--hours' => (int) config('queue.failed_retention_hours')])
        ->assertExitCode(0);

    expect(DB::table('failed_jobs')->where('id', $old)->exists())->toBeFalse();
    expect(DB::table('failed_jobs')->where('id', $recent)->exists())->toBeTrue();
});

it('schedules both prunes daily alongside the existing jobs — one cron drives everything', function () {
    $this->artisan('schedule:list');

    $events = collect(app(Schedule::class)->events());
    $commands = $events->map(fn ($event) => (string) $event->command);

    expect($commands->contains(fn ($c) => str_contains($c, 'model:prune')))->toBeTrue();
    expect($commands->contains(fn ($c) => str_contains($c, 'queue:prune-failed --hours=720')))->toBeTrue();

    // Daily, overlap-safe, and the pre-existing schedule is intact.
    foreach (['model:prune', 'queue:prune-failed'] as $needle) {
        $event = $events->first(fn ($event) => str_contains((string) $event->command, $needle));
        expect($event->expression)->toContain('* * *');
        expect($event->withoutOverlapping)->toBeTrue();
    }
    expect($commands->contains(fn ($c) => str_contains($c, 'queue:work')))->toBeTrue();
    expect($commands->contains(fn ($c) => str_contains($c, 'bookings:close-elapsed')))->toBeTrue();
    expect($commands->contains(fn ($c) => str_contains($c, 'ghl:reconcile')))->toBeTrue();
});

it('keeps sensible, env-tunable retention defaults', function () {
    expect((int) config('ghl.webhook_retention_days'))->toBe(30);
    expect((int) config('queue.failed_retention_hours'))->toBe(720);
});
