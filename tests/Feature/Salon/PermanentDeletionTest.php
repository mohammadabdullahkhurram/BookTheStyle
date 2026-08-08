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
| SOLO deletes — stylist, client, appointment, service. Deleting one record
| removes ONLY that record: appointments are always kept, rendering the
| tombstoned (soft-deleted) name marked "(removed)". Pure link rows that
| cannot exist without the target (stylist⇄service pivot, availability,
| client notes) go as FK cleanup. Gating: salon owner + agency owner/admin.
*/

function pdAgencyAdmin(Salon $salon): User
{
    return User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::Admin,
    ]);
}

/**
 * A salon with one stylist (working an upcoming day), one service, and one
 * upcoming booking on them.
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

it('gates every delete to owner + agency owner/admin — managers, stylists, and the demo are refused', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $client = $booking->client;
    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();

    foreach ([managerOf($salon), stylistOf($salon)] as $lowActor) {
        expect(fn () => app(DeleteBooking::class)->handle($lowActor, $salon, $booking))->toThrow(AuthorizationException::class);
        expect(fn () => app(DeleteClient::class)->handle($lowActor, $salon, $client))->toThrow(AuthorizationException::class);
        expect(fn () => app(DeleteService::class)->handle($lowActor, $salon, $service))->toThrow(AuthorizationException::class);
        expect(fn () => app(PurgeStaffMember::class)->handle($lowActor, $salon, $membership))->toThrow(AuthorizationException::class);
    }

    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();
    expect(Service::withoutGlobalScopes()->whereKey($service->id)->whereNull('deleted_at')->exists())->toBeTrue();

    // The demo salon refuses even its own agency operator.
    $demo = demoShowcase();
    $demoOp = User::factory()->create(['agency_id' => $demo->agency_id, 'agency_role' => AgencyRole::Owner]);
    $demoClient = Client::withoutGlobalScopes()->where('salon_id', $demo->id)->firstOrFail();
    expect(fn () => app(DeleteClient::class)->handle($demoOp, $demo, $demoClient))->toThrow(AuthorizationException::class);

    // The owner can (appointment case; the others have their own tests).
    app(DeleteBooking::class)->handle($owner, $salon, $booking);
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeFalse();
});

it('deletes a stylist SOLO: their upcoming appointments are kept intact, only their own salon links go', function () {
    Queue::fake();
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();
    $other = pdSalonWithUpcomingBooking(); // second salon, untouched throughout

    // No acknowledgment needed any more — nothing destructive happens to bookings.
    $accountDeleted = app(PurgeStaffMember::class)->handle($owner, $salon, $membership);

    expect($accountDeleted)->toBeTrue();

    // The APPOINTMENT IS KEPT, its item still pointing at the stylist.
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();
    expect(DB::table('booking_items')->where('booking_id', $booking->id)->where('stylist_id', $stylist->id)->exists())->toBeTrue();

    // The account tombstones (soft delete — the kept appointment's name
    // snapshot); it is NEVER force-deleted.
    expect(User::whereKey($stylist->id)->exists())->toBeFalse();
    expect(User::withTrashed()->whereKey($stylist->id)->exists())->toBeTrue();

    // Their own salon links are gone — pure FK cleanup, not appointments.
    expect(Availability::withoutGlobalScopes()->where('user_id', $stylist->id)->exists())->toBeFalse();
    expect(DB::table('service_stylist')->where('user_id', $stylist->id)->exists())->toBeFalse();
    expect($salon->memberships()->where('user_id', $stylist->id)->exists())->toBeFalse();

    // Nothing was pushed to GHL — no appointment changed.
    Queue::assertNotPushed(CancelGhlAppointmentRemotely::class);

    // The second salon kept everything.
    expect(Booking::withoutGlobalScopes()->whereKey($other['booking']->id)->exists())->toBeTrue();
    expect(User::whereKey($other['stylist']->id)->exists())->toBeTrue();
});

it('deletes a cross-salon member from ONE salon only: the account stays live for the other salon', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $second = bookingSalon();
    stylistOf($second, $stylist);

    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();
    $accountDeleted = app(PurgeStaffMember::class)->handle($owner, $salon, $membership);

    expect($accountDeleted)->toBeFalse();
    expect(User::whereKey($stylist->id)->exists())->toBeTrue(); // not even soft-deleted
    expect($second->memberships()->where('user_id', $stylist->id)->exists())->toBeTrue();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue(); // kept here too
});

it('never deletes yourself, the last active owner, or across salons (IDOR)', function () {
    ['salon' => $salon, 'owner' => $owner] = pdSalonWithUpcomingBooking();
    $op = pdAgencyAdmin($salon);

    $ownMembership = $salon->memberships()->where('user_id', $owner->id)->first();
    expect(fn () => app(PurgeStaffMember::class)->handle($owner, $salon, $ownMembership))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(PurgeStaffMember::class)->handle($op, $salon, $ownMembership))
        ->toThrow(ValidationException::class);

    $foreign = pdSalonWithUpcomingBooking();
    $foreignMembership = $foreign['salon']->memberships()->where('user_id', $foreign['stylist']->id)->first();
    expect(fn () => app(PurgeStaffMember::class)->handle($owner, $salon, $foreignMembership))
        ->toThrow(AuthorizationException::class);
});

it('deletes a service SOLO: appointments that used it are kept, only the offer-pivot rows go', function () {
    Queue::fake();
    ['salon' => $salon, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $op = pdAgencyAdmin($salon);
    $other = pdSalonWithUpcomingBooking();

    // Upcoming appointments exist — the delete proceeds WITHOUT any
    // acknowledgment, because they are not deleted.
    app(DeleteService::class)->handle($op, $salon, $service);

    // The service tombstones: gone from the menu/pickers, row kept as the
    // name snapshot.
    expect(Service::whereKey($service->id)->exists())->toBeFalse();
    expect(Service::withTrashed()->whereKey($service->id)->exists())->toBeTrue();

    // Its APPOINTMENTS ARE KEPT, items still pointing at the service.
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();
    expect(DB::table('booking_items')->where('booking_id', $booking->id)->where('service_id', $service->id)->exists())->toBeTrue();

    // Pure link rows (who offers it) are FK cleanup — gone.
    expect(DB::table('service_stylist')->where('service_id', $service->id)->exists())->toBeFalse();

    // The public widget no longer lists it.
    $this->getJson(route('salon.widget.services', ['salon' => $salon->slug]))
        ->assertOk()
        ->assertDontSee($service->name);

    Queue::assertNotPushed(CancelGhlAppointmentRemotely::class);

    // The second salon's service and booking are untouched.
    expect(Service::whereKey($other['service']->id)->exists())->toBeTrue();
    expect(Booking::withoutGlobalScopes()->whereKey($other['booking']->id)->exists())->toBeTrue();
});

it('deletes a client SOLO with contact scrubbed and notes gone; deleting one appointment removes just that one', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();

    $target = CarbonImmutable::now($salon->timezone)->addDays(2);
    $keeper = makeBooking($salon, $owner, $stylist, $service, $target->setTime(14, 0)->format('Y-m-d H:i'), 'Keeper Kim');

    $client = $booking->client;
    $client->forceFill(['phone' => '+1 555 123', 'email' => 'p@example.test'])->save();
    DB::table('client_notes')->insert([
        'salon_id' => $salon->id, 'client_id' => $client->id, 'author_id' => $owner->id,
        'body' => 'note', 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(DeleteClient::class)->handle($owner, $salon, $client);

    // Tombstoned + scrubbed; out of the directory, name kept for the calendar.
    expect(Client::whereKey($client->id)->exists())->toBeFalse();
    $trashed = Client::withTrashed()->whereKey($client->id)->firstOrFail();
    expect($trashed->phone)->toBeNull();
    expect($trashed->email)->toBeNull();
    expect(DB::table('client_notes')->where('client_id', $client->id)->exists())->toBeFalse();

    // Their APPOINTMENT IS KEPT; the other client's too.
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();
    expect(Booking::withoutGlobalScopes()->whereKey($keeper->id)->exists())->toBeTrue();

    // Single-appointment delete stays precise.
    app(DeleteBooking::class)->handle($owner, $salon, $keeper);
    expect(Booking::withoutGlobalScopes()->whereKey($keeper->id)->exists())->toBeFalse();
    expect(Client::where('name', 'Keeper Kim')->exists())->toBeTrue();
});

it('still cancels a synced appointment on the GHL side when THAT appointment is deleted', function () {
    Queue::fake();
    ['salon' => $salon, 'owner' => $owner, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $booking->forceFill(['ghl_appointment_id' => 'ghl_evt_123'])->save();

    app(DeleteBooking::class)->handle($owner, $salon, $booking);

    Queue::assertPushed(CancelGhlAppointmentRemotely::class, fn (CancelGhlAppointmentRemotely $job) => $job->salonId === $salon->id && $job->ghlAppointmentId === 'ghl_evt_123');
});

it('renders kept appointments without error, marking tombstoned names (removed) on the lists and calendar', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $op = pdAgencyAdmin($salon);
    $clientName = $booking->client->name;

    app(DeleteClient::class)->handle($owner, $salon, $booking->client);
    app(DeleteService::class)->handle($op, $salon, $service);
    app(PurgeStaffMember::class)->handle($owner, $salon, $salon->memberships()->where('user_id', $stylist->id)->first());

    // All three tombstoned — the kept appointment still renders everywhere.
    $this->actingAs($owner)->get(route('salon.appointments.all', $salon))
        ->assertOk()
        ->assertSee($clientName)
        ->assertSee($service->name)
        ->assertSee($stylist->name)
        ->assertSee(__('(removed)'));

    $this->actingAs($owner)->get(route('salon.calendar', $salon))->assertOk();
    $this->actingAs($owner)->get(route('salon.appointments', $salon))->assertOk();
});

it('confirms with KEPT copy — no acknowledgment step — and the modal deletes solo', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking] = pdSalonWithUpcomingBooking();
    $membership = $salon->memberships()->where('user_id', $stylist->id)->first();

    // Staff modal: says kept, never "also deleted"; confirm works directly.
    Livewire::actingAs($owner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('startDelete', $membership->id)
        ->assertSet('showDelete', true)
        ->assertSee(__('Their :count appointment is KEPT — nothing on the calendar is deleted.', ['count' => 1]))
        ->assertDontSee(__('Also deleted'))
        ->call('confirmDelete')
        ->assertHasNoErrors();
    expect($salon->memberships()->where('user_id', $stylist->id)->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();

    // Service modal.
    Livewire::actingAs($owner)
        ->test('pages::salon.services.index', ['salon' => $salon])
        ->call('startDelete', $service->id)
        ->assertSee(__('The :count appointment that used it is KEPT — nothing on the calendar is deleted.', ['count' => 1]))
        ->assertDontSee(__('Also deleted'))
        ->call('confirmDelete')
        ->assertHasNoErrors();
    expect(Service::whereKey($service->id)->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();

    // Client modal.
    Livewire::actingAs($owner)
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->call('startEdit', $booking->client_id)
        ->call('startDelete')
        ->assertSee(__('Their :count appointment is KEPT — nothing on the calendar is deleted.', ['count' => 1]))
        ->assertDontSee(__('Also deleted'))
        ->call('confirmDelete')
        ->assertHasNoErrors();
    expect(Client::whereKey($booking->client_id)->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeTrue();
});

it('hides every delete affordance from managers', function () {
    ['salon' => $salon] = pdSalonWithUpcomingBooking();
    $manager = managerOf($salon);

    $this->actingAs($manager)->get(route('salon.clients', $salon))->assertOk()->assertDontSee(__('Delete permanently'));
    $this->actingAs($manager)->get(route('salon.appointments.all', $salon))->assertOk()->assertDontSee(__('Delete permanently'));

    $owner = $salon->memberships()->where('salon_role', SalonRole::Owner->value)->first()->user;
    $this->actingAs($owner)->get(route('salon.appointments.all', $salon))->assertOk()->assertSee(__('Delete permanently'));
});
