<?php

use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Services\Ghl\GhlBookingPusher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Phase 6d sync-error surfacing: a failing push ends up VISIBLE — pending →
| failed with a concise reason and a last-attempt time on the booking, a
| "Sync failed" pill for managers, and an owner/admin-only sync-issues panel
| in Settings → Integrations with a per-booking retry. Tenant-scoped.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')); // Mon 08:00 EDT
});
afterEach(fn () => Carbon::setTestNow());

function siSalon(): Salon
{
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_si',
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);

    $stylist = stylistOf($salon);
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => 'prov_si'],
    );

    return $salon;
}

function siBooking(Salon $salon, array $overrides = []): Booking
{
    $client = Client::factory()->for($salon)->create(['name' => 'Fay Failing', 'ghl_contact_id' => 'ghl_si1']);
    $booking = Booking::factory()->for($salon)->for($client)->create(array_merge([
        'status' => BookingStatus::Booked,
    ], $overrides));
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create(['name' => 'Blowout'])->id,
        'stylist_id' => StylistProfile::forSalon($salon)->first()->user_id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 10:45', $salon->timezone),
    ]);

    return $booking->fresh();
}

function siFailedBooking(Salon $salon): Booking
{
    $booking = siBooking($salon);
    $booking->forceFill([
        'ghl_appointment_id' => 'ghl_si_a1',
        'ghl_sync_status' => GhlBookingPusher::STATUS_FAILED,
        'ghl_sync_error' => 'GoHighLevel rejected the request (HTTP 401).',
        'ghl_last_attempt_at' => now()->subMinutes(5),
    ])->save();

    return $booking;
}

it('tracks a push through pending, a stamped attempt, and a concise failure', function () {
    $salon = siSalon();
    $booking = siBooking($salon);

    // Queueing is visible immediately.
    Bus::fake();
    SyncBookingToGhl::queueFor($booking);
    Bus::assertDispatched(SyncBookingToGhl::class, fn (SyncBookingToGhl $job): bool => $job->bookingId === $booking->id);
    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_PENDING);

    // The push fails against GHL (bad token) …
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'unauthorized'], 401)]);
    $job = new SyncBookingToGhl($booking->id);
    $thrown = null;
    try {
        $job->handle(app(GhlBookingPusher::class));
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();
    $job->failed($thrown); // what the queue does after the final retry

    // … and the booking says so: failed, why, and when it was last tried.
    $booking->refresh();
    expect($booking->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_FAILED);
    expect($booking->ghl_sync_error)->not->toBeEmpty();
    expect($booking->ghl_sync_error)->not->toContain('pit-secret'); // never the token
    expect($booking->ghl_last_attempt_at?->getTimestamp())->toBe(now()->getTimestamp());
});

it('lists failed bookings in the sync-issues panel for an owner', function () {
    $salon = siSalon();
    siFailedBooking($salon);

    test()->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->assertSee('Sync issues')
        ->assertSee('Fay Failing')
        ->assertSee('GoHighLevel rejected the request (HTTP 401).')
        ->assertSee('Retry sync');
});

it('retries a failed booking: re-queued as pending and re-pushed', function () {
    $salon = siSalon();
    $booking = siFailedBooking($salon);

    Bus::fake();
    test()->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->call('retryGhlSync', $booking->id)
        ->assertHasNoErrors();

    Bus::assertDispatched(SyncBookingToGhl::class, fn (SyncBookingToGhl $job): bool => $job->bookingId === $booking->id);
    expect($booking->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_PENDING);
});

it('keeps the sync-issues panel away from non-admin staff', function () {
    $salon = siSalon();
    siFailedBooking($salon);

    // Staff (stylists) cannot open salon settings; front desk — an admin
    // since the remap — can.
    test()->actingAs(stylistOf($salon))
        ->get(route('salon.settings', $salon))
        ->assertForbidden();
    test()->actingAs(frontDeskOf($salon))
        ->get(route('salon.settings', $salon))
        ->assertOk();
});

it('never lists or retries another salon\'s bookings', function () {
    $salonA = siSalon();
    $salonB = siSalon();
    $foreign = siFailedBooking($salonB);

    test()->actingAs(salonOwnerOf($salonA));

    // Not listed …
    Livewire::test('pages::salon.settings', ['salon' => $salonA])
        ->assertDontSee('Fay Failing');

    // … and not retryable by id (anti-IDOR).
    expect(fn () => Livewire::test('pages::salon.settings', ['salon' => $salonA])
        ->call('retryGhlSync', $foreign->id))
        ->toThrow(ModelNotFoundException::class);

    expect($foreign->fresh()->ghl_sync_status)->toBe(GhlBookingPusher::STATUS_FAILED);
});
