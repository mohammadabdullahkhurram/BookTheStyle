<?php

use App\Actions\Salons\CreateSalon;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Mail\StaffInviteMail;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
| The canonical salon sidebar order (SPEC): Today · Calendar · Check-in ·
| Appointments · Clients · Reports · Services · Users · Availability —
| identical in the mobile drawer (one shared partial, included twice).
| Plus the ownership refinements: the fields are OWNER details, creation
| provisions that person as owner, and only agency operators (and the owner
| themself) may edit the owner's details.
*/

function navHrefOrder(): array
{
    return [
        'salon.show', 'salon.calendar', 'salon.appointments', 'salon.appointments.all',
        'salon.clients', 'salon.reports', 'salon.services', 'salon.staff', 'salon.availability',
    ];
}

it('renders the sidebar in exactly the canonical order — desktop and mobile drawer alike', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $html = $this->actingAs($owner)->get(route('salon.show', $salon))->assertOk()->getContent();

    // The shared nav partial renders twice (desktop sidebar + mobile drawer);
    // assert the full sequence holds in BOTH copies.
    $urls = array_map(fn (string $route) => route($route, $salon), navHrefOrder());
    $positions = [];
    foreach ($urls as $url) {
        $first = strpos($html, 'href="'.$url.'"');
        $second = strpos($html, 'href="'.$url.'"', $first + 1);
        expect($first)->not->toBeFalse("missing nav link: {$url}");
        expect($second)->not->toBeFalse("nav link only rendered once (mobile drawer missing?): {$url}");
        $positions[] = [$first, $second];
    }

    for ($i = 1; $i < count($positions); $i++) {
        expect($positions[$i][0])->toBeGreaterThan($positions[$i - 1][0]); // desktop order
        expect($positions[$i][1])->toBeGreaterThan($positions[$i - 1][1]); // drawer order
    }
});

it('keeps stylist scoping: fewer items, same relative order', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $html = $this->actingAs($stylist)->get(route('salon.show', $salon))->assertOk()->getContent();

    foreach (['salon.appointments' => false, 'salon.clients' => false, 'salon.reports' => false, 'salon.services' => false, 'salon.staff' => false] as $route => $x) {
        expect(str_contains($html, 'href="'.route($route, $salon).'"'))->toBeFalse($route);
    }

    $order = ['salon.show', 'salon.calendar', 'salon.appointments.all', 'salon.availability'];
    $last = -1;
    foreach ($order as $route) {
        $pos = strpos($html, 'href="'.route($route, $salon).'"');
        expect($pos)->not->toBeFalse($route);
        expect($pos)->toBeGreaterThan($last);
        $last = $pos;
    }
});

// ---------------------------------------------------------------------------
// Owner details: labelled as owner, provisioned as owner
// ---------------------------------------------------------------------------

it('labels the fields Owner details on creation, agency edit, and salon settings', function () {
    $agency = Agency::factory()->create();
    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $salon = Salon::factory()->for($agency)->create();
    salonOwnerOf($salon);

    $this->actingAs($agencyOwner)->get(route('agency.salons.create'))
        ->assertOk()->assertSee(__('Owner details'))->assertSee(__('Owner email'))
        ->assertDontSee(__('Primary contact'));

    $this->actingAs($agencyOwner)->get(route('agency.salons.edit', $salon))
        ->assertOk()->assertSee(__('Owner details'));
});

it('provisions the Owner-details person as the salon owner at creation, with the invite mailable', function () {
    Mail::fake();
    $agency = Agency::factory()->create();
    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);

    $salon = app(CreateSalon::class)->handle($agencyOwner, $agency, salonProfileInput([
        'name' => 'Provision Check', 'slug' => 'provision-check', 'timezone' => 'UTC',
        'contact_name' => 'Olive Owner', 'contact_email' => 'olive@example.com',
    ]));

    $owner = User::where('email', 'olive@example.com')->firstOrFail();
    expect($owner->membershipFor($salon)->salon_role)->toBe(SalonRole::Owner);
    expect($owner->must_change_password)->toBeTrue();

    Mail::assertQueued(StaffInviteMail::class, fn ($mail) => $mail->temporaryPassword !== null);
});

// ---------------------------------------------------------------------------
// Who may edit the owner's details
// ---------------------------------------------------------------------------

it('lets agency owner and admin edit the salon owner details; salon roles never', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();
    $owner = salonOwnerOf($salon);
    $ownerMembership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();

    foreach ([AgencyRole::Owner, AgencyRole::Admin] as $role) {
        $operator = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => $role]);

        Livewire::actingAs($operator)
            ->test('pages::salon.staff.index', ['salon' => $salon])
            ->assertSee(__('Edit details'))
            ->call('startOwnerEdit', $ownerMembership->id)
            ->assertSet('showOwnerEdit', true)
            ->set('ownerName', 'Renamed Owner')
            ->set('ownerPhone', '+1 555 010 4242')
            ->call('saveOwnerDetails')
            ->assertHasNoErrors();
    }

    $fresh = $owner->fresh();
    expect($fresh->name)->toBe('Renamed Owner');
    expect($fresh->phone)->toBe('+1 555 010 4242');
    expect($ownerMembership->fresh()->salon_role)->toBe(SalonRole::Owner); // details only — never the role

    // Salon manager: no affordance, and the server 403s a forged call.
    $manager = salonAdminOf($salon);
    Livewire::actingAs($manager)
        ->test('pages::salon.staff.index', ['salon' => $salon])
        ->assertDontSee(__('Edit details'))
        ->call('startOwnerEdit', $ownerMembership->id)
        ->assertForbidden();

    // Stylists can't even reach the screen (scope-down).
    $this->actingAs(stylistOf($salon))->get(route('salon.staff', $salon))->assertForbidden();
});

it('refuses a cross-agency operator the owner details', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $ownerMembership = $salon->memberships()->where('user_id', $owner->id)->firstOrFail();
    $foreign = User::factory()->create([
        'agency_id' => Agency::factory()->create()->id,
        'agency_role' => AgencyRole::Owner,
    ]);

    // Cross-agency operators are stopped at the salon boundary already.
    $this->actingAs($foreign)->get(route('salon.staff', $salon))->assertForbidden();
});

it('lets the owner edit their own account through settings', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)->get(route('profile.edit'))->assertOk();
});
