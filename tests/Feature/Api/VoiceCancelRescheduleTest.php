<?php

use App\Enums\AgencyRole;
use App\Enums\BookingStatus;
use App\Enums\SalonRole;
use App\Jobs\SyncBookingToGhl;
use App\Models\Agency;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\User;
use App\Support\BookingApiToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

/*
| Voice AI cancel + reschedule: the two new booking-API endpoints. Same
| bearer auth, throttle, tenancy and error semantics as availability/
| create; the mutations run through the SAME app actions (status event,
| GHL mirror). Identification: phone/email/ghl_contact_id (+ stated
| date/time to narrow) or booking_id; several matches disambiguate.
*/

/**
 * A salon with a stylist working the target day 9–17, a service, a token,
 * and one upcoming 10:00 AM booking for a phone-identified client.
 *
 * @return array{salon: Salon, token: string, booking: Booking, at: CarbonImmutable}
 */
function crSalon(string $phone = '+1 415 555 0122'): array
{
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $at = CarbonImmutable::now($salon->timezone)->addDays(2)->setTime(10, 0);
    $stylist = stylistWithHours($salon, (int) $at->format('N') - 1, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $booking = makeBooking($salon, $owner, $stylist, $service, $at->format('Y-m-d H:i'), 'Casey Caller');
    $booking->client->update(['phone' => $phone]);
    $token = BookingApiToken::generate($salon);

    return compact('salon', 'token', 'booking', 'at');
}

function crPost(string $route, array $payload, ?string $token): TestResponse
{
    return test()->postJson(route($route), $payload, $token !== null ? ['Authorization' => "Bearer {$token}"] : []);
}

it('requires the bearer token on both endpoints — uniform 401', function () {
    crSalon();

    crPost('api.booking.cancel', ['client' => ['phone' => '+1 415 555 0122']], null)->assertStatus(401);
    crPost('api.booking.reschedule', ['client' => ['phone' => '+1 415 555 0122']], null)->assertStatus(401);
});

it('cancels the caller\'s single upcoming appointment by phone — status event written, GHL mirror queued', function () {
    Queue::fake();
    ['salon' => $salon, 'token' => $token, 'booking' => $booking] = crSalon();
    $booking->forceFill(['ghl_appointment_id' => 'evt_1'])->save();

    $response = crPost('api.booking.cancel', ['client' => ['phone' => '+1 415 555 0122']], $token)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('already_cancelled', false)
        ->assertJsonPath('booking_id', $booking->id);

    expect($response->json('message'))->toContain('Cancelled');
    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);

    // The same effects the app's cancel has: a status event (system actor)
    // and the GHL mirror job.
    $event = $booking->statusEvents()->latest('id')->first();
    expect($event->to_status)->toBe(BookingStatus::Cancelled);
    expect($event->actor_user_id)->toBeNull();
    Queue::assertPushed(SyncBookingToGhl::class, fn (SyncBookingToGhl $job) => $job->bookingId === $booking->id);
});

it('disambiguates several upcoming appointments, then cancels the one narrowed by date+time', function () {
    ['salon' => $salon, 'token' => $token, 'booking' => $booking, 'at' => $at] = crSalon();

    // A second upcoming appointment for the SAME client (phone-matched by
    // the create endpoint itself), later that day.
    crPost('api.booking.create', [
        'service' => $booking->items()->first()->service->name,
        'date' => $at->format('Y-m-d'), 'time' => '3:00 PM',
        'client' => ['name' => 'Casey Caller', 'phone' => '+1 415 555 0122'],
    ], $token)->assertStatus(201);

    $response = crPost('api.booking.cancel', ['client' => ['phone' => '+1 415 555 0122']], $token)
        ->assertStatus(409)
        ->assertJsonPath('error', 'multiple_appointments');
    expect($response->json('appointments'))->toHaveCount(2);
    expect($response->json('message'))->toContain('which one');

    // Narrowed by the stated time → exactly one → cancelled.
    crPost('api.booking.cancel', [
        'client' => ['phone' => '+1 415 555 0122'],
        'date' => $at->format('Y-m-d'), 'time' => '10:00 AM',
    ], $token)->assertOk()->assertJsonPath('booking_id', $booking->id);

    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled);
});

it('answers 404 for an unknown caller or no matching upcoming appointment, and 422 with no identifier at all', function () {
    ['token' => $token, 'at' => $at] = crSalon();

    crPost('api.booking.cancel', ['client' => ['phone' => '+1 999 000 0000']], $token)
        ->assertStatus(404)->assertJsonPath('error', 'client_not_found');

    crPost('api.booking.cancel', [
        'client' => ['phone' => '+1 415 555 0122'],
        'date' => $at->addDays(3)->format('Y-m-d'),
    ], $token)->assertStatus(404)->assertJsonPath('error', 'appointment_not_found');

    crPost('api.booking.cancel', [], $token)
        ->assertStatus(422)->assertJsonPath('error', 'missing_identifier');
});

it('is idempotent: cancelling the already-cancelled appointment answers cleanly, not a crash', function () {
    ['token' => $token, 'booking' => $booking, 'at' => $at] = crSalon();

    crPost('api.booking.cancel', ['client' => ['phone' => '+1 415 555 0122']], $token)->assertOk();

    crPost('api.booking.cancel', [
        'client' => ['phone' => '+1 415 555 0122'],
        'date' => $at->format('Y-m-d'), 'time' => '10:00 AM',
    ], $token)
        ->assertOk()
        ->assertJsonPath('already_cancelled', true)
        ->assertJsonPath('booking_id', $booking->id);

    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->sole()->status)->toBe(BookingStatus::Cancelled);
});

it('never touches another salon: salon B\'s identical client is invisible to salon A\'s token', function () {
    $a = crSalon();
    $b = crSalon(); // same phone, different salon

    // Salon A's client has no other appointment — cancel through A's token
    // cancels A's booking only; B's stays booked.
    crPost('api.booking.cancel', ['client' => ['phone' => '+1 415 555 0122']], $a['token'])->assertOk();
    expect($a['booking']->refresh()->status)->toBe(BookingStatus::Cancelled);
    expect($b['booking']->refresh()->status)->toBe(BookingStatus::Booked);

    // And a booking_id from salon B is a 404 through A's token (anti-IDOR).
    crPost('api.booking.cancel', ['booking_id' => $b['booking']->id], $a['token'])
        ->assertStatus(404)->assertJsonPath('error', 'appointment_not_found');
});

it('reschedules to a free slot through the real engine — new time confirmed, GHL updated in place', function () {
    Queue::fake();
    ['salon' => $salon, 'token' => $token, 'booking' => $booking, 'at' => $at] = crSalon();

    $response = crPost('api.booking.reschedule', [
        'client' => ['phone' => '+1 415 555 0122'],
        'new_date' => $at->format('Y-m-d'), 'new_time' => '1:00 PM',
    ], $token)
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('booking_id', $booking->id);

    expect($response->json('confirmation.spoken_time'))->toContain('1:00 PM');
    expect($response->json('message'))->toContain('moved');

    $start = $booking->refresh()->items()->first()->starts_at->setTimezone($salon->timezone);
    expect($start->format('g:i A'))->toBe('1:00 PM');
    Queue::assertPushed(SyncBookingToGhl::class, fn (SyncBookingToGhl $job) => $job->bookingId === $booking->id);
});

it('refuses a taken new slot with 409 and leaves the appointment untouched — no double-booking', function () {
    ['salon' => $salon, 'token' => $token, 'booking' => $booking, 'at' => $at] = crSalon();

    // Another client already holds 1:00 PM with the same stylist.
    $owner = $salon->memberships()->where('salon_role', SalonRole::Owner->value)->first()->user;
    $item = $booking->items()->first();
    makeBooking($salon, $owner, $item->stylist, $item->service, $at->setTime(13, 0)->format('Y-m-d H:i'), 'Blocker Bob');

    crPost('api.booking.reschedule', [
        'client' => ['phone' => '+1 415 555 0122'],
        'new_date' => $at->format('Y-m-d'), 'new_time' => '1:00 PM',
    ], $token)
        ->assertStatus(409)
        ->assertJsonPath('error', 'slot_unavailable');

    expect($booking->refresh()->items()->first()->starts_at->setTimezone($salon->timezone)->format('g:i A'))->toBe('10:00 AM');
});

it('404s a reschedule for an unknown appointment and never crosses salons', function () {
    $a = crSalon();
    $b = crSalon();

    crPost('api.booking.reschedule', [
        'client' => ['phone' => '+1 777 000 1111'],
        'new_date' => $a['at']->format('Y-m-d'), 'new_time' => '1:00 PM',
    ], $a['token'])->assertStatus(404);

    crPost('api.booking.reschedule', [
        'booking_id' => $b['booking']->id,
        'new_date' => $b['at']->format('Y-m-d'), 'new_time' => '1:00 PM',
    ], $a['token'])->assertStatus(404);
    expect($b['booking']->refresh()->items()->first()->starts_at->setTimezone($b['salon']->timezone)->format('g:i A'))->toBe('10:00 AM');
});

it('documents both endpoints in the technical reference', function () {
    $agency = Agency::factory()->create();
    $op = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    $this->actingAs($op)
        ->get(route('agency.docs', ['doc' => 'technical-integration-reference']))
        ->assertOk()
        ->assertSee(__('Cancel appointment'))
        ->assertSee(__('Reschedule appointment'))
        ->assertSee('api.booking.cancel')
        ->assertSee('api.booking.reschedule')
        ->assertSee('multiple_appointments');
});
