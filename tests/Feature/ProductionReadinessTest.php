<?php

use App\Enums\AgencyRole;
use App\Models\Salon;
use App\Models\User;
use App\Services\Calendar\CalendarFeedService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
| Hostinger Cloud production readiness: the cron-driven queue worker, proxy /
| HTTPS correctness, config-derived public URLs, safe env defaults, the
| factory-reset production password, and the no-destructive-schema guard.
| All config-only — local dev (lvh.me, plain http) must keep working as-is.
*/

// ---------------------------------------------------------------------------
// Cron-driven queue (no supervisor on the host)
// ---------------------------------------------------------------------------

it('schedules the queue worker every minute, overlap-safe, with bounded runtime', function () {
    // Console routes register on kernel bootstrap; running any artisan
    // command loads them into the Schedule.
    $this->artisan('schedule:list');

    $events = collect(app(Schedule::class)->events());
    $queue = $events->first(fn ($event) => str_contains((string) $event->command, 'queue:work'));

    expect($queue)->not->toBeNull();
    expect((string) $queue->command)
        ->toContain('--stop-when-empty')   // exits once drained — no lingering process
        ->toContain('--max-time=55')       // finishes before the next cron tick
        ->toContain('--tries=3');          // transient GHL failures retry before failed_jobs
    expect($queue->expression)->toBe('* * * * *');
    expect($queue->withoutOverlapping)->toBeTrue();

    // The existing scheduled commands are intact alongside it.
    expect($events->contains(fn ($event) => str_contains((string) $event->command, 'bookings:close-elapsed')))->toBeTrue();
    expect($events->contains(fn ($event) => str_contains((string) $event->command, 'ghl:reconcile')))->toBeTrue();
});

it('has the database queue tables the cron-drained worker needs', function () {
    foreach (['jobs', 'job_batches', 'failed_jobs'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    // Tests run the sync driver on purpose; the shipped default is database
    // (asserted against .env.example below) and the driver config exists.
    expect(config('queue.connections.database.driver'))->toBe('database');
});

// ---------------------------------------------------------------------------
// HTTPS / proxy correctness
// ---------------------------------------------------------------------------

it('trusts the platform proxy: forwarded proto https makes the request and URLs secure', function () {
    $this->get('/up', ['X-Forwarded-Proto' => 'https'])->assertOk();

    expect(request()->isSecure())->toBeTrue();
    expect(url('/anything'))->toStartWith('https://');
});

it('forces https URL generation in production (and only there)', function () {
    $source = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($source)->toContain("URL::forceScheme('https')");
    expect(str_contains($source, 'isProduction()'))->toBeTrue();

    // Local dev is untouched: plain http URLs still generate here.
    expect(url('/'))->toStartWith('http://');
});

it('derives every UI-shown integration URL from config, never a hardcoded host', function () {
    // Host-bound routes take their domain from APP_DOMAIN…
    expect(route('webhooks.ghl'))->toContain('app.'.config('app.domain'));
    expect(route('widget.script'))->toContain((string) config('app.domain'));
    expect(route('api.booking.availability'))->toContain('app.'.config('app.domain'));

    // …and the voice-API endpoints printed in settings come from APP_URL.
    config(['app.url' => 'https://app.bookthestyle.com']);
    $salon = Salon::factory()->create();

    Livewire\Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->assertSee('https://app.bookthestyle.com/api/v1/booking/availability');
});

it('serves calendar feed links off the configured app host', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $url = app(CalendarFeedService::class)
        ->subscribeUrl('token-x');

    expect($url)->toContain('app.'.config('app.domain'))->toContain('/cal/');
});

// ---------------------------------------------------------------------------
// Env template + hardening defaults
// ---------------------------------------------------------------------------

it('documents every production key with safe values in .env.example', function () {
    $env = (string) file_get_contents(base_path('.env.example'));

    // The production block states the safe values explicitly.
    foreach ([
        'APP_ENV=production',
        'APP_DEBUG=false',
        'APP_URL=https://bookthestyle.com',
        'APP_DOMAIN=bookthestyle.com',
        'SESSION_DOMAIN=.bookthestyle.com',
        'SESSION_SECURE_COOKIE=true',
        'QUEUE_CONNECTION=database',
        'docs/DEPLOY.md',
    ] as $needle) {
        expect($env)->toContain($needle);
    }

    // Every key production needs is present in the template.
    foreach (['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'SESSION_DRIVER', 'CACHE_STORE', 'MAIL_MAILER', 'MAIL_FROM_ADDRESS', 'LOG_LEVEL'] as $key) {
        expect($env)->toContain($key.'=');
    }
});

it('keeps the deploy guide accurate: cron line, committed assets, no destructive schema commands', function () {
    $doc = (string) file_get_contents(base_path('docs/DEPLOY.md'));

    expect($doc)
        ->toContain('schedule:run')
        ->toContain('migrate --force')
        ->toContain('npm run build')
        ->toContain('app:factory-reset');
    expect($doc)->not->toContain('migrate:fresh');
});

// ---------------------------------------------------------------------------
// Safe initialisation: factory reset + destructive-command guard
// ---------------------------------------------------------------------------

it('factory reset accepts an explicit strong owner password', function () {
    $this->artisan('app:factory-reset', ['--force' => true, '--password' => 'Str0ng!Owner!Pass'])
        ->assertExitCode(0);

    $owner = User::sole();
    expect(Hash::check('Str0ng!Owner!Pass', $owner->password))->toBeTrue();
    expect(Hash::check('password', $owner->password))->toBeFalse();
});

it('factory reset in production generates a random password and forces a change at first login', function () {
    $this->app['env'] = 'production';

    $this->artisan('app:factory-reset', ['--force' => true])->assertExitCode(0);

    $owner = User::sole();
    expect(Hash::check('password', $owner->password))->toBeFalse();
    expect($owner->must_change_password)->toBeTrue();
    expect($owner->agency_role)->toBe(AgencyRole::Owner);
});

it('never ships a destructive schema command anywhere runnable', function () {
    // Nothing runnable invokes fresh/refresh/wipe…
    $runnable = [base_path('composer.json'), base_path('routes/console.php')];
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $workflow) {
        $runnable[] = $workflow;
    }

    foreach ($runnable as $path) {
        $content = (string) file_get_contents($path);
        foreach (['migrate:fresh', 'migrate:refresh', 'db:wipe'] as $forbidden) {
            expect(str_contains($content, $forbidden))->toBeFalse("{$forbidden} found in {$path}");
        }
    }

    // …and production refuses them outright even if typed by hand.
    expect((string) file_get_contents(app_path('Providers/AppServiceProvider.php')))
        ->toContain('prohibitDestructiveCommands');
});
