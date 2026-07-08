<?php

use App\Actions\Salons\TestGhlConnection;
use App\Actions\Salons\UpdateGhlStylistMapping;
use App\Enums\AgencyRole;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\StylistProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Phase 6a: test-connection verification, master-calendar selection and the
| stylist ↔ GHL team-member mapping — including the security properties:
| encrypted PIT at rest, owner/admin-only access, and tenant isolation.
| All GHL HTTP is faked.
*/

beforeEach(fn () => Http::preventStrayRequests());

function connectedSalon(string $locationId = 'loc_1'): Salon
{
    $salon = Salon::factory()->create();
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => $locationId,
        'private_integration_token' => 'pit-secret',
    ]);

    return $salon;
}

/**
 * @return array<string, mixed>
 */
function ghlLocationCalendars(string $locationId = 'loc_1'): array
{
    return ['calendars' => [[
        'id' => 'cal_1', 'name' => 'Master calendar', 'locationId' => $locationId,
        'teamMembers' => [['userId' => 'ghl_u1'], ['userId' => 'ghl_u2']],
    ]]];
}

// ---------------------------------------------------------------------------
// Storage security
// ---------------------------------------------------------------------------

it('stores the token as ciphertext at rest, never plaintext', function () {
    $salon = connectedSalon();

    $raw = DB::table('salon_ghl_connections')->where('salon_id', $salon->id)->value('private_integration_token');

    expect($raw)->not->toBeNull();
    expect($raw)->not->toContain('pit-secret');
    expect($salon->ghlConnection()->first()->private_integration_token)->toBe('pit-secret');
});

// ---------------------------------------------------------------------------
// Test connection
// ---------------------------------------------------------------------------

it('verifies a good connection and stamps last-verified', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(ghlLocationCalendars())]);
    $salon = connectedSalon();

    $check = app(TestGhlConnection::class)->handle($salon);

    expect($check->ok)->toBeTrue();
    expect($check->calendarCount)->toBe(1);
    expect($salon->ghlConnection()->first()->last_verified_at)->not->toBeNull();
});

it('fails cleanly on a rejected token and does not stamp verification', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(['message' => 'no'], 401)]);
    $salon = connectedSalon();

    $check = app(TestGhlConnection::class)->handle($salon);

    expect($check->ok)->toBeFalse();
    expect($check->message)->toContain('token');
    expect($check->message)->not->toContain('pit-secret');
    expect($salon->ghlConnection()->first()->last_verified_at)->toBeNull();
});

it('fails when the calendars belong to a different location', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(ghlLocationCalendars('loc_other'))]);
    $salon = connectedSalon('loc_1');

    $check = app(TestGhlConnection::class)->handle($salon);

    expect($check->ok)->toBeFalse();
    expect($check->message)->toContain('different location');
    expect($salon->ghlConnection()->first()->last_verified_at)->toBeNull();
});

it('fails cleanly when nothing is configured, without calling GHL', function () {
    $salon = Salon::factory()->create();

    $check = app(TestGhlConnection::class)->handle($salon);

    expect($check->ok)->toBeFalse();
    Http::assertNothingSent();
});

it('lets an owner test the connection from settings and shows last-verified', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(ghlLocationCalendars())]);
    $salon = connectedSalon();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('testGhlConnection')
        ->assertHasNoErrors()
        ->assertSet('ghlLastVerified', fn ($value) => $value !== null);
});

// ---------------------------------------------------------------------------
// Calendar selection + stylist mapping
// ---------------------------------------------------------------------------

it('loads the GHL directory and saves the master calendar and stylist mapping', function () {
    Http::fake([
        'services.leadconnectorhq.com/calendars/*' => Http::response(ghlLocationCalendars()),
        'services.leadconnectorhq.com/users/*' => Http::response(['users' => [
            ['id' => 'ghl_u1', 'name' => 'Ana', 'email' => 'ana@example.com'],
            ['id' => 'ghl_u2', 'name' => 'Ben', 'email' => 'ben@example.com'],
        ]]),
    ]);

    $salon = connectedSalon();
    $stylistA = stylistOf($salon);
    $stylistB = stylistOf($salon);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('loadGhlDirectory')
        ->assertSet('ghlDirectoryLoaded', true)
        ->set('ghlCalendarId', 'cal_1')
        ->set('ghlMap.'.$stylistA->id, 'ghl_u1')
        ->call('saveGhlMapping')
        ->assertHasNoErrors()
        // B stays visibly unmapped rather than disappearing.
        ->assertSet('ghlMap.'.$stylistB->id, '');

    expect($salon->ghlConnection()->first()->calendar_id)->toBe('cal_1');
    expect(StylistProfile::forSalon($salon)->where('user_id', $stylistA->id)->value('ghl_user_id'))->toBe('ghl_u1');
    expect(StylistProfile::forSalon($salon)->where('user_id', $stylistB->id)->value('ghl_user_id'))->toBeNull();
});

it('clears a mapping when set back to not mapped', function () {
    $salon = connectedSalon();
    $stylist = stylistOf($salon);

    app(UpdateGhlStylistMapping::class)->handle($salon, 'cal_1', [$stylist->id => 'ghl_u1']);
    expect(StylistProfile::forSalon($salon)->where('user_id', $stylist->id)->value('ghl_user_id'))->toBe('ghl_u1');

    app(UpdateGhlStylistMapping::class)->handle($salon, null, [$stylist->id => '']);
    expect(StylistProfile::forSalon($salon)->where('user_id', $stylist->id)->value('ghl_user_id'))->toBeNull();
});

it('rejects mapping a non-stylist member', function () {
    $salon = connectedSalon();
    $frontDesk = frontDeskOf($salon);

    expect(fn () => app(UpdateGhlStylistMapping::class)->handle($salon, null, [$frontDesk->id => 'ghl_u1']))
        ->toThrow(ValidationException::class);
});

it('rejects mapping before a connection exists', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    expect(fn () => app(UpdateGhlStylistMapping::class)->handle($salon, null, [$stylist->id => 'ghl_u1']))
        ->toThrow(ValidationException::class);
});

it('rejects a forged stylist id from another salon (tenant isolation)', function () {
    $salonA = connectedSalon();
    $salonB = Salon::factory()->create();
    $stylistB = stylistOf($salonB);

    expect(fn () => app(UpdateGhlStylistMapping::class)->handle($salonA, null, [$stylistB->id => 'ghl_u1']))
        ->toThrow(ValidationException::class);

    expect(StylistProfile::forSalon($salonB)->where('user_id', $stylistB->id)->value('ghl_user_id'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('forbids an assigned agency user from testing or mapping despite settings access', function () {
    $salon = connectedSalon();
    $agencyUser = User::factory()->create([
        'agency_id' => $salon->agency_id,
        'agency_role' => AgencyRole::User,
    ]);
    $agencyUser->assignedSalons()->attach($salon);

    Livewire::actingAs($agencyUser)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('testGhlConnection')
        ->assertForbidden();

    Livewire::actingAs($agencyUser)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('saveGhlMapping')
        ->assertForbidden();
});

it('keeps another salon\'s owner out of this salon\'s settings entirely', function () {
    $salonA = connectedSalon();
    $salonB = Salon::factory()->create();

    $this->actingAs(salonOwnerOf($salonB))
        ->get(route('salon.settings', $salonA))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Migration is additive
// ---------------------------------------------------------------------------

it('leaves existing salons disconnected and stylists unmapped by default', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    expect($salon->ghlConnection()->first())->toBeNull();
    expect($salon->ghlConnected())->toBeFalse();
    expect(StylistProfile::forSalon($salon)->where('user_id', $stylist->id)->value('ghl_user_id'))->toBeNull();
});
