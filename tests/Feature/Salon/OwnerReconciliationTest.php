<?php

use App\Actions\Availability\SaveWeeklyHours;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Salons\CreateSalon;
use App\Actions\Salons\ReconcileSalonOwner;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\SalonType;
use App\Enums\StaffType;
use App\Enums\StylistArrangement;
use App\Mail\StaffInviteMail;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| The Owner details on the salon profile are the single source of truth for
| who the owner is: saving provisions a missing owner, syncs details, or
| transfers on an email change — never two owners. The "owner is also a
| stylist" checkbox is the settled owner-who-cuts-hair answer: bookability
| via staff_type, not a second role.
*/

function reconcileAgency(): array
{
    $agency = Agency::factory()->create();
    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    return [$agency, $agencyOwner];
}

function ownerlessSalonWithDetails(Agency $agency): Salon
{
    return Salon::factory()->for($agency)->create([
        'contact_name' => 'Abdullah Salon Owner',
        'contact_email' => 'owner@example.com',
        'contact_phone' => '+1 555 010 0001',
    ]);
}

function activeOwnerCount(Salon $salon): int
{
    return $salon->memberships()
        ->where('salon_role', SalonRole::Owner->value)
        ->where('active', true)->count();
}

// ---------------------------------------------------------------------------
// Reconciliation: provision, sync, transfer
// ---------------------------------------------------------------------------

it('provisions the owner from Owner details on an ownerless salon — the production fix', function () {
    Mail::fake();
    [$agency, $agencyOwner] = reconcileAgency();
    $salon = ownerlessSalonWithDetails($agency);
    expect(activeOwnerCount($salon))->toBe(0);

    $result = app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon, false);

    expect($result)->not->toBeNull();
    expect($result->temporaryPassword)->not->toBeNull();
    Mail::assertQueued(StaffInviteMail::class);

    $owner = User::where('email', 'owner@example.com')->firstOrFail();
    expect($owner->name)->toBe('Abdullah Salon Owner');
    expect($owner->phone)->toBe('+1 555 010 0001');
    expect(activeOwnerCount($salon))->toBe(1);

    // …and they appear in the Users list.
    Livewire::actingAs($agencyOwner)
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->assertSee('Abdullah Salon Owner')
        ->assertSee(__('Owner'));
});

it('provisions through the settings profile save end to end', function () {
    Mail::fake();
    [$agency, $agencyOwner] = reconcileAgency();
    $salon = ownerlessSalonWithDetails($agency);

    Livewire::actingAs($agencyOwner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('showOwnerTempPassword', true);

    expect(activeOwnerCount($salon))->toBe(1);
});

it('syncs name and phone when the email is unchanged — for authorized actors only', function () {
    Mail::fake();
    [$agency, $agencyOwner] = reconcileAgency();
    $salon = ownerlessSalonWithDetails($agency);
    app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon, false);
    $owner = User::where('email', 'owner@example.com')->firstOrFail();

    // Agency operator: sync applies.
    $salon->update(['contact_name' => 'Renamed Owner', 'contact_phone' => '+1 555 010 9999']);
    app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon->fresh(), false);
    expect($owner->fresh()->name)->toBe('Renamed Owner');
    expect($owner->fresh()->phone)->toBe('+1 555 010 9999');

    // The owner themself: allowed too.
    $salon->update(['contact_name' => 'Self Renamed']);
    app(ReconcileSalonOwner::class)->handle($owner->fresh(), $salon->fresh(), false);
    expect($owner->fresh()->name)->toBe('Self Renamed');

    // A salon manager: their save must not touch the owner's record.
    $manager = salonAdminOf($salon);
    $salon->update(['contact_name' => 'Manager Was Here']);
    expect(fn () => app(ReconcileSalonOwner::class)->handle($manager, $salon->fresh(), false))
        ->toThrow(ValidationException::class);
    expect($owner->fresh()->name)->toBe('Self Renamed');
});

it('treats an email change as a transfer: agency owner only, never two owners', function () {
    Mail::fake();
    [$agency, $agencyOwner] = reconcileAgency();
    $salon = ownerlessSalonWithDetails($agency);
    app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon, false);
    $oldOwner = User::where('email', 'owner@example.com')->firstOrFail();

    // Agency owner changes the email → transfer; the incumbent is demoted.
    $salon->update(['contact_name' => 'Nadia New', 'contact_email' => 'nadia@example.com']);
    $result = app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon->fresh(), false);

    expect($result->user->email)->toBe('nadia@example.com');
    expect(activeOwnerCount($salon))->toBe(1);
    expect($oldOwner->fresh()->membershipFor($salon)->salon_role)->toBe(SalonRole::Manager);

    // A salon manager (or anyone else) cannot transfer via the profile.
    $salon->update(['contact_email' => 'sneak@example.com']);
    expect(fn () => app(ReconcileSalonOwner::class)->handle(salonAdminOf($salon), $salon->fresh(), false))
        ->toThrow(AuthorizationException::class);
    expect(activeOwnerCount($salon))->toBe(1);
});

// ---------------------------------------------------------------------------
// Owner-as-stylist: the settled owner-who-cuts-hair answer
// ---------------------------------------------------------------------------

it('makes the owner bookable via the checkbox while keeping full owner rights — in every salon type', function () {
    Mail::fake();
    foreach (SalonType::cases() as $type) {
        [$agency, $agencyOwner] = reconcileAgency();
        $salon = Salon::factory()->for($agency)->create([
            'salon_type' => $type,
            'contact_name' => 'Bookable Owner',
            'contact_email' => 'bookable-'.$type->value.'@example.com',
            'contact_phone' => '+1 555 010 0002',
        ]);

        app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon, true);

        $owner = User::where('email', 'bookable-'.$type->value.'@example.com')->firstOrFail();
        $membership = $owner->membershipFor($salon);
        expect($membership->salon_role)->toBe(SalonRole::Owner);
        expect($membership->staff_type)->toBe(StaffType::Stylist);

        // Bookable: in the stylist pool; owner rights intact (full surface).
        expect($salon->stylistUsers()->pluck('users.id')->all())->toContain($owner->id);
        expect($owner->can('manage', $salon))->toBeTrue();
        expect($owner->can('manageBookings', $salon))->toBeTrue();
    }
});

it('provisions a bookable owner straight from salon creation', function () {
    Mail::fake();
    [$agency, $agencyOwner] = reconcileAgency();

    $salon = app(CreateSalon::class)->handle($agencyOwner, $agency, salonProfileInput([
        'name' => 'Cutting Owner', 'slug' => 'cutting-owner', 'timezone' => 'UTC',
        'contact_name' => 'Olive Owner', 'contact_email' => 'olive@example.com',
        'owner_is_stylist' => true,
    ]));

    $owner = User::where('email', 'olive@example.com')->firstOrFail();
    expect($owner->membershipFor($salon)->staff_type)->toBe(StaffType::Stylist);
});

it('refuses unchecking while the owner has upcoming bookings — nothing orphaned', function () {
    Mail::fake();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));

    [$agency, $agencyOwner] = reconcileAgency();
    $salon = bookingSalon([
        'agency_id' => $agency->id,
        'contact_name' => 'Busy Owner', 'contact_email' => 'busy@example.com',
        'contact_phone' => '+1 555 010 0003',
    ]);
    app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon, true);
    $owner = User::where('email', 'busy@example.com')->firstOrFail();

    // Give the bookable owner hours + an upcoming booking.
    app(SaveWeeklyHours::class)
        ->handle($agencyOwner, $salon, $owner->id, [0 => [['start_minute' => 9 * 60, 'end_minute' => 17 * 60]]]);
    $service = serviceFor($salon, $owner, 60);
    app(CreateBooking::class)->handle($agencyOwner, $salon, [
        'client' => ['name' => 'Client'],
        'items' => [['service_id' => $service->id, 'stylist_id' => $owner->id]],
        'start' => '2026-06-22 14:00', 'is_walkin' => false, 'notes' => null,
    ]);

    // Unchecking now is refused, with the flag intact…
    expect(fn () => app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon->fresh(), false))
        ->toThrow(ValidationException::class);
    expect($owner->fresh()->membershipFor($salon)->staff_type)->toBe(StaffType::Stylist);

    // …and once the booking is in the past, unchecking works.
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-23 12:00:00', 'UTC'));
    app(ReconcileSalonOwner::class)->handle($agencyOwner, $salon->fresh(), false);
    expect($owner->fresh()->membershipFor($salon)->staff_type)->toBeNull();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Salon type editability (agency-level)
// ---------------------------------------------------------------------------

it('lets agency operators edit the salon type; a salon manager cannot even reach the screen', function () {
    [$agency, $agencyOwner] = reconcileAgency();
    $salon = Salon::factory()->for($agency)->create(['salon_type' => SalonType::Employee]);
    $stylist = stylistOf($salon);

    Livewire::actingAs($agencyOwner)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->assertSee(__('Salon type'))
        ->set('salonTypeChoice', 'booth_rental')
        ->call('changeSalonType')
        ->assertHasNoErrors();

    expect($salon->fresh()->salon_type)->toBe(SalonType::BoothRental);
    expect($stylist->membershipFor($salon)->arrangement)->toBe(StylistArrangement::BoothRental);

    // Salon managers: the agency edit screen 403s outright.
    $this->actingAs(salonAdminOf($salon))
        ->get(route('agency.salons.edit', $salon))
        ->assertForbidden();
});
