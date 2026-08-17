<?php

use App\Enums\AgencyRole;
use App\Mail\HealthAlertMail;
use App\Models\Booking;
use App\Models\Client;
use App\Models\HealthCheckRun;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\User;
use App\Services\Health\Checks\BookingsWithoutStylist;
use App\Services\Health\Checks\GhlConnectionLive;
use App\Services\Health\Checks\ServicesWithoutPrice;
use App\Services\Health\Checks\ServicesWithoutStylists;
use App\Services\Health\Checks\StylistsWithoutHours;
use App\Services\Health\Checks\SubdomainSsl;
use App\Services\Health\HealthCheckRegistry;
use App\Services\Health\HealthContext;
use App\Services\Health\HealthMonitor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| Health monitoring: the new SSL / GHL-live / data-integrity checks, the
| recorded run history with its green→red alert, and the scheduled
| read-only monitor. The alert goes to agency owners/admins ONLY — never
| salon staff, never clients.
*/

function monitorOperator(Salon $salon): User
{
    return User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Admin,
    ]);
}

/** A built salon: real stylist with hours, real priced service. */
function monitoredSalon(array $overrides = []): Salon
{
    $salon = bookingSalon($overrides);
    $stylist = stylistWithHours($salon, (int) CarbonImmutable::now($salon->timezone)->addDay()->format('N') - 1, 9 * 60, 17 * 60);
    serviceFor($salon, $stylist, 60)->forceFill(['price_cents' => 5000])->save();

    return $salon;
}

function fakeMonitorHttp(): void
{
    Http::fake([
        route('api.booking.availability') => Http::response(['success' => false, 'error' => 'unauthenticated'], 401),
        '*' => Http::response(['ok' => true], 200),
    ]);
}

/** A minimal synthetic report for driving HealthMonitor directly. */
function syntheticReport(string $status): array
{
    return [
        'categories' => [[
            'key' => 'integrations', 'label' => 'Integrations',
            'checks' => [[
                'key' => 'widget', 'label' => 'Booking widget', 'status' => $status,
                'message' => $status === 'pass' ? 'answers' : 'did not answer', 'fix' => null,
            ]],
        ]],
        'summary' => [
            'pass' => $status === 'pass' ? 1 : 0,
            'warn' => $status === 'warn' ? 1 : 0,
            'fail' => $status === 'fail' ? 1 : 0,
        ],
    ];
}

it('includes the new SSL, GHL-live, and data-integrity checks in the report', function () {
    fakeMonitorHttp();
    $salon = monitoredSalon();

    $report = app(HealthCheckRegistry::class)->run($salon);

    expect(array_column($report['categories'], 'key'))->toContain('integrity');

    $keys = collect($report['categories'])->flatMap(fn ($c) => array_column($c['checks'], 'key'));
    expect($keys)->toContain('subdomain-ssl')
        ->toContain('ghl-live')
        ->toContain('bookings-without-stylist')
        ->toContain('services-without-stylists')
        ->toContain('services-without-price')
        ->toContain('stylists-without-hours');
});

it('flags data-integrity problems in plain language, naming the affected items', function () {
    $salon = monitoredSalon();
    $context = new HealthContext($salon);

    // An active service nobody performs → fail, named.
    Service::factory()->create(['salon_id' => $salon->id, 'name' => 'Ghost Blowout', 'active' => true, 'price_cents' => 4000]);
    $result = app(ServicesWithoutStylists::class)->run($context);
    expect($result->status->value)->toBe('fail');
    expect($result->message)->toContain('Ghost Blowout')->toContain('never be booked');

    // An active service with no price → warn, named, "price varies" honesty.
    $noPrice = serviceFor($salon, stylistWithHours($salon, 2, 9 * 60, 17 * 60), 30);
    $noPrice->forceFill(['name' => 'Mystery Cut', 'price_cents' => null])->save();
    $result = app(ServicesWithoutPrice::class)->run($context);
    expect($result->status->value)->toBe('warn');
    expect($result->message)->toContain('Mystery Cut')->toContain('price varies');

    // A bookable stylist with no hours at all → warn, named.
    $hourless = User::factory()->create(['name' => 'Hourless Hannah']);
    stylistOf($salon, $hourless);
    $result = app(StylistsWithoutHours::class)->run($context);
    expect($result->status->value)->toBe('warn');
    expect($result->message)->toContain('Hourless Hannah')->toContain('never book');

    // An upcoming appointment whose stylist's membership was switched off → fail.
    $target = CarbonImmutable::now($salon->timezone)->addDays(2);
    $stylist = stylistWithHours($salon, (int) $target->format('N') - 1, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    makeBooking($salon, salonOwnerOf($salon), $stylist, $service, $target->setTime(10, 0)->format('Y-m-d H:i'));
    $salon->memberships()->where('user_id', $stylist->id)->update(['active' => false]);
    $result = app(BookingsWithoutStylist::class)->run($context);
    expect($result->status->value)->toBe('fail');
    expect($result->message)->toContain('nobody is assigned')->toContain($stylist->name);
});

it('verifies the salon address end to end: valid HTTPS passes, broken cert / missing DNS / 525 fail with the hPanel hint', function () {
    config(['app.url' => 'https://bookthestyle.com']);
    $salon = monitoredSalon();
    $context = new HealthContext($salon);
    $check = app(SubdomainSsl::class);

    // Any HTTP answer over verified TLS proves the address works.
    Http::fake(['*' => Http::response('ok', 200)]);
    expect($check->run($context)->status->value)->toBe('pass');

    // Cloudflare 525/526 = the origin certificate is broken for visitors.
    // (Http::fake stacks, so swap in a fresh factory between scenarios.)
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('', 525)]);
    $result = $check->run($context);
    expect($result->status->value)->toBe('fail');
    expect($result->message)->toContain('525');
    expect($result->fix)->toContain('hPanel');

    // A TLS failure on the way out.
    Http::swap(new Factory);
    Http::fake(fn () => throw new ConnectionException('SSL certificate problem: self-signed certificate'));
    $result = $check->run($context);
    expect($result->status->value)->toBe('fail');
    expect($result->message)->toContain('certificate');

    // DNS: the subdomain does not exist.
    Http::swap(new Factory);
    Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));
    $result = $check->run($context);
    expect($result->status->value)->toBe('fail');
    expect($result->message)->toContain('does not resolve');

    // Local/non-HTTPS environments say so honestly instead of failing.
    config(['app.url' => 'http://localhost']);
    expect($check->run($context)->status->value)->toBe('warn');
});

it('tests the GHL side with a real API call: alive passes, a revoked token fails, unconfigured warns', function () {
    $salon = monitoredSalon();
    $context = new HealthContext($salon);
    $check = app(GhlConnectionLive::class);

    // No connection at all → honest warn, not a failure.
    expect($check->run($context)->status->value)->toBe('warn');

    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_123',
        'private_integration_token' => 'pit-live-check',
    ]);
    $salon->refresh();

    // GHL answers the salon's token → the link is alive.
    Http::fake(['*/calendars/*' => Http::response(['calendars' => [['id' => 'cal_1', 'name' => 'Master']]], 200)]);
    $result = $check->run(new HealthContext($salon));
    expect($result->status->value)->toBe('pass');
    expect($result->message)->toContain('alive');

    // GHL refuses (revoked/rotated token) → fail, in plain words.
    Http::swap(new Factory);
    Http::fake(['*/calendars/*' => Http::response(['message' => 'unauthorized'], 401)]);
    $result = $check->run(new HealthContext($salon));
    expect($result->status->value)->toBe('fail');
    expect($result->message)->toContain('DOWN');
    expect($result->fix)->toContain('Private Integration Token');
});

it('records every manual run and shows the history view on the page', function () {
    fakeMonitorHttp();
    $salon = monitoredSalon();
    $operator = monitorOperator($salon);

    Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertHasNoErrors()
        ->assertSee(__('Last checked & history'))
        ->assertSee(__('Manual run'));

    $run = HealthCheckRun::query()->where('salon_id', $salon->id)->sole();
    expect($run->source)->toBe(HealthMonitor::SOURCE_MANUAL);
    expect($run->pass_count + $run->warn_count + $run->fail_count)->toBeGreaterThan(10);
    expect($run->results)->toHaveKey('widget');
});

it('fires the green→red alert to agency owners and admins only — once, on the run that flipped', function () {
    Mail::fake();
    $salon = monitoredSalon();
    $admin = monitorOperator($salon);
    $agencyOwner = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Owner]);
    $salonOwner = salonOwnerOf($salon);
    $monitor = app(HealthMonitor::class);

    // Green… then red: the regression is stored and the alert goes out.
    $monitor->record($salon, syntheticReport('pass'), HealthMonitor::SOURCE_SCHEDULED);
    $red = $monitor->record($salon, syntheticReport('fail'), HealthMonitor::SOURCE_SCHEDULED);

    expect($red->regressions)->toHaveCount(1);
    expect($red->regressions[0]['label'])->toBe('Booking widget');
    expect($red->regressions[0]['was'])->toBe('pass');

    Mail::assertSent(HealthAlertMail::class, fn (HealthAlertMail $mail) => $mail->hasTo($admin->email));
    Mail::assertSent(HealthAlertMail::class, fn (HealthAlertMail $mail) => $mail->hasTo($agencyOwner->email));
    Mail::assertSent(HealthAlertMail::class, 2); // …and NOBODY else — not salon staff, never clients
    Mail::assertNotSent(HealthAlertMail::class, fn (HealthAlertMail $mail) => $mail->hasTo($salonOwner->email));

    // Still failing on the next run → no repeat alert; recovery then a new
    // flip alerts again.
    $stillRed = $monitor->record($salon, syntheticReport('fail'), HealthMonitor::SOURCE_SCHEDULED);
    expect($stillRed->regressions)->toBeNull();
    Mail::assertSent(HealthAlertMail::class, 2);

    $monitor->record($salon, syntheticReport('pass'), HealthMonitor::SOURCE_SCHEDULED);
    $monitor->record($salon, syntheticReport('fail'), HealthMonitor::SOURCE_SCHEDULED);
    Mail::assertSent(HealthAlertMail::class, 4);
});

it('runs the scheduled monitor read-only: live salons recorded, nothing mutated, no test records, setup and demo salons skipped', function () {
    Mail::fake();
    fakeMonitorHttp();

    $live = monitoredSalon(['onboarded_at' => now()->subWeek()]);
    $inSetup = monitoredSalon();
    $demo = demoShowcase();
    monitorOperator($live);

    $before = [
        'users' => User::withTrashed()->count(),
        'services' => Service::withoutGlobalScopes()->count(),
        'clients' => Client::withoutGlobalScopes()->count(),
        'bookings' => Booking::withoutGlobalScopes()->count(),
    ];

    $this->artisan('health:monitor')->assertExitCode(0);

    // The live salon got a recorded scheduled run; the others were skipped.
    $run = HealthCheckRun::query()->where('salon_id', $live->id)->sole();
    expect($run->source)->toBe(HealthMonitor::SOURCE_SCHEDULED);
    expect(HealthCheckRun::query()->where('salon_id', $inSetup->id)->exists())->toBeFalse();
    expect(HealthCheckRun::query()->where('salon_id', $demo->id)->exists())->toBeFalse();

    // The monitor skipped the test-record checks entirely…
    expect($run->results)->not->toHaveKey('test-booking');
    expect($run->results)->not->toHaveKey('availability');
    expect($run->results)->toHaveKey('subdomain-ssl');

    // …and mutated NOTHING: no test records, no TTL clock, no new rows of
    // any kind, no mail (nothing regressed on a first run).
    expect(User::where('is_test', true)->exists())->toBeFalse();
    expect(Service::withoutGlobalScopes()->where('is_test', true)->exists())->toBeFalse();
    expect($live->refresh()->test_records_expire_at)->toBeNull();
    expect(User::withTrashed()->count())->toBe($before['users']);
    expect(Service::withoutGlobalScopes()->count())->toBe($before['services']);
    expect(Client::withoutGlobalScopes()->count())->toBe($before['clients']);
    expect(Booking::withoutGlobalScopes()->count())->toBe($before['bookings']);
    Mail::assertNothingOutgoing();
});

it('keeps the tab agency-operator-only with history present in its Settings home', function () {
    $salon = monitoredSalon();
    app(HealthMonitor::class)->record($salon, syntheticReport('pass'), HealthMonitor::SOURCE_SCHEDULED);

    // Salon roles: no tab, and the component refuses a direct mount.
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->assertForbidden();
    Livewire::actingAs(stylistOf($salon))
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->assertForbidden();

    // The operator sees the history inside the Settings tab.
    $this->actingAs(monitorOperator($salon))->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertSee(__('Last checked & history'))
        ->assertSee(__('Auto monitor'));
});
