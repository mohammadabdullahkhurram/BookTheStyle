<?php

use App\Actions\Bookings\DeleteBooking;
use App\Actions\Clients\DeleteClient;
use App\Actions\Services\DeleteService;
use App\Actions\Staff\PurgeStaffMember;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Jobs\CancelGhlAppointmentRemotely;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Permanent deletion — stylist, client, appointment, service. Record AND
| history gone (deactivate/cancel stay the archive paths). Gated to the
| salon owner + agency owner/admin; salon-scoped; FK-safe cascade order;
| future bookings are never deleted silently.
*/

function pdAgencyAdmin(Salon $salon): User
{
    return User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Admin,
    ]);
}

/**
 * A salon with one stylist (working the given upcoming day), one service,
 * and one upcoming booking on them.
 *
 * @return array{salon: Salon, owner: User, stylist: User, service: Service, booking: Booking}
 */
function pdSalonWithUpcomingBooking(): array
{
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $target = CarbonImmutable::now($salon->timezone)->addDays(2);
    $stylist = stylistWithHours($salon, (int) $target->format('N') - 1, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $booking = makeBooking($salon, $owner, $stylist, $service, $target->setTime(10, 0)->format('Y-m-d H:i'));

    return compact('salon', 'owner', 'stylist', 'service', 'booking');
}

it('gates every permanent delete to owner + agency owner/admin — managers, stylists, and the demo are refused', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $client = $booking->client;
    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();

    foreach ([managerOf($salon), stylistOf($salon)] as $lowActor) {
        expect(fn () => app(DeleteBooking::class)->handle($lowActor, $salon, $booking))->toThrow(AuthorizationException::class);
        expect(fn () => app(DeleteClient::class)->handle($lowActor, $salon, $client))->toThrow(AuthorizationException::class);
        expect(fn () => app(DeleteService::class)->handle($lowActor, $salon, $service, true))->toThrow(AuthorizationException::class);
        expect(fn () => app(PurgeStaffMember::class)->handle($lowActor, $salon, $membership, true))->toThrow(AuthorizationException::class);
    }

    // Nothing was deleted by the refused attempts.
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();
    expect(Service::withoutGlobalScopes()->whereKey($service->id)->exists())->toBeTrue();

    // The demo salon refuses even its own agency operator.
    $demo = demoShowcase();
    $demoOp = User::factory()->create(['agency_id' => $demo->agency_id, 'agency_role' => AgencyRole::Owner]);
    $demoClient = Client::withoutGlobalScopes()->where('salon_id', $demo->id)->firstOrFail();
    expect(fn () => app(DeleteClient::class)->handle($demoOp, $demo, $demoClient))->toThrow(AuthorizationException::class);

    // The owner and an agency admin CAN delete (appointment here; the other
    // types have their own tests below).
    app(DeleteBooking::class)->handle($owner, $salon, $booking);
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeFalse();
});

it('refuses to purge a stylist with upcoming appointments until acknowledged — then removes them and every dependent, FK-safely', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();

    // A second salon, untouched throughout.
    $other = pdSalonWithUpcomingBooking();

    // Unacknowledged → refused, nothing changes.
    expect(fn () => app(PurgeStaffMember::class)->handle($owner, $salon, $membership))
        ->toThrow(ValidationException::class);
    expect(User::whereKey($stylist->id)->exists())->toBeTrue();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();

    // Acknowledged → stylist, bookings, and every dependent row are gone.
    $accountDeleted = app(PurgeStaffMember::class)->handle($owner, $salon, $membership, acknowledgedUpcoming: true);

    expect($accountDeleted)->toBeTrue();
    expect(User::withTrashed()->whereKey($stylist->id)->exists())->toBeFalse(); // force-deleted
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeFalse();
    expect(DB::table('booking_items')->where('stylist_id', $stylist->id)->exists())->toBeFalse();
    expect(DB::table('booking_status_events')->where('booking_id', $booking->id)->exists())->toBeFalse();
    expect(Availability::withoutGlobalScopes()->where('user_id', $stylist->id)->exists())->toBeFalse();
    expect(DB::table('service_stylist')->where('user_id', $stylist->id)->exists())->toBeFalse();
    expect($salon->memberships()->where('user_id', $stylist->id)->exists())->toBeFalse();

    // The other salon kept everything.
    expect(Booking::withoutGlobalScopes()->whereKey($other['booking']->id)->exists())->toBeTrue();
    expect(User::whereKey($other['stylist']->id)->exists())->toBeTrue();
});

it('purges a cross-salon member from ONE salon only: the account and the other salon survive', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'booking' => $booking] = pdSalonWithUpcomingBooking();

    // The same person also works at a second salon.
    $second = bookingSalon();
    stylistOf($second, $stylist);

    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();
    $accountDeleted = app(PurgeStaffMember::class)->handle($owner, $salon, $membership, acknowledgedUpcoming: true);

    expect($accountDeleted)->toBeFalse();
    expect(User::whereKey($stylist->id)->exists())->toBeTrue(); // still logs in elsewhere
    expect($second->memberships()->where('user_id', $stylist->id)->exists())->toBeTrue();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeFalse(); // this salon's data gone
});

it('never purges yourself, the last active owner, or across salons (IDOR)', function () {
    ['salon' => $salon, 'owner' => $owner] = pdSalonWithUpcomingBooking();
    $op = pdAgencyAdmin($salon);

    // Self.
    $ownMembership = $salon->memberships()->where('user_id', $owner->id)->first();
    expect(fn () => app(PurgeStaffMember::class)->handle($owner, $salon, $ownMembership, true))
        ->toThrow(AuthorizationException::class);

    // Last active owner (an agency op has the authority, the guard still refuses).
    expect(fn () => app(PurgeStaffMember::class)->handle($op, $salon, $ownMembership, true))
        ->toThrow(ValidationException::class);

    // A membership from another salon 403s regardless of privilege.
    $foreign = pdSalonWithUpcomingBooking();
    $foreignMembership = $foreign['salon']->memberships()->where('user_id', $foreign['stylist']->id)->first();
    expect(fn () => app(PurgeStaffMember::class)->handle($owner, $salon, $foreignMembership, true))
        ->toThrow(AuthorizationException::class);
});

it('refuses to delete a service with upcoming appointments until acknowledged — then removes it, its appointments, and its stylist links', function () {
    ['salon' => $salon, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $op = pdAgencyAdmin($salon);
    $other = pdSalonWithUpcomingBooking();

    expect(fn () => app(DeleteService::class)->handle($op, $salon, $service))
        ->toThrow(ValidationException::class);
    expect(Service::withoutGlobalScopes()->whereKey($service->id)->exists())->toBeTrue();

    app(DeleteService::class)->handle($op, $salon, $service, acknowledgedUpcoming: true);

    expect(Service::withoutGlobalScopes()->whereKey($service->id)->exists())->toBeFalse();
    expect(DB::table('service_stylist')->where('service_id', $service->id)->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeFalse();
    expect(DB::table('booking_items')->where('service_id', $service->id)->exists())->toBeFalse();

    // The second salon's service and booking are untouched.
    expect(Service::withoutGlobalScopes()->whereKey($other['service']->id)->exists())->toBeTrue();
    expect(Booking::withoutGlobalScopes()->whereKey($other['booking']->id)->exists())->toBeTrue();
});

it('deletes a client with every appointment and note; deleting one appointment removes just that one', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();

    // A second, separate booking for a DIFFERENT client stays.
    $target = CarbonImmutable::now($salon->timezone)->addDays(2);
    $keeper = makeBooking($salon, $owner, $stylist, $service, $target->setTime(14, 0)->format('Y-m-d H:i'), 'Keeper Kim');

    $client = $booking->client;
    DB::table('client_notes')->insert([
        'salon_id' => $salon->id, 'client_id' => $client->id, 'author_id' => $owner->id,
        'body' => 'note', 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(DeleteClient::class)->handle($owner, $salon, $client);

    expect(Client::withoutGlobalScopes()->whereKey($client->id)->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeFalse();
    expect(DB::table('client_notes')->where('client_id', $client->id)->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->whereKey($keeper->id)->exists())->toBeTrue();

    // Single-appointment delete: only that appointment goes.
    app(DeleteBooking::class)->handle($owner, $salon, $keeper);
    expect(Booking::withoutGlobalScopes()->whereKey($keeper->id)->exists())->toBeFalse();
    expect(Client::withoutGlobalScopes()->where('name', 'Keeper Kim')->exists())->toBeTrue(); // the client survives
});

it('cancels a synced upcoming appointment on the GHL side when it is deleted locally', function () {
    Queue::fake();
    ['salon' => $salon, 'owner' => $owner, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $booking->forceFill(['ghl_appointment_id' => 'ghl_evt_123'])->save();

    app(DeleteBooking::class)->handle($owner, $salon, $booking);

    Queue::assertPushed(CancelGhlAppointmentRemotely::class, fn (CancelGhlAppointmentRemotely $job) => $job->salonId === $salon->id && $job->ghlAppointmentId === 'ghl_evt_123');
});

it('shows the blast radius in each confirm modal with the right counts', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();

    // Staff modal: total + upcoming list, acknowledgment required.
    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startDelete', $membership->id)
        ->assertSet('showDelete', true)
        ->assertSee(__('Also deleted: :count appointment (all of their history here).', ['count' => 1]))
        ->assertSee($booking->client->name)
        ->call('confirmDelete') // unacknowledged → surfaced error, nothing deleted
        ->assertHasErrors(['deleteAcknowledge'])
        ->set('deleteAcknowledge', true)
        ->call('confirmDelete')
        ->assertHasNoErrors();
    expect($salon->memberships()->where('user_id', $stylist->id)->exists())->toBeFalse();

    // Client modal: the appointment count (booking survived the stylist
    // purge? no — it went with the stylist; make a fresh scenario).
    ['salon' => $salon2, 'owner' => $owner2, 'service' => $service2, 'booking' => $booking2] = pdSalonWithUpcomingBooking();
    Livewire::actingAs($owner2)
        ->test('pages::salon.clients.index', ['salon' => $salon2])
        ->call('startEdit', $booking2->client_id)
        ->call('startDelete')
        ->assertSet('showDelete', true)
        ->assertSee(__('Also deleted: :count appointment.', ['count' => 1]));

    // Service modal: count + upcoming + acknowledgment path.
    Livewire::actingAs($owner2)
        ->test('pages::salon.services.index', ['salon' => $salon2])
        ->call('startDelete', $service2->id)
        ->assertSet('showDelete', true)
        ->assertSee(__('Also deleted: :count appointment.', ['count' => 1]))
        ->call('confirmDelete')
        ->assertHasErrors(['deleteAcknowledge'])
        ->set('deleteAcknowledge', true)
        ->call('confirmDelete')
        ->assertHasNoErrors();
    expect(Service::withoutGlobalScopes()->whereKey($service2->id)->exists())->toBeFalse();
});

it('hides every delete affordance from managers and blocks the demo pages', function () {
    ['salon' => $salon, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $manager = managerOf($salon);

    $this->actingAs($manager)->get(route('salon.clients', $salon))->assertOk()->assertDontSee(__('Delete permanently'));
    $this->actingAs($manager)->get(route('salon.appointments.all', $salon))->assertOk()->assertDontSee(__('Delete permanently'));

    // Owners see them.
    $owner = $salon->memberships()->where('salon_role', SalonRole::Owner->value)->first()->user;
    $this->actingAs($owner)->get(route('salon.appointments.all', $salon))->assertOk()->assertSee(__('Delete permanently'));
});
