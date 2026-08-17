<?php

use App\Actions\Bookings\DeleteBooking;
use App\Enums\AgencyRole;
use App\Enums\BookingSource;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\Client;
use App\Models\User;
use App\Services\Diagnostics\ConnectionDiagnostics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

/*
| The widget preview's TEST-booking lane: for an AGENCY OPERATOR (the same
| runDiagnostics gate as Check connections) the in-app preview's confirm
| commits a REAL booking through the shared engine — but on the designated
| is_test client, so it lands, badges TEST, never syncs to GHL, and the
| diagnostics teardown/sweep removes it. Salon roles keep the old
| non-committal preview; the PUBLIC widget is untouched. Frozen clock: Mon
| 2026-06-22 12:00 UTC (the shared widget helpers' clock).
*/

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    Bus::fake([SyncBookingToGhl::class]);
});
afterEach(fn () => Carbon::setTestNow());

function agencyOpFor($salon): User
{
    return User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Admin]);
}

it('commits an agency operator\'s preview confirm as a REAL booking on the designated test client', function () {
    [$salon, $stylist] = widgetSalon();
    $operator = agencyOpFor($salon);

    $response = $this->actingAs($operator)
        ->postJson(route('salon.widget.preview.book', $salon), widgetPayload($salon, [
            'client' => ['name' => 'Olivia Operator', 'phone' => '+1 555 999 1234'],
        ]))
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('test', true);

    expect($response->json('message'))->toContain('Test booking');

    // A real engine booking landed — on the TEST client, never on the
    // identity typed into the preview.
    $booking = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->with('client')->sole();
    expect($booking->client->name)->toBe(ConnectionDiagnostics::CLIENT_NAME);
    expect($booking->client->is_test)->toBeTrue();
    expect($booking->source)->toBe(BookingSource::WebWidget);
    expect($booking->items()->sole()->starts_at->timezone($salon->timezone)->format('Y-m-d g:i A'))->toBe('2026-06-22 2:00 PM');
    expect(Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('name', 'Olivia Operator')->exists())->toBeFalse();

    // The client's is_test flag is exactly what SyncBookingToGhl's guard
    // reads — the booking can never reach GHL (no reminders, no voice AI).
    // And the sweep TTL is armed so an abandoned test booking ages out.
    expect($salon->refresh()->test_records_expire_at)->not->toBeNull();
});

it('keeps salon-role previews non-committal and the PUBLIC widget exactly as before', function () {
    [$salon] = widgetSalon();

    // Owner and manager: full flow runs, nothing persists (unchanged).
    foreach ([salonOwnerOf($salon), managerOf($salon)] as $actor) {
        $this->actingAs($actor)
            ->postJson(route('salon.widget.preview.book', $salon), widgetPayload($salon))
            ->assertCreated()
            ->assertJsonPath('preview', true)
            ->assertJsonMissingPath('test');
    }
    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(0);

    // The public embedded widget: a REAL booking for the REAL typed client.
    $this->postJson(route('salon.widget.book', $salon), widgetPayload($salon))
        ->assertCreated()
        ->assertJsonMissingPath('test');

    $booking = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->with('client')->sole();
    expect($booking->client->name)->toBe('Widget Wendy');
    expect($booking->client->is_test)->toBeFalse();
    expect($booking->source)->toBe(BookingSource::WebWidget);
    Bus::assertDispatched(SyncBookingToGhl::class); // real bookings still push
});

it('shows the operator a badged TEST appointment, while reports never count it', function () {
    [$salon, $stylist] = widgetSalon();
    $operator = agencyOpFor($salon);

    $this->actingAs($operator)
        ->postJson(route('salon.widget.preview.book', $salon), widgetPayload($salon))
        ->assertCreated();

    // Badged for the operator on the appointments list…
    $this->actingAs($operator)
        ->get(route('salon.appointments', $salon).'?date=2026-06-22')
        ->assertOk()
        ->assertSee(ConnectionDiagnostics::CLIENT_NAME)
        ->assertSee(__('TEST'));

    // …and the public widget now treats the slot as taken (a REAL hold —
    // the point of a true end-to-end test), while the availability payload
    // never names the test client.
    $service = $salon->services()->sole();
    $slots = $this->getJson(route('salon.widget.availability', $salon).'?services[]='.$service->id.'&stylist=any&date=2026-06-22')
        ->assertOk()->json();
    expect(collect($slots['slots'])->pluck('time'))->not->toContain('2:00 PM');
    expect(json_encode($slots))->not->toContain(ConnectionDiagnostics::CLIENT_NAME);
});

it('is swept by the diagnostics TTL, removable by the existing delete tooling, and never touches another salon', function () {
    [$salon] = widgetSalon();
    [$other, , $otherService] = widgetSalon();
    $operator = agencyOpFor($salon);

    // Salon B's REAL public booking goes FIRST: in the test lifecycle a
    // tenant request leaves currentSalon bound in the shared container, and
    // the global scope would then hide salon B from its own engine calls
    // (prod runs one process per request — no such bleed).
    $this->postJson(route('salon.widget.book', $other), widgetPayload($other))->assertCreated();

    // Then the preview test booking in salon A.
    $this->actingAs($operator)
        ->postJson(route('salon.widget.preview.book', $salon), widgetPayload($salon))
        ->assertCreated();

    // Manual removal: the existing hard-delete action takes it like any
    // appointment. (Re-book afterwards for the sweep half.)
    $booking = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->sole();
    app(DeleteBooking::class)->handle($operator, $salon, $booking);
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeFalse();

    $this->actingAs($operator)
        ->postJson(route('salon.widget.preview.book', $salon), widgetPayload($salon))
        ->assertCreated();

    // The hourly sweep: expire the TTL and run it — booking AND test
    // client go; salon B's real booking and clients are untouched.
    $salon->forceFill(['test_records_expire_at' => now()->subMinute()])->save();
    $this->artisan('diagnostics:sweep-test-records')->assertExitCode(0);

    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(0);
    expect(Client::withoutGlobalScopes()->withTrashed()->where('salon_id', $salon->id)->where('is_test', true)->exists())->toBeFalse();
    expect($salon->refresh()->test_records_expire_at)->toBeNull();

    expect(Booking::withoutGlobalScopes()->where('salon_id', $other->id)->count())->toBe(1);
    expect(Client::withoutGlobalScopes()->where('salon_id', $other->id)->where('name', 'Widget Wendy')->exists())->toBeTrue();
});

it('reuses the diagnostics test client rather than minting a duplicate', function () {
    [$salon] = widgetSalon();
    $operator = agencyOpFor($salon);

    // The Check-connections lane already provisioned the records…
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon, now()->addHours(48));
    $before = Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->count();

    $this->actingAs($operator)
        ->postJson(route('salon.widget.preview.book', $salon), widgetPayload($salon))
        ->assertCreated()
        ->assertJsonPath('test', true);

    // Same client row; and an in-flight LONGER diagnostics TTL is never
    // shortened by a preview booking.
    expect(Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->count())->toBe($before);
    expect($salon->refresh()->test_records_expire_at->isAfter(now()->addHours(24)))->toBeTrue();
});
