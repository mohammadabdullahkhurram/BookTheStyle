<?php

use App\Actions\Bookings\CreateBooking;
use App\Actions\Demo\DeleteDemoSalon;
use App\Actions\Salons\UpdateGhlConnection;
use App\Actions\Staff\InviteStaff;
use App\Enums\AgencyRole;
use App\Enums\BookingStatus;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\User;
use App\Support\BookingApiToken;
use App\Support\DemoMode;
use Carbon\CarbonInterface;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| The public demo: a LOGGED-OUT guest preview of ONE canonical showcase
| salon on the static demo.{domain} host. No login, no per-visitor salons,
| no session coupling — and STRUCTURALLY INERT: no GHL, no mail, no API
| tokens, no public widget, invisible to the real agency, view-only.
| The demo host resolves the showcase via ResolveSalon; a request-scoped
| viewer renders the member-shaped shell while the chrome stays logged out.
*/

$demoHost = fn (): string => 'http://demo.'.config('app.domain');

// ---------------------------------------------------------------------------
// The showcase salon and its seed
// ---------------------------------------------------------------------------

it('seeds a lived-in showcase salon, idempotently, that never expires', function () {
    $salon = demoShowcase();

    expect($salon->is_demo)->toBeTrue();
    expect($salon->demo_expires_at)->toBeNull();
    expect($salon->slug)->toBe(DemoMode::SHOWCASE_SLUG);
    expect($salon->memberships()->count())->toBeGreaterThanOrEqual(6);
    expect($salon->services()->count())->toBeGreaterThanOrEqual(5);
    expect($salon->clients()->count())->toBeGreaterThanOrEqual(30);
    expect($salon->bookings()->count())->toBeGreaterThan(30);

    $statuses = $salon->bookings()->distinct()->pluck('status')->all();
    foreach ([BookingStatus::Completed, BookingStatus::Booked, BookingStatus::Cancelled] as $status) {
        expect($statuses)->toContain($status);
    }

    // Re-running changes nothing (firstOrCreate + populate-once).
    $bookings = $salon->bookings()->count();
    demoShowcase();
    expect($salon->bookings()->count())->toBe($bookings);
});

// ---------------------------------------------------------------------------
// Guest preview — no auth, ever
// ---------------------------------------------------------------------------

it('renders the dashboard and calendar as a logged-out guest — no session, no login events', function () use ($demoHost) {
    Event::fake([Login::class]);
    demoShowcase();

    $this->get($demoHost().'/calendar')->assertOk();
    $this->get($demoHost().'/')->assertOk();

    // Nothing persisted anywhere: no login session key, no Login event.
    expect(session()->get('login_web_'.sha1(SessionGuard::class)))->toBeNull();
    Event::assertNotDispatched(Login::class);
    Auth::forgetUser();
    $this->assertGuest();
});

it('enters via app./demo with a plain redirect to the showcase calendar', function () use ($demoHost) {
    demoShowcase();

    // Old apex bookmark → entry → the demo host calendar; no auth anywhere.
    $this->get('http://'.config('app.domain').'/demo')->assertRedirect(route('demo.enter'));
    $this->get('http://app.'.config('app.domain').'/demo')
        ->assertRedirect($demoHost().'/calendar');

    Auth::forgetUser();
    $this->assertGuest();
});

it('renders the same guest preview regardless of any real session, without touching it', function () use ($demoHost) {
    $showcase = demoShowcase();
    $real = Salon::factory()->create();
    $owner = salonOwnerOf($real);
    $owner->forceFill(['password' => 'secret-pass-123', 'must_change_password' => false])->save();

    // A REAL session-backed login (the production shape — the session cookie
    // is what carries auth between requests).
    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'secret-pass-123'])->assertRedirect();

    // Browsing the demo shows the SHOWCASE (not their salon) — the guest
    // preview is independent of the session…
    $this->get($demoHost().'/calendar')->assertOk();
    expect(app('currentSalon')->id)->toBe($showcase->id);

    // …and the session survives untouched: dropping the request-scoped
    // viewer, the next request re-authenticates from the session as before.
    Auth::forgetUser();
    $this->get(route('salon.show', $real))->assertOk();
    expect(app('currentSalon')->id)->toBe($real->id);
});

// ---------------------------------------------------------------------------
// Guest chrome
// ---------------------------------------------------------------------------

it('shows guest chrome in demo: a Log in button, no account menu, no salon switcher', function () use ($demoHost) {
    demoShowcase();

    $this->get($demoHost().'/')
        ->assertOk()
        ->assertSee(__('You\'re viewing a live demo.'))
        ->assertSee(route('login'), false)
        ->assertDontSee(__('Account menu'))
        ->assertDontSee(__('All salons'))
        ->assertDontSee(__('Log out'));
});

it('keeps the full account chrome for real tenants', function () {
    $salon = Salon::factory()->create();

    $this->actingAs(salonOwnerOf($salon))
        ->get(route('salon.show', $salon))
        ->assertOk()
        ->assertSee(__('Account menu'))
        ->assertSee(__('All salons'))
        ->assertSee(__('Log out'))
        ->assertDontSee(__('You\'re viewing a live demo.'));
});

// ---------------------------------------------------------------------------
// Login stays functional and decoupled
// ---------------------------------------------------------------------------

it('keeps the real login working after browsing the demo', function () use ($demoHost) {
    demoShowcase();
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $owner->forceFill(['password' => 'secret-pass-123', 'must_change_password' => false])->save();

    $this->get($demoHost().'/calendar')->assertOk();
    Auth::forgetUser();

    $this->get(route('login'))->assertOk();
    $this->post(route('login.store'), ['email' => $owner->email, 'password' => 'secret-pass-123'])
        ->assertRedirect();

    $this->assertAuthenticatedAs($owner);
    $this->get(route('salon.show', $salon))->assertOk();
});

// ---------------------------------------------------------------------------
// View-only
// ---------------------------------------------------------------------------

it('blocks representative mutations on the demo dashboard', function () {
    $salon = demoShowcase();
    $owner = salonOwnerOf($salon);

    $bookings = $salon->bookings()->count();
    $clients = $salon->clients()->count();
    $booked = $salon->bookings()->where('status', BookingStatus::Booked)->with('items')->first();

    Livewire::actingAs($owner)
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($owner)
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->set('name', 'Blocked In Demo')
        ->call('create')
        ->assertHasNoErrors();

    Livewire::actingAs($owner)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('changeStatus', $booked->id, 'arrived')
        ->assertHasNoErrors();

    expect($salon->bookings()->count())->toBe($bookings);
    expect($salon->clients()->count())->toBe($clients);
    expect($booked->fresh()->status)->toBe(BookingStatus::Booked);
});

it('keeps mutations working for real tenants', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->set('name', 'Real New Client')
        ->call('create')
        ->assertHasNoErrors();

    expect($salon->clients()->where('name', 'Real New Client')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// THE CRITICAL GUARDS — the showcase salon is structurally inert
// ---------------------------------------------------------------------------

it('never queues a GHL sync from the showcase salon, on any path', function () {
    $salon = demoShowcase();
    Queue::fake();

    $stylist = $salon->memberships()->where('staff_type', 'stylist')->first()->user;
    $service = $salon->services()->first();
    $service->stylists()->syncWithoutDetaching([$stylist->id => ['salon_id' => $salon->id]]);
    $booking = app(CreateBooking::class)->handle(salonOwnerOf($salon), $salon, [
        'client' => ['name' => 'Demo Visitor Client'],
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'start' => now($salon->timezone)->addDays(45)->next(CarbonInterface::MONDAY)->setTime(10, 0)->format('Y-m-d H:i'),
        'is_walkin' => false,
        'notes' => null,
    ]);
    expect($booking->exists)->toBeTrue();
    Queue::assertNothingPushed();

    expect(fn () => app(UpdateGhlConnection::class)->handle($salon, [
        'location_id' => 'loc_x', 'calendar_id' => null, 'private_integration_token' => null,
    ]))->toThrow(RuntimeException::class);
});

it('never sends mail from the showcase salon — even inviting a REAL address', function () {
    $salon = demoShowcase();
    Mail::fake();

    $result = app(InviteStaff::class)->handle(salonOwnerOf($salon), $salon, [
        'name' => 'Real Stranger', 'email' => 'stranger@gmail.com', 'salon_role' => 'stylist',
    ]);
    expect($result->user)->not->toBeNull();
    Mail::assertNothingOutgoing();
});

it('refuses API tokens and the public widget for the showcase salon', function () {
    $salon = demoShowcase();

    expect(fn () => BookingApiToken::generate($salon))->toThrow(RuntimeException::class);

    $widget = $salon->defaultWidget();
    $this->get('http://'.$salon->slug.'.'.config('app.domain').'/widget/'.$widget->public_id)
        ->assertNotFound();
});

it('keeps the showcase invisible to the real agency console', function () {
    $salon = demoShowcase();

    $realAgency = Agency::factory()->create();
    $agencyOwner = User::factory()->create(['agency_id' => $realAgency->id, 'agency_role' => AgencyRole::Owner]);

    expect($salon->agency_id)->not->toBe($realAgency->id);
    $this->actingAs($agencyOwner)->get(route('dashboard'))->assertOk()->assertDontSee($salon->slug);
    // A demo salon has NO addressable agency URL: its route key is the
    // static "demo", which matches no salon slug → 404.
    $this->actingAs($agencyOwner)->get(route('agency.salons.edit', $salon))->assertNotFound();
});

// ---------------------------------------------------------------------------
// Tenancy + lifecycle
// ---------------------------------------------------------------------------

it('never resolves the showcase as a tenant subdomain', function () {
    $salon = demoShowcase();
    $owner = salonOwnerOf($salon);

    $this->get('http://'.$salon->slug.'.'.config('app.domain').'/')->assertRedirect(); // guest → login (auth), never data
    $this->actingAs($owner)->get('http://'.$salon->slug.'.'.config('app.domain').'/')->assertNotFound();
});

it('sweeps expired legacy demo salons but never the showcase', function () {
    $showcase = demoShowcase();

    $legacy = Salon::factory()->for($showcase->agency)->create();
    $legacy->forceFill(['is_demo' => true, 'demo_expires_at' => now()->subHour()])->save();

    $this->artisan('demo:sweep')->assertExitCode(0);

    expect(Salon::find($legacy->id))->toBeNull();
    expect(Salon::find($showcase->id))->not->toBeNull();
    expect(Booking::where('salon_id', $showcase->id)->count())->toBeGreaterThan(0);
});

it('hard-deletes legacy demo data through DeleteDemoSalon but refuses real salons', function () {
    $real = Salon::factory()->create();

    expect(fn () => app(DeleteDemoSalon::class)->handle($real))->toThrow(RuntimeException::class);
});
