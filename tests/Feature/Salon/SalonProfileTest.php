<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\SalonProfile;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/*
| Per-salon business + point-of-contact profile: required on create, editable by
| the permitted roles, optional website/address-line-2, and independent of the
| (optional) GoHighLevel connection.
*/

function agencyOwnerOf(Agency $agency): User
{
    return User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
}

it('requires every business and contact field to create a salon', function (string $field) {
    $agency = Agency::factory()->create();

    Livewire::actingAs(agencyOwnerOf($agency))
        ->test('pages::agency.salons.create')
        ->set(salonProfileInput([$field => '']))
        ->set('slug', 'incomplete-salon')
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertHasErrors($field);

    expect(Salon::where('slug', 'incomplete-salon')->exists())->toBeFalse();
})->with([
    'name', 'legal_business_name', 'business_email', 'business_phone',
    'address_line1', 'city', 'region', 'postal_code', 'country',
    'contact_name', 'contact_email', 'contact_phone',
]);

it('creates a salon with a complete profile (GHL connection independent + optional)', function () {
    $agency = Agency::factory()->create();

    Livewire::actingAs(agencyOwnerOf($agency))
        ->test('pages::agency.salons.create')
        ->set(salonProfileInput([
            'name' => 'Full Salon',
            'legal_business_name' => 'Full Salon Ltd',
            'website' => 'https://full.test',
            'address_line2' => 'Floor 3',
            'country' => 'United Kingdom',
        ]))
        ->set('slug', 'full-salon')
        ->set('timezone', 'Europe/London')
        ->call('save')
        ->assertHasNoErrors();

    $salon = Salon::where('slug', 'full-salon')->first();
    expect($salon)->not->toBeNull();
    expect($salon->legal_business_name)->toBe('Full Salon Ltd');
    expect($salon->website)->toBe('https://full.test');
    expect($salon->address_line2)->toBe('Floor 3');
    expect($salon->country)->toBe('United Kingdom');
    expect($salon->contact_email)->toBe('contact@glow-bar.test');
    // No GHL fields supplied → no connection row; the profile stands alone.
    expect($salon->ghlConnection)->toBeNull();
});

it('allows blank website and address line 2', function () {
    $agency = Agency::factory()->create();

    Livewire::actingAs(agencyOwnerOf($agency))
        ->test('pages::agency.salons.create')
        ->set(salonProfileInput(['name' => 'Lean Salon', 'website' => '', 'address_line2' => '']))
        ->set('slug', 'lean-salon')
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertHasNoErrors();

    $salon = Salon::where('slug', 'lean-salon')->first();
    expect($salon->website)->toBeNull();
    expect($salon->address_line2)->toBeNull();
});

it('validates email and website formats', function () {
    $agency = Agency::factory()->create();

    Livewire::actingAs(agencyOwnerOf($agency))
        ->test('pages::agency.salons.create')
        ->set(salonProfileInput(['business_email' => 'not-an-email', 'website' => 'not a url']))
        ->set('slug', 'bad-format-salon')
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertHasErrors(['business_email', 'website']);
});

it('scopes profile management to salon and agency managers', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();

    $agencyUser = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);
    $agencyUser->assignedSalons()->attach($salon);
    $otherAdmin = User::factory()->create([
        'agency_id' => Agency::factory()->create()->id, 'agency_role' => AgencyRole::Admin,
    ]);

    // Allowed.
    expect(salonOwnerOf($salon)->can('manageProfile', $salon))->toBeTrue();
    expect(salonAdminOf($salon)->can('manageProfile', $salon))->toBeTrue();
    expect(agencyOwnerOf($agency)->can('manageProfile', $salon))->toBeTrue();
    expect(User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin])
        ->can('manageProfile', $salon))->toBeTrue();

    // Denied: salon staff, agency users, and other agencies.
    expect(stylistOf($salon)->can('manageProfile', $salon))->toBeFalse();
    expect(frontDeskOf($salon)->can('manageProfile', $salon))->toBeFalse();
    expect($agencyUser->can('manageProfile', $salon))->toBeFalse();
    expect($otherAdmin->can('manageProfile', $salon))->toBeFalse();
});

it('lets a salon owner edit the profile via settings', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('legal_business_name', 'Updated Legal LLC')
        ->set('contact_name', 'New Contact')
        ->call('saveProfile')
        ->assertHasNoErrors();

    expect($salon->fresh()->legal_business_name)->toBe('Updated Legal LLC');
    expect($salon->fresh()->contact_name)->toBe('New Contact');
});

it('hides the profile card and forbids the save for an agency user in settings', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();
    $agencyUser = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);
    $agencyUser->assignedSalons()->attach($salon);

    $this->actingAs($agencyUser)->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertDontSee('Business profile');

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('legal_business_name', 'Sneaky LLC')
        ->call('saveProfile')
        ->assertForbidden();
});

it('keeps profile fields required on edit (cannot blank them)', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('business_email', '')
        ->call('saveProfile')
        ->assertHasErrors('business_email');

    expect($salon->fresh()->business_email)->not->toBe('');
});

it('lets an agency admin edit the profile from the console, but not another agency', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);

    Livewire::actingAs($admin)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('legal_business_name', 'Console Legal LLC')
        ->set('city', 'Chicago')
        ->call('save')
        ->assertHasNoErrors();

    expect($salon->fresh()->legal_business_name)->toBe('Console Legal LLC');
    expect($salon->fresh()->city)->toBe('Chicago');

    // Tenant isolation: another agency's salon edit screen is forbidden.
    $salonB = Salon::factory()->create();
    $this->actingAs($admin)->get(route('agency.salons.edit', $salonB))->assertForbidden();
});

it('produces a complete, valid profile from the factory', function () {
    $salon = Salon::factory()->create();

    foreach (['legal_business_name', 'business_email', 'business_phone', 'address_line1',
        'city', 'region', 'postal_code', 'country', 'contact_name', 'contact_email', 'contact_phone'] as $field) {
        expect($salon->{$field})->not->toBe('');
    }

    // Passes the same rules the create/edit forms enforce.
    $validator = Validator::make($salon->only(array_keys(SalonProfile::rules())), SalonProfile::rules());
    expect($validator->fails())->toBeFalse();
});
