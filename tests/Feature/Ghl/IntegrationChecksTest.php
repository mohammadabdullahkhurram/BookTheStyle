<?php

use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\StylistProfile;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Ghl\IntegrationCheckResult;
use App\Services\Ghl\IntegrationChecks;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Integration test/verify actions (Settings → Integrations + setup wizard):
| every GHL-facing integration gets a real, safe, rate-limited check with a
| specific pass/fail message. All GHL/self HTTP is faked; the booking round
| trip must clean up after itself; URL-dependent checks show an honest
| "needs live URL" state on a local APP_URL; no tokens are ever persisted.
*/

const CHECKS_PUBLIC_URL = 'https://app.bookthestyle.com';

beforeEach(fn () => Http::preventStrayRequests());

function checksSalon(array $connection = []): Salon
{
    $salon = Salon::factory()->create();
    SalonGhlConnection::factory()->for($salon)->create(array_merge([
        'location_id' => 'loc_1',
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_1',
        'webhook_secret' => 'whsec-1',
    ], $connection));

    return $salon;
}

function checksStylist(Salon $salon, string $name, ?string $ghlUserId = null, ?string $scheduleId = null): User
{
    $user = User::factory()->create(['name' => $name]);
    stylistOf($salon, $user);

    if ($ghlUserId !== null || $scheduleId !== null) {
        StylistProfile::factory()->create([
            'salon_id' => $salon->id,
            'user_id' => $user->id,
            'ghl_user_id' => $ghlUserId,
            'ghl_schedule_id' => $scheduleId,
        ]);
    }

    return $user;
}

/** @return array<string, mixed> */
function checksCalendars(array $teamMemberIds = ['ghl_u1', 'ghl_u2'], string $id = 'cal_1', bool $active = true): array
{
    return ['calendars' => [[
        'id' => $id, 'name' => 'Master calendar', 'locationId' => 'loc_1', 'isActive' => $active,
        'teamMembers' => array_map(fn (string $userId): array => ['userId' => $userId], $teamMemberIds),
    ]]];
}

function runCheck(Salon $salon, string $key, ?string $voiceToken = null): IntegrationCheckResult
{
    return app(IntegrationChecks::class)->run($salon, $key, $voiceToken);
}

// ---------------------------------------------------------------------------
// Master calendar + stylist mapping
// ---------------------------------------------------------------------------

it('passes the mapping check when the calendar exists and every stylist is a team member', function () {
    Http::fake(['services.leadconnectorhq.com/calendars/*' => Http::response(checksCalendars())]);
    $salon = checksSalon();
    checksStylist($salon, 'Abdullah Stylist One', 'ghl_u1');
    checksStylist($salon, 'Bea Stylist Two', 'ghl_u2');

    $result = runCheck($salon, 'mapping');

    expect($result->ok())->toBeTrue();
    expect($result->message)->toContain('all 2 stylists');
    expect(collect($result->details)->pluck('text')->all())
        ->toContain('Abdullah Stylist One → linked OK')
        ->toContain('Bea Stylist Two → linked OK');

    // Persisted for "Last verified X ago" on both surfaces.
    expect($salon->fresh()->integration_checks['mapping']['state'])->toBe('passed');
});

it('flags unmapped stylists and mappings that are not team members, by name', function () {
    Http::fake(['services.leadconnectorhq.com/calendars/*' => Http::response(checksCalendars(['ghl_u1']))]);
    $salon = checksSalon();
    checksStylist($salon, 'Mapped Fine', 'ghl_u1');
    checksStylist($salon, 'Wrong Member', 'ghl_gone');
    checksStylist($salon, 'Never Mapped');

    $result = runCheck($salon, 'mapping');
    $texts = collect($result->details)->pluck('text')->implode(' | ');

    expect($result->ok())->toBeFalse();
    expect($result->message)->toContain('1 of 3 stylists');
    expect($texts)->toContain('Mapped Fine → linked OK');
    expect($texts)->toContain('Wrong Member → the mapped user is not a team member on this calendar');
    expect($texts)->toContain('Never Mapped is not mapped to a calendar provider yet');
    expect($result->hint)->toContain('team members');
});

it('fails the mapping check when the configured master calendar no longer exists', function () {
    Http::fake(['services.leadconnectorhq.com/calendars/*' => Http::response(checksCalendars(id: 'cal_other'))]);
    $salon = checksSalon();
    checksStylist($salon, 'Any Stylist', 'ghl_u1');

    $result = runCheck($salon, 'mapping');

    expect($result->ok())->toBeFalse();
    expect($result->message)->toContain('not found in GoHighLevel');
});

it('never mixes another salon’s stylists into the mapping check', function () {
    Http::fake(['services.leadconnectorhq.com/calendars/*' => Http::response(checksCalendars())]);
    $salon = checksSalon();
    checksStylist($salon, 'Mine Only', 'ghl_u1');
    $other = checksSalon(['location_id' => 'loc_2']);
    checksStylist($other, 'Foreign Stylist', 'ghl_u2');

    $result = runCheck($salon, 'mapping');

    expect(collect($result->details)->pluck('text')->implode(' '))
        ->toContain('Mine Only')
        ->not->toContain('Foreign Stylist');
});

// ---------------------------------------------------------------------------
// Availability read-back
// ---------------------------------------------------------------------------

it('verifies each mapped stylist’s schedule exists in GHL by reading it back', function () {
    Http::fake(['services.leadconnectorhq.com/calendars/schedules/search*' => Http::response(['schedules' => [['id' => 'sched_1']]])]);
    $salon = checksSalon();
    checksStylist($salon, 'Synced Stylist', 'ghl_u1', 'sched_1');

    $result = runCheck($salon, 'availability');

    expect($result->ok())->toBeTrue();
    expect(collect($result->details)->pluck('text')->first())->toBe('Synced Stylist → schedule present in GoHighLevel');
});

it('flags never-synced stylists and schedules that vanished from GHL', function () {
    Http::fake(['services.leadconnectorhq.com/calendars/schedules/search*' => Http::response(['schedules' => []])]);
    $salon = checksSalon();
    checksStylist($salon, 'Gone Schedule', 'ghl_u1', 'sched_gone');
    checksStylist($salon, 'Never Synced', 'ghl_u2');

    $result = runCheck($salon, 'availability');
    $texts = collect($result->details)->pluck('text')->implode(' | ');

    expect($result->ok())->toBeFalse();
    expect($texts)->toContain('Gone Schedule → the synced schedule no longer exists in GoHighLevel — re-run the sync');
    expect($texts)->toContain('Never Synced → never synced');
    expect($result->hint)->toContain('Sync availability');
});

// ---------------------------------------------------------------------------
// Outbound booking round trip (create → read back → DELETE)
// ---------------------------------------------------------------------------

it('round-trips a test appointment and cleans it up', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'c_test']]),
        'services.leadconnectorhq.com/calendars/events/appointments/evt_1' => Http::response(['appointment' => ['id' => 'evt_1']]),
        'services.leadconnectorhq.com/calendars/events/appointments' => Http::response(['id' => 'evt_1']),
        'services.leadconnectorhq.com/calendars/events/evt_1' => Http::response(['succeeded' => true]),
    ]);
    $salon = checksSalon();
    checksStylist($salon, 'Provider One', 'ghl_u1');

    $result = runCheck($salon, 'booking');

    expect($result->ok())->toBeTrue();
    expect($result->message)->toContain('created')->toContain('read back')->toContain('deleted');

    // The appointment is unmistakably a test and far in the future…
    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/calendars/events/appointments')) {
            return false;
        }

        return $request['title'] === IntegrationChecks::TEST_APPOINTMENT_TITLE
            && str_contains((string) $request['startTime'], 'T03:00:00')
            && $request['contactId'] === 'c_test';
    });

    // …only the dedicated test contact is written (no real client data)…
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/contacts/upsert')
        && $request['email'] === 'integration-check+salon'.$salon->id.'@bookthestyle.app');

    // …and the DELETE cleanup actually ran.
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/calendars/events/evt_1'));
});

it('reports loudly when the test appointment could not be deleted', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'c_test']]),
        'services.leadconnectorhq.com/calendars/events/appointments/evt_1' => Http::response(['appointment' => ['id' => 'evt_1']]),
        'services.leadconnectorhq.com/calendars/events/appointments' => Http::response(['id' => 'evt_1']),
        'services.leadconnectorhq.com/calendars/events/evt_1' => Http::response(['message' => 'forbidden'], 403),
    ]);
    $salon = checksSalon();
    checksStylist($salon, 'Provider One', 'ghl_u1');

    $result = runCheck($salon, 'booking');

    expect($result->ok())->toBeFalse();
    expect($result->message)->toContain('could not delete');
    expect($result->hint)->toContain(IntegrationChecks::TEST_APPOINTMENT_TITLE);
});

it('refuses the round trip without a mapped stylist', function () {
    $salon = checksSalon();

    $result = runCheck($salon, 'booking');

    expect($result->ok())->toBeFalse();
    expect($result->message)->toContain('No stylist is mapped');
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Inbound webhook (self-ping over the public URL)
// ---------------------------------------------------------------------------

it('shows the needs-live-URL state for the webhook check on a local app URL', function () {
    config(['app.url' => 'http://app.lvh.me:8000']);
    $salon = checksSalon();

    $result = runCheck($salon, 'webhook');

    expect($result->state)->toBe(IntegrationCheckResult::BLOCKED);
    expect($result->message)->toContain('public URL');
    // A blocked state is informational — it never overwrites a stored result.
    expect($salon->fresh()->integration_checks)->toBeNull();
    Http::assertNothingSent();
});

it('passes the webhook check when the public endpoint verifies the secret', function () {
    // Production-faithful: APP_URL is the APEX; the checks must still hit app.
    config(['app.url' => 'https://bookthestyle.com', 'app.domain' => 'bookthestyle.com']);
    Http::fake([CHECKS_PUBLIC_URL.'/webhooks/ghl' => Http::response(['received' => true, 'test' => true])]);
    $salon = checksSalon();

    $result = runCheck($salon, 'webhook');

    expect($result->ok())->toBeTrue();
    expect($result->message)->toContain('secret verified');
    Http::assertSent(fn ($request): bool => $request->url() === CHECKS_PUBLIC_URL.'/webhooks/ghl'
        && $request->hasHeader('X-Webhook-Secret', 'whsec-1')
        && $request['type'] === 'bookthestyle.webhook.test');
});

it('explains a webhook secret mismatch with the likely fix', function () {
    // Production-faithful: APP_URL is the APEX; the checks must still hit app.
    config(['app.url' => 'https://bookthestyle.com', 'app.domain' => 'bookthestyle.com']);
    Http::fake([CHECKS_PUBLIC_URL.'/webhooks/ghl' => Http::response(['message' => 'Unauthorized.'], 401)]);
    $salon = checksSalon();

    $result = runCheck($salon, 'webhook');

    expect($result->ok())->toBeFalse();
    expect($result->message)->toContain('secret did not verify');
    expect($result->hint)->toContain('X-Webhook-Secret');
});

it('answers the self-test payload through the real webhook endpoint without recording an event', function () {
    $salon = checksSalon();

    $response = $this->postJson(route('webhooks.ghl'), [
        'type' => 'bookthestyle.webhook.test',
        'locationId' => 'loc_1',
    ], ['X-Webhook-Secret' => 'whsec-1']);

    $response->assertOk()->assertJson(['received' => true, 'test' => true]);
    expect(WebhookEvent::query()->count())->toBe(0);

    // A wrong secret still gets the uniform 401.
    $this->postJson(route('webhooks.ghl'), [
        'type' => 'bookthestyle.webhook.test',
        'locationId' => 'loc_1',
    ], ['X-Webhook-Secret' => 'nope'])->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Voice AI booking API (the salon's own endpoint over the public URL)
// ---------------------------------------------------------------------------

function checksApiToken(Salon $salon): void
{
    $salon->forceFill([
        'api_token_hash' => hash('sha256', 'btsk_secret'),
        'api_token_generated_at' => now(),
    ])->save();
}

it('shows the needs-live-URL state for the booking API check on a local app URL', function () {
    config(['app.url' => 'http://app.lvh.me:8000']);
    $salon = checksSalon();
    checksApiToken($salon);

    $result = runCheck($salon, 'voice');

    expect($result->state)->toBe(IntegrationCheckResult::BLOCKED);
    Http::assertNothingSent();
});

it('proves the full 200-with-slots path when the fresh token is still on screen', function () {
    // Production-faithful: APP_URL is the APEX; the checks must still hit app.
    config(['app.url' => 'https://bookthestyle.com', 'app.domain' => 'bookthestyle.com']);
    Http::fake([CHECKS_PUBLIC_URL.'/api/v1/booking/availability' => Http::response([
        'success' => true,
        'slots' => [['spoken' => 'Tomorrow 10:00'], ['spoken' => 'Tomorrow 11:00']],
    ])]);
    $salon = checksSalon();
    $stylist = checksStylist($salon, 'Bookable', 'ghl_u1');
    serviceFor($salon, $stylist);
    checksApiToken($salon);

    $result = runCheck($salon, 'voice', 'btsk_secret');

    expect($result->ok())->toBeTrue();
    expect($result->message)->toContain('200 OK')->toContain('2 open slots');
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer btsk_secret'));

    // The token itself is never persisted with the result.
    expect(json_encode($salon->fresh()->integration_checks))->not->toContain('btsk_secret');
});

it('verifies endpoint + auth stack without the plaintext token (hashed at rest)', function () {
    // Production-faithful: APP_URL is the APEX; the checks must still hit app.
    config(['app.url' => 'https://bookthestyle.com', 'app.domain' => 'bookthestyle.com']);
    Http::fake([CHECKS_PUBLIC_URL.'/api/v1/booking/availability' => Http::response([
        'success' => false, 'error' => 'unauthenticated', 'message' => 'Invalid or missing API token.',
    ], 401)]);
    $salon = checksSalon();
    checksApiToken($salon);

    $result = runCheck($salon, 'voice');

    expect($result->ok())->toBeTrue();
    expect($result->message)->toContain('reachable')->toContain('rejects bad tokens');
});

it('gives a helpful 404 message when the booking API route is missing', function () {
    // Production-faithful: APP_URL is the APEX; the checks must still hit app.
    config(['app.url' => 'https://bookthestyle.com', 'app.domain' => 'bookthestyle.com']);
    Http::fake([CHECKS_PUBLIC_URL.'/api/v1/booking/availability' => Http::response('not found', 404)]);
    $salon = checksSalon();
    checksApiToken($salon);

    $result = runCheck($salon, 'voice');

    expect($result->ok())->toBeFalse();
    expect($result->message)->toContain('404');
});

it('requires a token before the booking API can be tested', function () {
    // Production-faithful: APP_URL is the APEX; the checks must still hit app.
    config(['app.url' => 'https://bookthestyle.com', 'app.domain' => 'bookthestyle.com']);
    $salon = checksSalon();

    $result = runCheck($salon, 'voice');

    expect($result->ok())->toBeFalse();
    expect($result->message)->toContain('Generate the booking API token first');
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Client ↔ contact sync (scope check)
// ---------------------------------------------------------------------------

it('passes the contact sync check when read and write both respond', function () {
    Http::fake([
        'services.leadconnectorhq.com/contacts/upsert' => Http::response(['contact' => ['id' => 'c_test']]),
        'services.leadconnectorhq.com/contacts/*' => Http::response(['contacts' => []]),
    ]);
    $salon = checksSalon();

    $result = runCheck($salon, 'contacts');

    expect($result->ok())->toBeTrue();
    expect(collect($result->details)->pluck('text')->implode(' '))
        ->toContain('contacts.readonly')
        ->toContain('contacts.write');
});

it('points at the missing contacts scope when reading fails', function () {
    Http::fake(['services.leadconnectorhq.com/contacts/*' => Http::response(['message' => 'forbidden'], 403)]);
    $salon = checksSalon();

    $result = runCheck($salon, 'contacts');

    expect($result->ok())->toBeFalse();
    expect($result->hint)->toContain('contacts.readonly');
});

// ---------------------------------------------------------------------------
// Guard rails: rate limit, unknown key, connection prerequisite
// ---------------------------------------------------------------------------

it('rate-limits repeated runs without clobbering the stored result', function () {
    Http::fake(['services.leadconnectorhq.com/*' => Http::response(checksCalendars())]);
    $salon = checksSalon();
    checksStylist($salon, 'Solo', 'ghl_u1');

    foreach (range(1, 6) as $i) {
        runCheck($salon, 'mapping');
    }
    $stored = $salon->fresh()->integration_checks['mapping'];

    $seventh = runCheck($salon, 'mapping');

    expect($seventh->ok())->toBeFalse();
    expect($seventh->message)->toContain('Too many runs');
    expect($salon->fresh()->integration_checks['mapping'])->toBe($stored);
});

it('asks for the connection before any GHL-reading check', function () {
    $salon = Salon::factory()->create();

    foreach (['mapping', 'availability', 'contacts'] as $key) {
        expect(runCheck($salon, $key)->ok())->toBeFalse();
    }
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Surfaces: Settings → Integrations and the setup wizard, owner/admin only
// ---------------------------------------------------------------------------

it('renders every check control on the settings integrations tab', function () {
    Http::fake();
    $salon = checksSalon();
    $owner = salonOwnerOf($salon);

    $page = Livewire::actingAs($owner)->test('pages::salon.settings', ['salon' => $salon]);

    foreach (['mapping', 'contacts', 'availability', 'booking', 'webhook', 'voice'] as $key) {
        $page->assertSeeHtml('data-integration-check="'.$key.'"');
    }
    // The connection card renders the same shared result panel.
    $page->assertSeeHtml('wire:click="testGhlConnection"');
});

it('renders the matching check on each setup wizard step', function () {
    Http::fake();
    $salon = checksSalon();
    $owner = salonOwnerOf($salon);

    $page = Livewire::actingAs($owner)->test('pages::salon.onboarding', ['salon' => $salon]);

    foreach ([
        'ghl_connect' => 'contacts',
        'ghl_mapping' => 'mapping',
        'webhook' => 'webhook',
        'api_token' => 'voice',
        'voice_actions' => 'voice',
        'availability_sync' => 'availability',
    ] as $step => $key) {
        $page->call('goTo', $step)->assertSeeHtml('data-integration-check="'.$key.'"');
    }
    $page->call('goTo', 'availability_sync')->assertSeeHtml('data-integration-check="booking"');
});

it('shows the needs-live-URL note instead of a false failure on local URLs', function () {
    config(['app.url' => 'http://app.lvh.me:8000']);
    Http::fake();
    $salon = checksSalon();
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->assertSee('live public URL')
        ->assertSee('works automatically once the app is deployed');
});

it('runs a check from the settings surface and shows the inline result', function () {
    Http::fake(['services.leadconnectorhq.com/calendars/*' => Http::response(checksCalendars())]);
    $salon = checksSalon();
    checksStylist($salon, 'Abdullah Stylist One', 'ghl_u1');
    $owner = salonOwnerOf($salon);

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('runIntegrationCheck', 'mapping')
        ->assertSee('Passed')
        ->assertSee('Abdullah Stylist One → linked OK')
        ->assertSee('Last verified');
});

it('keeps the check actions away from non-managers (tenant + role scoped)', function () {
    $salon = checksSalon();
    $stylist = checksStylist($salon, 'Just A Stylist');
    $outsider = salonOwnerOf(checksSalon(['location_id' => 'loc_2']));

    // Stylists cannot even open the settings page that hosts the actions…
    Livewire::actingAs($stylist)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->assertForbidden();

    // …and another salon's owner is rejected outright.
    Livewire::actingAs($outsider)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->assertForbidden();
});
