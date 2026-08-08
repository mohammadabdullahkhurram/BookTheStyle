<?php

use App\Actions\Salons\CreateSalon;
use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Diagnostics\ConnectionDiagnostics;
use App\Services\Health\HealthCheckRegistry;
use App\Services\Health\Heartbeat;
use App\Services\Reporting\SalonReport;
use App\Support\BookingApiToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| "Check connections": agency-operator-only diagnostics with disposable,
| LEAK-PROOF test records. The BTS side is tested automatically; the GHL
| side is verified by a manual round-trip — and the page says so honestly.
*/

function diagnosticsOperator(Salon $salon): User
{
    return User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Admin,
    ]);
}

/** An already-built salon: real staff, a real service, real hours. */
function builtSalon(): Salon
{
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, (int) CarbonImmutable::now($salon->timezone)->addDay()->format('N') - 1, 9 * 60, 17 * 60);
    serviceFor($salon, $stylist, 60);

    return $salon;
}

function fakeOutboundChecks(): void
{
    Http::fake([
        route('api.booking.availability') => Http::response(['success' => false, 'error' => 'unauthenticated'], 401),
        '*' => Http::response(['ok' => true], 200),
    ]);
}

it('opens for agency owner and admin only — every salon role, delegated users, guests, and demo are refused', function () {
    $salon = builtSalon();

    foreach ([AgencyRole::Owner, AgencyRole::Admin] as $role) {
        $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => $role]);
        $this->actingAs($operator)->get(route('salon.check-connections', $salon))
            ->assertOk()
            ->assertSee(__('Health check'));
    }

    // Salon roles — the salon's own OWNER included — are refused.
    foreach ([salonOwnerOf($salon), salonAdminOf($salon), stylistOf($salon)] as $actor) {
        $this->actingAs($actor)->get(route('salon.check-connections', $salon))->assertForbidden();
    }

    // A delegated agency_user is not an operator.
    $delegated = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::User]);
    $delegated->assignedSalons()->attach($salon->id);
    $this->actingAs($delegated)->get(route('salon.check-connections', $salon))->assertForbidden();

    // Demo salons are unreachable even for their own agency operator: the
    // demo slug never resolves as a tenant host (ResolveSalon refuses), and
    // mount 404s is_demo as belt-and-suspenders besides.
    $demo = demoShowcase();
    $demoOperator = User::factory()->create(['agency_id' => $demo->agency_id, 'agency_role' => AgencyRole::Owner]);
    $status = $this->actingAs($demoOperator)->get(route('salon.check-connections', $demo))->getStatusCode();
    expect(in_array($status, [403, 404], true))->toBeTrue();
});

it('requires the operator\'s own password before running — wrong password creates nothing', function () {
    $salon = builtSalon();
    $operator = diagnosticsOperator($salon);

    Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'not-my-password')
        ->call('run')
        ->assertHasErrors(['password']);

    expect(User::where('is_test', true)->exists())->toBeFalse();
    expect(Service::withoutGlobalScopes()->where('is_test', true)->exists())->toBeFalse();
});

it('runs on an already-built salon: creates the three test records, books a real test appointment, reports in plain language', function () {
    fakeOutboundChecks();
    $salon = builtSalon();
    BookingApiToken::generate($salon);
    $operator = diagnosticsOperator($salon);

    $component = Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertHasNoErrors();

    // The three disposable records, all flagged.
    $stylist = User::where('is_test', true)->where('name', ConnectionDiagnostics::STYLIST_NAME)->firstOrFail();
    $service = Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->firstOrFail();
    $client = Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->firstOrFail();
    expect($service->name)->toBe(ConnectionDiagnostics::SERVICE_NAME);
    expect($client->name)->toBe(ConnectionDiagnostics::CLIENT_NAME);
    expect($stylist->membershipFor($salon)->staff_type?->value)->toBe('stylist');

    // A REAL booking exists on the test stylist for the test client.
    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->where('client_id', $client->id)->exists())->toBeTrue();

    // The categorized report: all five categories, ✓/⚠/✗ lines with human
    // wording and fix hints, plus the top-line summary.
    $component->assertSeeInOrder([
        __('Integrations & Voice AI booking'),
        __('Notifications'),
        __('Scheduled jobs & queue'),
        __('Salon readiness'),
        __('System'),
    ])
        ->assertSee(__('Booking API token'))
        ->assertSee(__('Booking endpoint'))
        ->assertSee(__('Availability'))
        ->assertSee(__('Test booking'))
        ->assertSee(__('Inbound webhook secret'))
        ->assertSee(__('Booking widget'))
        ->assertSee(__('Email sending'))
        ->assertSee(__('Scheduler (cron)'))
        ->assertSee(__('Queue'))
        ->assertSee(__('Bookable staff'))
        ->assertSee(__('Database'))
        ->assertSee(__('the booking engine works end to end'), false)
        ->assertSee(__('checks passed'), false)
        // The honest split — GHL is round-trip, never claimed auto-tested.
        ->assertSee(__('GHL side — verified by round-trip'))
        ->assertSee(__('BookTheStyle cannot fire GHL\'s Custom Actions itself'))
        ->assertSee(ConnectionDiagnostics::SERVICE_NAME)
        ->assertSee(__('No call received since this check started.'));

    // Running again is idempotent — still exactly one of each record.
    $component->set('password', 'password')->call('run')->assertHasNoErrors();
    expect(Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->count())->toBe(1);
    expect(User::where('is_test', true)->where('email', 'like', 'diagnostics+%')->count())->toBe(1);
});

it('keeps test records leak-proof: widget, booking flow, client directory, and reports never show them', function () {
    fakeOutboundChecks();
    $salon = builtSalon();
    $operator = diagnosticsOperator($salon);
    $owner = salonOwnerOf($salon);

    $before = app(SalonReport::class)->build(
        $salon,
        CarbonImmutable::now($salon->timezone)->startOfMonth(),
        CarbonImmutable::now($salon->timezone)->endOfMonth(),
    );

    Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertHasNoErrors();

    $service = Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->firstOrFail();

    // Public widget: the catalogue never lists the test service or stylist…
    $this->getJson(route('salon.widget.services', ['salon' => $salon->slug]))
        ->assertOk()
        ->assertDontSee(ConnectionDiagnostics::SERVICE_NAME)
        ->assertDontSee(ConnectionDiagnostics::STYLIST_NAME);

    // …and a GUESSED test-service id behaves as nonexistent — a real client
    // cannot see or book the test stylist, full stop.
    $this->getJson(route('salon.widget.availability', ['salon' => $salon->slug]).'?service='.$service->id.'&date='.CarbonImmutable::now($salon->timezone)->addDay()->format('Y-m-d'))
        ->assertStatus(422)
        ->assertJsonPath('error', 'unknown_service');

    // STAFF surfaces show the records, badged TEST — the operator can see
    // and use them; only the public side is blind.
    $this->actingAs($owner)->get(route('salon.bookings.create', $salon))
        ->assertOk()
        ->assertSee(ConnectionDiagnostics::SERVICE_NAME);
    $this->actingAs($owner)->get(route('salon.clients', $salon))
        ->assertOk()
        ->assertSee(ConnectionDiagnostics::CLIENT_NAME)
        ->assertSee(__('TEST'));
    $this->actingAs($owner)->get(route('salon.users', $salon))
        ->assertOk()
        ->assertSee(ConnectionDiagnostics::STYLIST_NAME)
        ->assertSee(__('TEST'));

    // The AUTHENTICATED widget preview includes the test service — that is
    // how the operator walks the client booking flow safely.
    $this->actingAs($owner)
        ->getJson(route('salon.widget.preview.availability', ['salon' => $salon->slug]).'?service='.$service->id.'&date='.CarbonImmutable::now($salon->timezone)->addDay()->format('Y-m-d'))
        ->assertOk();

    // Reports: the test booking changed NOTHING.
    $after = app(SalonReport::class)->build(
        $salon,
        CarbonImmutable::now($salon->timezone)->startOfMonth(),
        CarbonImmutable::now($salon->timezone)->endOfMonth(),
    );
    expect($after['totals'] ?? $after)->toEqual($before['totals'] ?? $before);
});

it('greens the round-trip indicator when a genuinely authenticated API call arrives', function () {
    fakeOutboundChecks();
    $salon = builtSalon();
    $token = BookingApiToken::generate($salon);
    $operator = diagnosticsOperator($salon);

    $component = Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertSee(__('No call received since this check started.'));

    // GHL's side of the round-trip: a real authenticated call to the API.
    $this->postJson(route('api.booking.availability'), ['service' => ConnectionDiagnostics::SERVICE_NAME], [
        'Authorization' => 'Bearer '.$token,
    ])->assertOk();

    $component->call('$refresh')
        ->assertSee(__('the wiring works'), false)
        ->assertDontSee(__('No call received since this check started.'));
});

it('tears everything down on Finish — records and their appointments — and the sweep catches abandoned runs', function () {
    fakeOutboundChecks();
    $salon = builtSalon();
    $operator = diagnosticsOperator($salon);

    $component = Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertHasNoErrors();

    expect(Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->exists())->toBeTrue();

    $component->call('finish')->assertHasNoErrors();

    expect(Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->exists())->toBeFalse();
    expect(Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->exists())->toBeFalse();
    expect(User::withTrashed()->where('is_test', true)->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(0);

    // The sweep, expiry-first: an unexpired clock keeps the records…
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon);
    $this->artisan('diagnostics:sweep-test-records')->assertExitCode(0);
    expect(User::where('is_test', true)->exists())->toBeTrue(); // clock in the future — kept

    // …an elapsed clock removes them, appointments included.
    $salon->forceFill(['test_records_expire_at' => now()->subMinute()])->save();
    $this->artisan('diagnostics:sweep-test-records')->assertExitCode(0);
    expect(User::withTrashed()->where('is_test', true)->exists())->toBeFalse();
    expect(Service::withoutGlobalScopes()->where('is_test', true)->exists())->toBeFalse();
    expect($salon->refresh()->test_records_expire_at)->toBeNull();

    // Legacy fallback: records with NO clock at all are swept once old.
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon);
    $salon->forceFill(['test_records_expire_at' => null])->save();
    User::where('is_test', true)->update(['created_at' => now()->subHours(ConnectionDiagnostics::SWEEP_AFTER_HOURS + 1)]);
    $this->artisan('diagnostics:sweep-test-records')->assertExitCode(0);
    expect(User::withTrashed()->where('is_test', true)->exists())->toBeFalse();
});

it('exposes an extensible registry: every category has checks and the summary adds up', function () {
    fakeOutboundChecks();
    $salon = builtSalon();

    $report = app(HealthCheckRegistry::class)->run($salon);

    expect(array_column($report['categories'], 'key'))
        ->toBe(array_keys(HealthCheckRegistry::CATEGORIES));

    $lines = 0;
    foreach ($report['categories'] as $category) {
        expect(count($category['checks']))->toBeGreaterThanOrEqual(1);
        foreach ($category['checks'] as $line) {
            expect(in_array($line['status'], ['pass', 'warn', 'fail'], true))->toBeTrue();
            expect($line['message'])->not->toBe('');
            $lines++;
        }
    }

    expect($report['summary']['pass'] + $report['summary']['warn'] + $report['summary']['fail'])->toBe($lines);

    app(ConnectionDiagnostics::class)->teardown($salon);
});

it('surfaces deliberately broken conditions as plain-language failures', function () {
    fakeOutboundChecks();

    // A bare salon (owner only, no services/staff/hours) → readiness fails.
    $bare = Salon::factory()->create();
    salonOwnerOf($bare);
    $report = app(HealthCheckRegistry::class)->run($bare);
    $readiness = collect($report['categories'])->firstWhere('key', 'readiness')['checks'];

    $services = collect($readiness)->firstWhere('key', 'services');
    expect($services['status'])->toBe('fail');
    expect($services['message'])->toContain('no real services');
    expect($services['fix'])->toContain('SOP');

    $staff = collect($readiness)->firstWhere('key', 'bookable-staff');
    expect($staff['status'])->toBe('fail');
    expect($staff['message'])->toContain('Nobody real takes bookings');

    // A stale scheduler heartbeat → schedule fail with the cron hint.
    Heartbeat::beat(Heartbeat::SCHEDULER);
    Cache::put(Heartbeat::SCHEDULER, now()->subHour()->toIso8601String(), now()->addDay());
    $report = app(HealthCheckRegistry::class)->run($bare);
    $scheduler = collect(collect($report['categories'])->firstWhere('key', 'schedule')['checks'])->firstWhere('key', 'scheduler');
    expect($scheduler['status'])->toBe('fail');
    expect($scheduler['message'])->toContain('should tick every minute');
    expect($scheduler['fix'])->toContain('Cron Jobs');

    // A fresh heartbeat → pass.
    Heartbeat::beat(Heartbeat::SCHEDULER);
    $report = app(HealthCheckRegistry::class)->run($bare);
    $scheduler = collect(collect($report['categories'])->firstWhere('key', 'schedule')['checks'])->firstWhere('key', 'scheduler');
    expect($scheduler['status'])->toBe('pass');

    // A stuck queue (an old pending job) → queue fail in plain words.
    DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
        'reserved_at' => null, 'available_at' => now()->subMinutes(30)->timestamp, 'created_at' => now()->subMinutes(30)->timestamp,
    ]);
    $report = app(HealthCheckRegistry::class)->run($bare);
    $queue = collect(collect($report['categories'])->firstWhere('key', 'schedule')['checks'])->firstWhere('key', 'queue');
    expect($queue['status'])->toBe('fail');
    expect($queue['message'])->toContain('stuck');

    DB::table('jobs')->delete();
    app(ConnectionDiagnostics::class)->teardown($bare);
});

it('mutates nothing real: the only write is the test booking on test records', function () {
    fakeOutboundChecks();
    $salon = builtSalon();
    $operator = diagnosticsOperator($salon);

    $realServices = Service::withoutGlobalScopes()->where('is_test', false)->count();
    $realClients = Client::withoutGlobalScopes()->where('is_test', false)->count();
    $realUsers = User::where('is_test', false)->count();
    $realBookings = Booking::withoutGlobalScopes()
        ->whereDoesntHave('items.stylist', fn ($q) => $q->where('is_test', true))
        ->count();

    Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertHasNoErrors();

    // Real rows: byte-for-byte the same counts. The only new rows are the
    // flagged test records and the one test booking on the test stylist.
    expect(Service::withoutGlobalScopes()->where('is_test', false)->count())->toBe($realServices);
    expect(Client::withoutGlobalScopes()->where('is_test', false)->count())->toBe($realClients);
    expect(User::where('is_test', false)->count())->toBe($realUsers);
    expect(Booking::withoutGlobalScopes()
        ->whereDoesntHave('items.stylist', fn ($q) => $q->where('is_test', true))
        ->count())->toBe($realBookings);
});

it('auto-creates the test set when a salon is created — 48-hour setup clock; the demo showcase has none', function () {
    $agency = Agency::factory()->create();
    $actor = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    $salon = app(CreateSalon::class)->handle($actor, $agency, salonProfileInput([
        'name' => 'Fresh Cuts', 'slug' => 'fresh-cuts', 'timezone' => 'America/New_York',
        'owner_name' => 'Fresh Owner', 'owner_email' => 'fresh-owner@example.test',
    ]));

    // The operator can walk the client flow from minute one.
    expect(app(ConnectionDiagnostics::class)->hasTestRecords($salon))->toBeTrue();
    expect(Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->exists())->toBeTrue();

    // Setup records get the LONG clock (~48h), not the 60-minute run clock.
    $expiry = $salon->refresh()->test_records_expire_at;
    expect($expiry)->not->toBeNull();
    expect($expiry->diffInMinutes(now()->addHours(ConnectionDiagnostics::TTL_SETUP_HOURS), absolute: true))->toBeLessThan(5);

    // The demo showcase never carries test records.
    expect(app(ConnectionDiagnostics::class)->hasTestRecords(demoShowcase()))->toBeFalse();
});

it('resets the TTL clock to the short run window every time the health check runs', function () {
    fakeOutboundChecks();
    $salon = builtSalon();
    $operator = diagnosticsOperator($salon);

    // Pretend the setup clock is nearly out…
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon, expiresAt: now()->addMinutes(2));

    Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertHasNoErrors();

    // …the run pushed it back out to ~60 minutes.
    $expiry = $salon->refresh()->test_records_expire_at;
    expect($expiry->diffInMinutes(now()->addMinutes(ConnectionDiagnostics::TTL_RUN_MINUTES), absolute: true))->toBeLessThan(5);
});

it('offers Remove test data to the operator whenever test records linger — and the button removes them', function () {
    $salon = builtSalon();
    $operator = diagnosticsOperator($salon);

    // No records → no strip.
    Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->assertDontSee(__('Remove test data'));

    app(ConnectionDiagnostics::class)->ensureTestRecords($salon);

    $component = Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->assertSee(__('This salon currently has test data'))
        ->assertSee(__('Remove test data'));

    $component->call('finish')->assertHasNoErrors();

    expect(app(ConnectionDiagnostics::class)->hasTestRecords($salon->refresh()))->toBeFalse();
    expect($salon->test_records_expire_at)->toBeNull();
    expect(User::withTrashed()->where('is_test', true)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// The fixed 2:00 PM test appointment
// ---------------------------------------------------------------------------

/** The first upcoming 2:00 PM in the salon's timezone (the check's first choice). */
function firstUpcomingTwoPm(Salon $salon): CarbonImmutable
{
    $now = CarbonImmutable::now($salon->timezone);
    $today = $now->startOfDay()->setTime(14, 0);

    return $today->gt($now) ? $today : $today->addDay();
}

it('books the health-check test appointment at exactly 2:00 PM salon time — and reuses it on a re-run', function () {
    fakeOutboundChecks();
    $salon = builtSalon();
    BookingApiToken::generate($salon);
    $operator = diagnosticsOperator($salon);

    $component = Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertHasNoErrors();

    $client = Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->firstOrFail();
    $booking = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->where('client_id', $client->id)->with('items')->sole();

    $start = $booking->items->min('starts_at')->setTimezone($salon->timezone);
    expect($start->format('g:i A'))->toBe(ConnectionDiagnostics::TEST_BOOKING_TIME);
    expect($start->toDateString())->toBe(firstUpcomingTwoPm($salon)->toDateString());

    // Second run: the 2:00 PM is already held by the previous test run —
    // the idempotent engine REUSES it. Still ONE booking, still 2:00 PM.
    $component->set('password', 'password')->call('run')->assertHasNoErrors();
    $bookings = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->where('client_id', $client->id)->with('items')->get();
    expect($bookings)->toHaveCount(1);
    expect($bookings->first()->items->min('starts_at')->setTimezone($salon->timezone)->format('g:i A'))->toBe(ConnectionDiagnostics::TEST_BOOKING_TIME);
});

it('rolls to the NEXT day\'s 2:00 PM when the first choice is occupied by someone else — never another time', function () {
    fakeOutboundChecks();
    $salon = builtSalon();
    BookingApiToken::generate($salon);
    $operator = diagnosticsOperator($salon);

    // Occupy the first upcoming 2:00 PM on the test stylist with a booking
    // the idempotent create cannot match (different client).
    $records = app(ConnectionDiagnostics::class)->ensureTestRecords($salon);
    $blocked = firstUpcomingTwoPm($salon);
    makeBooking($salon, salonOwnerOf($salon), $records['stylist'], $records['service'], $blocked->format('Y-m-d H:i'), 'Blocker Bob');

    Livewire::actingAs($operator)
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->set('password', 'password')
        ->call('run')
        ->assertHasNoErrors();

    $client = Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->firstOrFail();
    $booking = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->where('client_id', $client->id)->with('items')->sole();
    $start = $booking->items->min('starts_at')->setTimezone($salon->timezone);

    // Still exactly 2:00 PM — on the NEXT day, not another time on the first.
    expect($start->format('g:i A'))->toBe(ConnectionDiagnostics::TEST_BOOKING_TIME);
    expect($start->toDateString())->toBe($blocked->addDay()->toDateString());
});
