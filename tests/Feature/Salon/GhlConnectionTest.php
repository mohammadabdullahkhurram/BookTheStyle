<?php

use App\Actions\Salons\CreateSalon;
use App\Actions\Salons\UpdateGhlConnection;
use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\User;

/*
| Phase-6 groundwork: per-salon GoHighLevel connection credentials. Storage,
| the write-only token, the connection-status logic, and who may manage it.
| (No GHL API calls here — that is Phase 6.)
*/

it('stores and round-trips the connection through the action', function () {
    $salon = Salon::factory()->create();

    $connection = app(UpdateGhlConnection::class)->handle($salon, [
        'location_id' => 'loc_123',
        'calendar_id' => 'cal_123',
        'private_integration_token' => 'pit-secret',
    ]);

    expect($connection->location_id)->toBe('loc_123');
    expect($connection->calendar_id)->toBe('cal_123');
    expect($connection->private_integration_token)->toBe('pit-secret');
    expect($connection->connected_at)->not->toBeNull();
    expect($salon->fresh()->ghlConnected())->toBeTrue();
});

it('keeps the existing token when the token input is blank', function () {
    $salon = Salon::factory()->create();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_old',
        'private_integration_token' => 'pit-original',
        'calendar_id' => 'cal_old',
    ]);

    // Update the other fields, leaving the token blank.
    app(UpdateGhlConnection::class)->handle($salon, [
        'location_id' => 'loc_new',
        'calendar_id' => 'cal_new',
        'private_integration_token' => '',
    ]);

    $connection = $salon->fresh()->ghlConnection;
    expect($connection->location_id)->toBe('loc_new');
    expect($connection->private_integration_token)->toBe('pit-original'); // unchanged
});

it('stamps connected_at the first time a token is saved', function () {
    $salon = Salon::factory()->create();

    // Location only, no token → not yet connected, no timestamp.
    $connection = app(UpdateGhlConnection::class)->handle($salon, [
        'location_id' => 'loc_1',
        'calendar_id' => 'cal_1',
        'private_integration_token' => '',
    ]);
    expect($connection->connected_at)->toBeNull();
    expect($connection->isConnected())->toBeFalse();

    // Now add the token → connected_at stamped.
    $connection = app(UpdateGhlConnection::class)->handle($salon, [
        'location_id' => 'loc_1',
        'calendar_id' => 'cal_1',
        'private_integration_token' => 'pit-now',
    ]);
    expect($connection->connected_at)->not->toBeNull();
    expect($connection->isConnected())->toBeTrue();
});

it('reports status from the three fields', function () {
    expect(SalonGhlConnection::factory()->unconnected()->create()->status())->toBe('not_connected');

    expect(SalonGhlConnection::factory()->unconnected()->create(['location_id' => 'loc'])->status())
        ->toBe('incomplete');

    expect(SalonGhlConnection::factory()->create()->status())->toBe('connected');
});

it('creates a salon with no GHL fields and no connection row', function () {
    $agency = Agency::factory()->create();

    $salon = app(CreateSalon::class)->handle($agency, [
        'name' => 'No GHL Salon',
        'slug' => 'no-ghl-salon',
        'timezone' => 'America/New_York',
    ]);

    expect($salon->exists)->toBeTrue();
    expect($salon->ghlConnection)->toBeNull();
});

it('creates the connection when GHL fields are supplied at creation', function () {
    $agency = Agency::factory()->create();

    $salon = app(CreateSalon::class)->handle($agency, [
        'name' => 'GHL Salon',
        'slug' => 'ghl-salon',
        'timezone' => 'America/New_York',
        'ghl_location_id' => 'loc_create',
        'ghl_calendar_id' => 'cal_create',
        'ghl_token' => 'pit-create',
    ]);

    $connection = $salon->ghlConnection;
    expect($connection)->not->toBeNull();
    expect($connection->location_id)->toBe('loc_create');
    expect($connection->private_integration_token)->toBe('pit-create');
});

it('lets salon and agency managers manage the connection, but not staff or agency users', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create();

    $agencyOwner = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Owner]);
    $agencyAdmin = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::Admin]);
    $agencyUser = User::factory()->create(['agency_id' => $agency->id, 'agency_role' => AgencyRole::User]);
    $agencyUser->assignedSalons()->attach($salon);

    // Allowed: agency owner/admin + salon owner/admin.
    expect($agencyOwner->can('manageGhlConnection', $salon))->toBeTrue();
    expect($agencyAdmin->can('manageGhlConnection', $salon))->toBeTrue();
    expect(salonOwnerOf($salon)->can('manageGhlConnection', $salon))->toBeTrue();
    expect(salonAdminOf($salon)->can('manageGhlConnection', $salon))->toBeTrue();

    // Denied: salon staff and agency users never touch credentials.
    expect($agencyUser->can('manageGhlConnection', $salon))->toBeFalse();
    expect(stylistOf($salon)->can('manageGhlConnection', $salon))->toBeFalse();
    expect(frontDeskOf($salon)->can('manageGhlConnection', $salon))->toBeFalse();
});

it('forbids an agency admin from managing another agency\'s salon connection', function () {
    $agencyA = Agency::factory()->create();
    $agencyB = Agency::factory()->create();
    $salonB = Salon::factory()->for($agencyB)->create();

    $adminA = User::factory()->create(['agency_id' => $agencyA->id, 'agency_role' => AgencyRole::Admin]);

    expect($adminA->can('manageGhlConnection', $salonB))->toBeFalse();
});
