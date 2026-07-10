<?php

use App\Actions\Clients\AddClientNote;
use App\Actions\Clients\UpdateClientPreferences;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Client profiles: visit history from existing bookings, timestamped notes,
| preferences with prominent allergies. View + add notes = accessBookings
| (stylists included); edit contact/preferences = manageBookings.
| Frozen clock: Mon 2026-06-22 12:00 UTC (08:00 EDT).
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

/** One booked visit for the client at the given salon-local start. */
function profileVisit(Salon $salon, Client $client, User $stylist, Service $service, string $start, BookingStatus $status = BookingStatus::Completed): Booking
{
    $startAt = CarbonImmutable::parse($start, $salon->timezone);
    $booking = Booking::factory()->for($salon)->for($client)->create(['status' => $status]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => $service->id,
        'stylist_id' => $stylist->id,
        'starts_at' => $startAt,
        'ends_at' => $startAt->addMinutes($service->duration_min),
    ]);

    return $booking;
}

// ---------------------------------------------------------------------------
// Visit history
// ---------------------------------------------------------------------------

it('shows the client\'s visit history most recent first with service, stylist, status, and price', function () {
    $salon = bookingSalon();
    $stylist = stylistOf($salon);
    $client = Client::factory()->for($salon)->create(['name' => 'History Hana']);

    $color = serviceFor($salon, $stylist, 90);
    $color->update(['name' => 'Color', 'price_cents' => 12000]);
    $cut = serviceFor($salon, $stylist, 45);
    $cut->update(['name' => 'Cut', 'price_cents' => null]); // price varies

    $old = profileVisit($salon, $client, $stylist, $color, '2026-05-01 10:00');
    $recent = profileVisit($salon, $client, $stylist, $cut, '2026-06-15 14:00', BookingStatus::NoShow);
    $upcoming = profileVisit($salon, $client, $stylist, $color, '2026-06-29 09:00', BookingStatus::Booked);

    // A different client's booking never appears.
    $other = Client::factory()->for($salon)->create();
    profileVisit($salon, $other, $stylist, $color, '2026-06-10 10:00');

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.show', ['salon' => $salon, 'clientId' => $client->id]);

    expect($component->instance()->visits->pluck('id')->all())
        ->toBe([$upcoming->id, $recent->id, $old->id]); // most recent first

    $component->assertSee('History Hana')
        ->assertSee('Color')->assertSee('$120')          // service + price
        ->assertSee('Cut')                                // unpriced service still listed
        ->assertSee($stylist->name)
        ->assertSee(BookingStatus::NoShow->label())
        ->assertSee(BookingStatus::Completed->label())
        ->assertSee('1 completed visit');                 // header stat
});

// ---------------------------------------------------------------------------
// Notes
// ---------------------------------------------------------------------------

it('lets any booking-area staff add attributed notes — stylists included', function () {
    $salon = bookingSalon();
    $stylist = stylistOf($salon);
    $client = Client::factory()->for($salon)->create();

    Livewire::actingAs($stylist)
        ->test('pages::salon.clients.show', ['salon' => $salon, 'clientId' => $client->id])
        ->set('noteBody', 'Prefers cooler tones')
        ->call('addNote')
        ->assertHasNoErrors()
        ->assertSee('Prefers cooler tones')
        ->assertSee($stylist->name);

    $note = $client->notes()->first();
    expect($note->body)->toBe('Prefers cooler tones');
    expect($note->author_id)->toBe($stylist->id);
    expect($note->salon_id)->toBe($salon->id);
});

it('refuses a note on another salon\'s client through the action (anti-IDOR)', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $clientA = Client::factory()->for($salonA)->create();

    expect(fn () => app(AddClientNote::class)->handle(salonOwnerOf($salonB), $salonB, $clientA, 'sneaky'))
        ->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------
// Preferences + allergies
// ---------------------------------------------------------------------------

it('persists preferences and surfaces allergies prominently', function () {
    $salon = bookingSalon();
    $stylist = stylistOf($salon);
    $client = Client::factory()->for($salon)->create();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.show', ['salon' => $salon, 'clientId' => $client->id])
        ->call('startEditingPrefs')
        ->set('allergies', 'PPD allergy — no permanent color')
        ->set('formulaNotes', '7N + 8A 1:1, 20 vol')
        ->set('preferredStylistId', (string) $stylist->id)
        ->set('preferredContactMethod', 'text')
        ->set('birthday', '1990-04-12')
        ->call('savePreferences')
        ->assertHasNoErrors()
        // The allergy banner renders on the profile header.
        ->assertSee(__('Allergies / sensitivities:'))
        ->assertSee('PPD allergy — no permanent color');

    $client->refresh();
    expect($client->allergies)->toBe('PPD allergy — no permanent color');
    expect($client->formula_notes)->toBe('7N + 8A 1:1, 20 vol');
    expect($client->preferred_stylist_id)->toBe($stylist->id);
    expect($client->preferred_contact_method)->toBe('text');
    expect($client->birthday?->format('Y-m-d'))->toBe('1990-04-12');
});

it('lets stylists view profiles but not edit preferences or contact', function () {
    $salon = bookingSalon();
    $stylist = stylistOf($salon);
    $client = Client::factory()->for($salon)->create(['allergies' => 'Latex sensitivity']);

    // Viewing works, and the safety banner is visible to stylists too.
    Livewire::actingAs($stylist)
        ->test('pages::salon.clients.show', ['salon' => $salon, 'clientId' => $client->id])
        ->assertSee('Latex sensitivity')
        ->call('startEditingPrefs')
        ->assertForbidden();

    Livewire::actingAs($stylist)
        ->test('pages::salon.clients.show', ['salon' => $salon, 'clientId' => $client->id])
        ->call('startContactEdit')
        ->assertForbidden();
});

it('rejects a preferred stylist from another salon', function () {
    $salonA = Salon::factory()->create();
    $foreignStylist = stylistOf(Salon::factory()->create());
    $client = Client::factory()->for($salonA)->create();

    expect(fn () => app(UpdateClientPreferences::class)->handle($salonA, $client, [
        'preferred_stylist_id' => $foreignStylist->id,
    ]))->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('404s another salon\'s client profile (no cross-salon leakage)', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $clientA = Client::factory()->for($salonA)->create();
    ClientNote::factory()->for($salonA)->create(['client_id' => $clientA->id, 'body' => 'salon A secret']);

    // Salon B's owner, on salon B's own host, probing salon A's client id.
    $this->actingAs(salonOwnerOf($salonB))
        ->get(route('salon.client', ['salon' => $salonB, 'clientId' => $clientA->id]))
        ->assertNotFound();

    // And salon B's owner cannot reach salon A's host at all.
    $this->actingAs(salonOwnerOf($salonB))
        ->get(route('salon.client', ['salon' => $salonA, 'clientId' => $clientA->id]))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Existing clients + entry points
// ---------------------------------------------------------------------------

it('renders a bare pre-existing client (no notes or preferences) cleanly', function () {
    $salon = bookingSalon();
    $client = Client::factory()->for($salon)->create(['phone' => null, 'email' => null]);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.show', ['salon' => $salon, 'clientId' => $client->id])
        ->assertSee($client->name)
        ->assertSee(__('No visits yet.'))
        ->assertSee(__('No notes yet.'))
        ->assertSee(__('None recorded'))
        ->assertDontSee(__('Allergies / sensitivities:')); // no banner without allergies
});

it('keeps the booking client search working and links to the profile', function () {
    $salon = bookingSalon();
    $client = Client::factory()->for($salon)->create(['name' => 'Searchable Sam']);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.bookings.create', ['salon' => $salon])
        ->set('clientSearch', 'Searchable')
        ->assertSee('Searchable Sam')
        ->set('clientId', $client->id)
        ->assertSee(__('View client profile'));
});

it('links each clients-list row to the profile page', function () {
    $salon = Salon::factory()->create();
    $client = Client::factory()->for($salon)->create();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->assertSeeHtml(route('salon.client', ['salon' => $salon, 'clientId' => $client->id]));
});
