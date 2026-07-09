<?php

use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Phase 6d source tagging: every booking records where it originated. In-app
| bookings are in_app; GHL-originated ones derive their source from the
| webhook's explicit customData.source, the contact's tags, or the
| created_by / last_updated_by metadata — and fall back to ghl_other when
| nothing identifies the channel. The source is visible in the UI.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')); // Mon 08:00 EDT
});
afterEach(fn () => Carbon::setTestNow());

function srcSalon(): Salon
{
    $salon = bookingSalon();
    $connection = SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_src',
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);
    $connection->webhook_secret = 'src-secret';
    $connection->save();

    $stylist = stylistOf($salon);
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => 'prov_src'],
    );

    return $salon;
}

/** Post one real-shape workflow webhook (calendar.* nesting) and return the imported booking. */
function srcImport(Salon $salon, array $calendarExtra = [], array $rootExtra = []): ?Booking
{
    test()->postJson(route('webhooks.ghl'), array_merge([
        'locationId' => 'loc_src',
        'calendar' => array_merge([
            'appointmentId' => 'appt_'.fake()->unique()->lexify('??????'),
            'appoinmentStatus' => 'confirmed', // GHL's misspelling, as sent live
            'assignedUserId' => 'prov_src',
            'startTime' => '2026-06-23T15:00:00-04:00',
            'endTime' => '2026-06-23T16:00:00-04:00',
            'selectedTimezone' => 'America/New_York',
        ], $calendarExtra),
        'contact' => ['id' => 'ghl_c_'.fake()->unique()->lexify('????'), 'name' => 'Source Client'],
    ], $rootExtra), ['X-Webhook-Secret' => 'src-secret'])->assertStatus(202);

    return Booking::where('salon_id', $salon->id)->latest('id')->first();
}

it('tags an in-app booking as in_app', function () {
    $salon = bookingSalon(); // unconnected: the push skips without HTTP
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60); // weekday 0 = Monday
    $service = serviceFor($salon, $stylist);

    $booking = makeBooking($salon, salonOwnerOf($salon), $stylist, $service);

    expect($booking->source)->toBe(BookingSource::InApp);
});

it('maps web_app / appointment_page metadata to ghl_manual', function () {
    $salon = srcSalon();

    $booking = srcImport($salon, [
        'created_by_meta' => ['source' => 'appointment_page'],
        'last_updated_by_meta' => ['source' => 'appointment_page', 'channel' => 'web_app'],
    ]);

    expect($booking->source)->toBe(BookingSource::GhlManual);
});

it('derives voice AI from contact tags when the metadata only says third_party', function () {
    $salon = srcSalon();

    $booking = srcImport($salon, [
        'created_by_meta' => ['source' => 'third_party'],
    ], [
        'contact' => ['id' => 'ghl_c_voice', 'name' => 'Voice Client', 'tags' => ['Voice AI booking']],
    ]);

    expect($booking->source)->toBe(BookingSource::VoiceAi);
});

it('lets an explicit customData source override every other signal', function () {
    $salon = srcSalon();

    $booking = srcImport($salon, [
        'created_by_meta' => ['source' => 'appointment_page', 'channel' => 'web_app'],
    ], [
        'customData' => ['source' => 'chat'],
    ]);

    expect($booking->source)->toBe(BookingSource::ChatWidget);
});

it('falls back to ghl_other when the channel cannot be determined', function () {
    $salon = srcSalon();

    $booking = srcImport($salon, [
        'created_by_meta' => ['source' => 'third_party'], // an integration, but WHICH one is unknowable
    ]);

    expect($booking->source)->toBe(BookingSource::GhlOther);
});

it('shows the source on the appointments list', function () {
    $salon = bookingSalon();
    $stylist = stylistOf($salon);
    $client = Client::factory()->for($salon)->create(['name' => 'Vera Voice']);

    $booking = Booking::factory()->for($salon)->for($client)->create(['source' => BookingSource::VoiceAi]);
    BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => Service::factory()->for($salon)->create()->id,
        'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 10:45', $salon->timezone),
    ]);

    test()->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.appointments.all', ['salon' => $salon])
        ->assertSee('Vera Voice')
        ->assertSee('Voice AI');
});
