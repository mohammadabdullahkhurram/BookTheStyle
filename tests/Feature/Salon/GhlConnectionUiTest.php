<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\User;
use Livewire\Livewire;

/*
| The GoHighLevel connection UI on the salon-settings and agency-edit screens:
| who may edit it, that the token is write-only/masked, and that the stored
| token is never rendered back into the page.
*/

it('lets a salon owner save the connection through settings', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('ghlLocationId', 'loc_abc')
        ->set('ghlCalendarId', 'cal_abc')
        ->set('ghlToken', 'pit-from-ui')
        ->call('saveGhlConnection')
        ->assertHasNoErrors()
        ->assertSet('ghlToken', '')        // cleared after save (write-only)
        ->assertSet('ghlStatus', 'connected');

    $connection = $salon->fresh()->ghlConnection;
    expect($connection->location_id)->toBe('loc_abc');
    expect($connection->private_integration_token)->toBe('pit-from-ui');
});

it('preserves the existing token when saved blank through settings', function () {
    $salon = Salon::factory()->create();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_old',
        'private_integration_token' => 'pit-original',
        'calendar_id' => 'cal_old',
    ]);
    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->assertSet('tokenIsSet', true)
        ->set('ghlLocationId', 'loc_changed')
        ->set('ghlToken', '')              // blank → keep existing
        ->call('saveGhlConnection')
        ->assertHasNoErrors();

    $connection = $salon->fresh()->ghlConnection;
    expect($connection->location_id)->toBe('loc_changed');
    expect($connection->private_integration_token)->toBe('pit-original');
});

it('never renders the stored token in the settings response', function () {
    $salon = Salon::factory()->create();
    SalonGhlConnection::factory()->for($salon)->create([
        'private_integration_token' => 'pit-super-secret',
    ]);
    $owner = salonOwnerOf($salon);
    $this->actingAs($owner);

    // Interactive component render.
    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->assertSet('ghlToken', '')
        ->assertDontSee('pit-super-secret')
        ->assertSee('Private integration token saved')
        ->assertSee('Connected');

    // Full page response too.
    $this->actingAs($owner)->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertDontSee('pit-super-secret');
});

it('hides the connection UI from an agency user who can otherwise open settings', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();

    $agencyUser = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);
    $agencyUser->assignedSalons()->attach($salon);

    // They can open settings (manage), but the GHL card is not rendered...
    $this->actingAs($agencyUser)->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertDontSee('GoHighLevel connection');

    // ...and the save action is denied server-side (403).
    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('ghlToken', 'pit-sneaky')
        ->call('saveGhlConnection')
        ->assertForbidden();

    expect($salon->fresh()->ghlConnection)->toBeNull();
});

it('lets an agency admin save the connection from the console edit screen', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
    $this->actingAs($admin);

    Livewire::test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('ghlLocationId', 'loc_console')
        ->set('ghlCalendarId', 'cal_console')
        ->set('ghlToken', 'pit-console')
        ->call('saveGhlConnection')
        ->assertHasNoErrors()
        ->assertSet('ghlToken', '');

    expect($salon->fresh()->ghlConnection->private_integration_token)->toBe('pit-console');
});

it('never renders the stored token in the agency edit response', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();
    SalonGhlConnection::factory()->for($salon)->create([
        'private_integration_token' => 'pit-super-secret',
    ]);
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);

    $this->actingAs($admin)->get(route('agency.salons.edit', $salon))
        ->assertOk()
        ->assertDontSee('pit-super-secret');
});

it('creates a salon with GHL fields from the console create screen', function () {
    $agency = Agency::factory()->create();
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
    $this->actingAs($admin);

    Livewire::test('pages::agency.salons.create')
        ->set(salonProfileInput(['name' => 'Connected Salon']))
        ->set('slug', 'connected-salon')
        ->set('timezone', 'America/New_York')
        ->set('ghl_location_id', 'loc_new')
        ->set('ghl_token', 'pit-new')
        ->call('save')
        ->assertHasNoErrors();

    $salon = Salon::where('slug', 'connected-salon')->first();
    expect($salon->ghlConnection->location_id)->toBe('loc_new');
    expect($salon->ghlConnection->private_integration_token)->toBe('pit-new');
});

/*
| Required-scopes guidance: wherever a token is entered or rotated, the card
| lists the GHL scopes to grant, rendered from the single config source.
*/

it('lists the required GHL scopes on the salon-settings connection card', function () {
    $salon = Salon::factory()->create();
    // Token already set (rotation state) — the scopes must still show.
    SalonGhlConnection::factory()->for($salon)->create([
        'private_integration_token' => 'pit-existing',
    ]);

    $response = $this->actingAs(salonOwnerOf($salon))
        ->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertSee('Required scopes')
        ->assertSee('grant these scopes');

    foreach (config('ghl.required_scopes') as $scope) {
        $response->assertSee($scope);
    }
});

it('lists the required GHL scopes on the agency salon-edit connection card', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create(); // no token yet (entry state)
    $admin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);

    $response = $this->actingAs($admin)
        ->get(route('agency.salons.edit', $salon))
        ->assertOk()
        ->assertSee('Required scopes')
        ->assertSee('copy it immediately');

    foreach (config('ghl.required_scopes') as $scope) {
        $response->assertSee($scope);
    }
});

it('defines the required scopes once, in config/ghl.php', function () {
    expect(config('ghl.required_scopes'))->toBe([
        'calendars.readonly',
        'calendars.write',
        'calendars/events.readonly',
        'calendars/events.write',
        'calendars/groups.readonly',
        'contacts.readonly',
        'contacts.write',
        'users.readonly',
    ]);
});
