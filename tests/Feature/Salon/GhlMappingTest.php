<?php

use App\Actions\Salons\TestGhlConnection;
use App\Actions\Salons\UpdateGhlStaffMapping;
use App\Enums\AgencyRole;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\StylistProfile;
use App\Models\User;
use App\Services\Calendar\CalendarData;
use Carbon\CarbonImmutable;
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
// ---------------------------------------------------------------------------
// Two-tier mapping: stylists → calendar providers, other staff → location users
// ---------------------------------------------------------------------------

/**
 * Fake both GHL endpoints: one master calendar whose team members are
 * ghl_u1/ghl_u2, and three location users (u1 Ana, u2 Ben, u3 Cara — Cara is
 * NOT on the calendar).
 */
function fakeGhlDirectory(): void
{
    Http::fake([
        'services.leadconnectorhq.com/calendars/*' => Http::response(ghlLocationCalendars()),
        'services.leadconnectorhq.com/users/*' => Http::response(['users' => [
            ['id' => 'ghl_u1', 'name' => 'Ana', 'email' => 'ana@example.com'],
            ['id' => 'ghl_u2', 'name' => 'Ben', 'email' => 'ben@example.com'],
            ['id' => 'ghl_u3', 'name' => 'Cara', 'email' => 'cara@example.com'],
        ]]),
    ]);
}

it('offers only the master calendar\'s team members as stylist providers', function () {
    fakeGhlDirectory();
    $salon = connectedSalon();
    stylistOf($salon);

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('loadGhlDirectory')
        ->set('ghlCalendarId', 'cal_1');

    // Providers = calendar members only (Cara, a location user not on the
    // calendar, is excluded); the staff tier still offers everyone.
    expect(array_column($component->instance()->ghlProviderOptions, 'id'))->toBe(['ghl_u1', 'ghl_u2']);
    expect(array_column($component->instance()->ghlStaffOptions, 'id'))->toBe(['ghl_u1', 'ghl_u2', 'ghl_u3']);
});

it('shows a clear hint instead of falling back when the calendar has no team members', function () {
    Http::fake([
        'services.leadconnectorhq.com/calendars/*' => Http::response(['calendars' => [[
            'id' => 'cal_empty', 'name' => 'Empty calendar', 'locationId' => 'loc_1', 'teamMembers' => [],
        ]]]),
        'services.leadconnectorhq.com/users/*' => Http::response(['users' => [
            ['id' => 'ghl_u1', 'name' => 'Ana', 'email' => 'ana@example.com'],
        ]]),
    ]);
    $salon = connectedSalon();
    stylistOf($salon);

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('loadGhlDirectory')
        ->set('ghlCalendarId', 'cal_empty');

    // The provider tier is NOT quietly filled with location users.
    expect($component->instance()->ghlProviderOptions)->toBe([]);
    $component->assertSee('This calendar has no team members yet');
});

it('reports an empty GHL user list instead of silent empty dropdowns', function () {
    Http::fake([
        'services.leadconnectorhq.com/calendars/*' => Http::response(ghlLocationCalendars()),
        'services.leadconnectorhq.com/users/*' => Http::response(['users' => []]),
    ]);
    $salon = connectedSalon();
    frontDeskOf($salon);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('loadGhlDirectory')
        ->assertSee('No users found in GoHighLevel');
});

it('auto-matches both tiers by email, case-insensitively, and leaves non-matches unmapped', function () {
    fakeGhlDirectory();
    $salon = connectedSalon();

    $stylist = stylistOf($salon, User::factory()->create(['email' => 'ANA@Example.com ']));
    $frontDesk = frontDeskOf($salon);
    $frontDesk->update(['email' => ' cara@EXAMPLE.com']);
    $unmatched = stylistOf($salon); // random factory email, no GHL counterpart

    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('ghlCalendarId', 'cal_1')
        ->call('loadGhlDirectory');

    $component
        ->assertSet('ghlStylistMap.'.$stylist->id, 'ghl_u1')      // provider tier match
        ->assertSet('ghlStaffMap.'.$frontDesk->id, 'ghl_u3')      // location-user tier match
        ->assertSet('ghlStylistMap.'.$unmatched->id, '')          // no email match → unmapped
        ->assertSee('Matched by email');
});

it('persists both tiers to their distinct fields, including overrides of auto-matches', function () {
    fakeGhlDirectory();
    $salon = connectedSalon();
    $stylist = stylistOf($salon, User::factory()->create(['email' => 'ana@example.com']));
    $frontDesk = frontDeskOf($salon);

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('ghlCalendarId', 'cal_1')
        ->call('loadGhlDirectory')                     // auto-matches stylist → ghl_u1
        ->set('ghlStylistMap.'.$stylist->id, 'ghl_u2') // …but the owner overrides it
        ->set('ghlStaffMap.'.$frontDesk->id, 'ghl_u3')
        ->call('saveGhlMapping')
        ->assertHasNoErrors();

    // Stylist tier → stylist_profiles (feeds 6b booking routing).
    expect(StylistProfile::forSalon($salon)->where('user_id', $stylist->id)->value('ghl_user_id'))->toBe('ghl_u2');
    // Staff tier → salon_memberships (identity only).
    expect($salon->memberships()->where('user_id', $frontDesk->id)->value('ghl_location_user_id'))->toBe('ghl_u3');
    expect($salon->ghlConnection()->first()->calendar_id)->toBe('cal_1');
});

it('clears a mapping when set back to not mapped', function () {
    $salon = connectedSalon();
    $stylist = stylistOf($salon);

    app(UpdateGhlStaffMapping::class)->handle($salon, 'cal_1', [$stylist->id => 'ghl_u1']);
    expect(StylistProfile::forSalon($salon)->where('user_id', $stylist->id)->value('ghl_user_id'))->toBe('ghl_u1');

    app(UpdateGhlStaffMapping::class)->handle($salon, null, [$stylist->id => '']);
    expect(StylistProfile::forSalon($salon)->where('user_id', $stylist->id)->value('ghl_user_id'))->toBeNull();
});

it('rejects a non-stylist in the provider tier and a stylist in the identity tier', function () {
    $salon = connectedSalon();
    $stylist = stylistOf($salon);
    $frontDesk = frontDeskOf($salon);

    expect(fn () => app(UpdateGhlStaffMapping::class)->handle($salon, null, [$frontDesk->id => 'ghl_u1']))
        ->toThrow(ValidationException::class);

    expect(fn () => app(UpdateGhlStaffMapping::class)->handle($salon, null, [], [$stylist->id => 'ghl_u1']))
        ->toThrow(ValidationException::class);
});

it('rejects mapping before a connection exists', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    expect(fn () => app(UpdateGhlStaffMapping::class)->handle($salon, null, [$stylist->id => 'ghl_u1']))
        ->toThrow(ValidationException::class);
});

it('rejects forged ids from another salon in either tier (tenant isolation)', function () {
    $salonA = connectedSalon();
    $salonB = Salon::factory()->create();
    $stylistB = stylistOf($salonB);
    $frontDeskB = frontDeskOf($salonB);

    expect(fn () => app(UpdateGhlStaffMapping::class)->handle($salonA, null, [$stylistB->id => 'ghl_u1']))
        ->toThrow(ValidationException::class);
    expect(fn () => app(UpdateGhlStaffMapping::class)->handle($salonA, null, [], [$frontDeskB->id => 'ghl_u1']))
        ->toThrow(ValidationException::class);

    expect(StylistProfile::forSalon($salonB)->where('user_id', $stylistB->id)->value('ghl_user_id'))->toBeNull();
    expect($salonB->memberships()->where('user_id', $frontDeskB->id)->value('ghl_location_user_id'))->toBeNull();
});

it('never turns a mapped non-stylist into a booking target', function () {
    $salon = connectedSalon();
    $stylist = stylistOf($salon);
    $frontDesk = frontDeskOf($salon);

    app(UpdateGhlStaffMapping::class)->handle($salon, null, [], [$frontDesk->id => 'ghl_u3']);

    // Booking surfaces still see only stylists: the roster and the calendar
    // columns are unchanged by an identity mapping.
    expect($salon->stylistUsers()->pluck('users.id')->all())->toBe([$stylist->id]);

    $grid = app(CalendarData::class)
        ->day($salon, CarbonImmutable::now($salon->timezone), null);
    expect(array_column($grid['columns'], 'stylistId'))->not->toContain($frontDesk->id);
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

it('auto-matches stylists when the master calendar is picked after loading', function () {
    fakeGhlDirectory();
    $salon = connectedSalon();
    $stylist = stylistOf($salon, User::factory()->create(['email' => 'ben@example.com']));

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('loadGhlDirectory')                    // no calendar chosen yet
        ->assertSet('ghlStylistMap.'.$stylist->id, '')
        ->set('ghlCalendarId', 'cal_1')               // picking it re-runs the match
        ->assertSet('ghlStylistMap.'.$stylist->id, 'ghl_u2');
});
