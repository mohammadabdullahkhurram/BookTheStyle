<?php

use App\Actions\Bookings\CreateBooking;
use App\Actions\Demo\ProvisionDemoSalon;
use App\Actions\Salons\UpdateGhlConnection;
use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResetStaffPassword;
use App\Enums\AgencyRole;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\SalonType;
use App\Jobs\SyncAvailabilityToGhl;
use App\Jobs\SyncBookingToGhl;
use App\Jobs\SyncClientToGhl;
use App\Jobs\SyncGhlCalendarSlotSettings;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\User;
use App\Support\BookingApiToken;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

/*
| The public demo: per-visitor isolated salons (never shared), session-bound,
| auto-authenticated, rate-limited, expiring — and STRUCTURALLY INERT: no
| GHL, no mail, no API tokens, no public widget, invisible to the real
| agency. The inertness guards are the part that must never break.
*/

function demoSalonOf($response): Salon
{
    $location = $response->headers->get('Location');
    preg_match('#//([a-z0-9-]+)\.#', (string) $location, $m);

    return Salon::query()->where('slug', $m[1])->firstOrFail();
}

it('provisions an isolated, populated demo salon and signs the visitor in — no auth', function () {
    $response = $this->get('http://'.config('app.domain').'/demo');

    $response->assertRedirect();
    $salon = demoSalonOf($response);

    expect($salon->is_demo)->toBeTrue();
    expect($salon->salon_type)->toBe(SalonType::Mix);
    expect($salon->demo_expires_at)->not->toBeNull();
    expect($salon->agency->is_demo)->toBeTrue();
    $this->assertAuthenticated();
    expect(auth()->user()->membershipFor($salon))->not->toBeNull();

    // Lived-in, not empty: stylists, services, a real client book, and a
    // calendar with statuses + sources spread over past/today/future.
    expect($salon->memberships()->count())->toBeGreaterThanOrEqual(6);
    expect($salon->services()->count())->toBeGreaterThanOrEqual(5);
    expect($salon->clients()->count())->toBeGreaterThanOrEqual(30);
    expect($salon->bookings()->count())->toBeGreaterThan(30);

    $statuses = $salon->bookings()->distinct()->pluck('status')->all();
    foreach ([BookingStatus::Completed, BookingStatus::Booked, BookingStatus::Cancelled, BookingStatus::NoShow] as $status) {
        expect($statuses)->toContain($status);
    }
    $sources = $salon->bookings()->distinct()->pluck('source')->all();
    foreach ([BookingSource::InApp, BookingSource::VoiceAi, BookingSource::WebWidget] as $source) {
        expect($sources)->toContain($source);
    }

    // Today looks like a working day, someone mid-flow.
    $dayStart = now($salon->timezone)->startOfDay()->utc();
    $today = $salon->bookings()->whereHas('items', fn ($q) => $q
        ->whereBetween('starts_at', [$dayStart, $dayStart->addDay()]))->get();
    expect($today->count())->toBeGreaterThanOrEqual(3);
    expect($today->pluck('status')->all())->toContain(BookingStatus::Arrived);

    // The visitor can actually open their salon.
    $this->get(route('salon.show', $salon))->assertOk();
});

it('gives a second visitor a different salon, sealed off from the first', function () {
    $first = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));

    // Fresh visitor: new session, new identity.
    $this->post(route('logout'));
    $this->flushSession();

    $second = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));

    expect($second->id)->not->toBe($first->id);
    // Visitor #2 cannot open visitor #1's salon.
    $this->get(route('salon.show', $first))->assertForbidden();
});

it('keeps the same salon across a refresh (session-bound), and reset reprovisions', function () {
    $salon = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));

    $again = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));
    expect($again->id)->toBe($salon->id);

    $response = $this->post(route('salon.demo.reset', $salon));
    $fresh = demoSalonOf($response);
    expect($fresh->id)->not->toBe($salon->id);
    expect(Salon::find($salon->id))->toBeNull(); // the old one is gone
});

it('rate-limits provisioning per IP and enforces the global cap', function () {
    foreach (range(1, 3) as $i) {
        $this->get('http://'.config('app.domain').'/demo')->assertRedirect();
        $this->post(route('logout'));
        $this->flushSession();
    }

    $this->get('http://'.config('app.domain').'/demo')->assertStatus(429);

    // The global ceiling turns visitors away independently of per-IP limits.
    RateLimiter::clear('demo-provision:127.0.0.1');
    $agency = Agency::firstOrCreate(['is_demo' => true], ['name' => 'BookTheStyle demo']);
    Salon::factory()->count(ProvisionDemoSalon::MAX_ACTIVE)->for($agency)->create()
        ->each(fn (Salon $salon) => $salon->forceFill(['is_demo' => true, 'demo_expires_at' => now()->addHour()])->save());

    $this->flushSession();
    $this->get('http://'.config('app.domain').'/demo')->assertStatus(503);
});

// ---------------------------------------------------------------------------
// THE CRITICAL GUARDS — a demo salon is structurally inert
// ---------------------------------------------------------------------------

it('never queues a GHL sync from a demo salon, on any path', function () {
    $salon = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));
    Queue::fake();

    // Booking path: create a booking as the demo owner via the real action.
    $stylist = $salon->memberships()->where('staff_type', 'stylist')->first()->user;
    $service = $salon->services()->first();
    $service->stylists()->syncWithoutDetaching([$stylist->id => ['salon_id' => $salon->id]]);
    $booking = app(CreateBooking::class)->handle(auth()->user(), $salon, [
        'client' => ['name' => 'Demo Visitor Client'],
        'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
        'start' => now($salon->timezone)->addDays(45)->next(CarbonInterface::MONDAY)->setTime(10, 0)->format('Y-m-d H:i'),
        'is_walkin' => false,
        'notes' => null,
    ]);
    expect($booking->exists)->toBeTrue();
    Queue::assertNotPushed(SyncBookingToGhl::class);

    // Client + availability + slot-settings paths.
    SyncClientToGhl::queueFor($salon->clients()->firstOrFail());
    SyncAvailabilityToGhl::queueForStylist($salon, $stylist->id);
    expect(SyncAvailabilityToGhl::queueForSalon($salon))->toBe(0);
    SyncGhlCalendarSlotSettings::queueFor($salon);
    Queue::assertNothingPushed();

    // A GHL connection can never even be created.
    expect(fn () => app(UpdateGhlConnection::class)->handle($salon, [
        'location_id' => 'loc_x', 'calendar_id' => null, 'private_integration_token' => null,
    ]))->toThrow(RuntimeException::class);
});

it('never sends mail from a demo salon — even inviting a REAL email address', function () {
    $salon = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));
    Mail::fake();

    // The demo visitor invites a stranger's real address: nothing goes out.
    $result = app(InviteStaff::class)->handle(auth()->user(), $salon, [
        'name' => 'Real Stranger', 'email' => 'stranger@gmail.com', 'salon_role' => 'stylist',
    ]);
    expect($result->user)->not->toBeNull();
    Mail::assertNothingOutgoing();

    // Admin password reset: silent too.
    $membership = $salon->memberships()->where('user_id', $result->user->id)->firstOrFail();
    app(ResetStaffPassword::class)->handle(auth()->user(), $salon, $membership);
    Mail::assertNothingOutgoing();
});

it('refuses API tokens and the public widget for demo salons', function () {
    $salon = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));

    expect(fn () => BookingApiToken::generate($salon))->toThrow(RuntimeException::class);

    // Even a forged token for a demo salon id resolves to nothing.
    $forged = 'btsk_'.$salon->id.'_'.str_repeat('a', 40);
    $salon->forceFill(['api_token_hash' => hash('sha256', $forged)])->saveQuietly();
    expect(BookingApiToken::resolveSalon($forged))->toBeNull();

    // The public widget surface 404s.
    $widget = $salon->defaultWidget();
    $this->get('http://'.$salon->slug.'.'.config('app.domain').'/widget/'.$widget->public_id)
        ->assertNotFound();
    $this->getJson('http://'.$salon->slug.'.'.config('app.domain').'/api/widget/services')
        ->assertNotFound();
});

it('keeps demo salons invisible to the real agency console and reporting', function () {
    $salon = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));
    $this->post(route('logout'));

    $realAgency = Agency::factory()->create();
    $agencyOwner = User::factory()->create(['agency_id' => $realAgency->id, 'agency_role' => AgencyRole::Owner]);

    // Different agency ⇒ the existing tenancy scoping excludes it everywhere.
    expect($salon->agency_id)->not->toBe($realAgency->id);
    expect($realAgency->salons()->whereKey($salon->id)->exists())->toBeFalse();

    $this->actingAs($agencyOwner)->get(route('dashboard'))->assertOk()->assertDontSee($salon->slug);
    Livewire\Livewire::actingAs($agencyOwner)->test('pages::agency.users.index')
        ->assertDontSee('Olivia Owner');
    $this->actingAs($agencyOwner)->get(route('agency.salons.edit', $salon))->assertForbidden();
});

it('sweeps expired demos — salon, data, and accounts all hard-deleted', function () {
    $salon = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));
    $userIds = $salon->memberships()->pluck('user_id');
    expect($salon->bookings()->count())->toBeGreaterThan(0);

    $this->travel(ProvisionDemoSalon::TTL_HOURS + 1)->hours();
    $this->artisan('demo:sweep')->assertExitCode(0);

    expect(Salon::find($salon->id))->toBeNull();
    expect(Booking::where('salon_id', $salon->id)->count())->toBe(0);
    expect(User::withTrashed()->whereIn('id', $userIds)->count())->toBe(0);
});

it('shows the demo banner with reset and CTA inside a demo salon only', function () {
    $salon = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));

    $html = $this->get(route('salon.show', $salon))->assertOk()->getContent();
    expect($html)->toContain(e(__('You\'re in the demo.'))); // blade-escaped apostrophe
    expect($html)->toContain(__('Reset demo'));
    expect($html)->toContain(__('Book a call'));
    expect($html)->toContain('Refreshing keeps your session');

    // Real salons never see it.
    $real = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($real))
        ->get(route('salon.show', $real))
        ->assertOk()
        ->assertDontSee(__('Reset demo'));
});

it('refuses the reset endpoint for non-demo salons and foreign sessions', function () {
    $real = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($real))
        ->post(route('salon.demo.reset', $real))
        ->assertForbidden();

    $salon = demoSalonOf($this->get('http://'.config('app.domain').'/demo'));
    $this->flushSession(); // a session that does NOT own this demo
    $this->actingAs(salonOwnerOf($real))
        ->post(route('salon.demo.reset', $salon))
        ->assertForbidden();
});
