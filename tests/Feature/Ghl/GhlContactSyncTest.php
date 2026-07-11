<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Actions\Clients\UpdateClient;
use App\Enums\BookingStatus;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\WebhookEvent;
use App\Services\Ghl\GhlContactSync;
use App\Support\BookingApiToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Bidirectional client↔GHL-contact sync (basic fields only: name, phone,
| email). Outbound on client edit; inbound via the same /webhooks/ghl
| endpoint; echo-gated, last-edit-wins, tag-gated creation. Frozen clock:
| Mon 2026-06-22 12:00 UTC.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
});
afterEach(fn () => Carbon::setTestNow());

function csSalon(string $locationId = 'loc_cs', string $secret = 'cs-secret'): Salon
{
    $salon = bookingSalon();
    $connection = SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => $locationId,
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);
    $connection->webhook_secret = $secret;
    $connection->save();

    return $salon;
}

function fakeContactApi(): void
{
    Http::fake([
        '*/contacts/upsert' => Http::response(['contact' => ['id' => 'c_new']]),
        '*/contacts/*' => Http::response(['contact' => ['id' => 'c_1']]),
    ]);
}

/** @param array<string, mixed> $overrides */
function postContactWebhook(array $overrides = [], string $secret = 'cs-secret'): TestResponse
{
    return test()->postJson(route('webhooks.ghl'), array_merge([
        'locationId' => 'loc_cs',
        'contact_id' => 'c_1',
        'first_name' => 'Paula',
        'last_name' => 'Phone',
        'phone' => '+15550999',
        'email' => 'paula@example.com',
        'tags' => [],
    ], $overrides), ['X-Webhook-Secret' => $secret]);
}

// ---------------------------------------------------------------------------
// App → GHL
// ---------------------------------------------------------------------------

it('pushes a client edit to the linked GHL contact with the basic fields only', function () {
    fakeContactApi();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create([
        'name' => 'Paula Phone', 'phone' => '+15550111', 'email' => 'paula@example.com',
        'ghl_contact_id' => 'c_1', 'allergies' => 'PPD',
    ]);

    app(UpdateClient::class)->handle($salon, $client, [
        'name' => 'Paula Phone', 'phone' => '+15550999', 'email' => 'paula@example.com',
    ]);

    Http::assertSent(function ($request): bool {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/contacts/c_1')
            && $request['phone'] === '+15550999'
            && $request['firstName'] === 'Paula'
            && $request['lastName'] === 'Phone'
            && ! array_key_exists('allergies', $request->data()); // app-only never leaves
    });

    $client->refresh();
    expect($client->ghl_sync_status)->toBe(GhlContactSync::STATUS_SYNCED);
    expect($client->ghl_pushed_hash)->toBe(GhlContactSync::basicHash('Paula Phone', '+15550999', 'paula@example.com'));
});

it('creates and links a GHL contact for an unlinked client on edit', function () {
    fakeContactApi();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create([
        'name' => 'Nolan New', 'phone' => '+15550222', 'ghl_contact_id' => null,
    ]);

    app(UpdateClient::class)->handle($salon, $client, [
        'name' => 'Nolan Newname', 'phone' => '+15550222', 'email' => null,
    ]);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/contacts/upsert')
        && $request['name'] === 'Nolan Newname');

    expect($client->refresh()->ghl_contact_id)->toBe('c_new');
});

it('skips the push when nothing changed or the salon is unconnected', function () {
    Http::fake();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create(['name' => 'Same Sam', 'phone' => '+15550333', 'email' => null, 'ghl_contact_id' => 'c_1']);

    // Identical values: no change, no push.
    app(UpdateClient::class)->handle($salon, $client, ['name' => 'Same Sam', 'phone' => '+15550333', 'email' => null]);
    Http::assertNothingSent();

    // Unconnected salon: edit applies, nothing is queued.
    $bare = bookingSalon();
    $bareClient = Client::factory()->for($bare)->create(['name' => 'Bare Bea']);
    app(UpdateClient::class)->handle($bare, $bareClient, ['name' => 'Bea Renamed', 'phone' => null, 'email' => null]);
    Http::assertNothingSent();
    expect($bareClient->refresh()->name)->toBe('Bea Renamed');
});

// ---------------------------------------------------------------------------
// GHL → App
// ---------------------------------------------------------------------------

it('applies a newer inbound contact update and never touches app-only fields', function () {
    Http::fake();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create([
        'name' => 'Paula Phone', 'phone' => '+15550111', 'email' => 'paula@example.com',
        'ghl_contact_id' => 'c_1', 'allergies' => 'PPD', 'formula_notes' => '7N 20vol',
    ]);

    postContactWebhook(['date_updated' => '2026-06-22T13:00:00Z'])->assertStatus(202);

    $client->refresh();
    expect($client->phone)->toBe('+15550999');
    expect($client->allergies)->toBe('PPD');              // app-only untouched
    expect($client->formula_notes)->toBe('7N 20vol');
    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::STATUS_APPLIED);

    // Applying an inbound change queues NO outbound push — no loop.
    Http::assertNothingSent();
});

it('adopts the contact id when matching an existing client by phone', function () {
    Http::fake();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create([
        'name' => 'Paula Phone', 'phone' => '+15550999', 'email' => null, 'ghl_contact_id' => null,
    ]);

    postContactWebhook(['email' => 'paula@new.example.com', 'date_updated' => '2026-06-22T13:00:00Z'])->assertStatus(202);

    $client->refresh();
    expect($client->ghl_contact_id)->toBe('c_1');
    expect($client->email)->toBe('paula@new.example.com');
});

// ---------------------------------------------------------------------------
// Tag gating
// ---------------------------------------------------------------------------

it('creates a client only from a TAGGED unknown contact', function () {
    Http::fake();
    $salon = csSalon();

    // Untagged unknown contact: ignored, no client created.
    postContactWebhook(['contact_id' => 'c_lead', 'phone' => '+15550444'])->assertStatus(202);
    expect($salon->clients()->count())->toBe(0);
    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::STATUS_IGNORED_UNTAGGED);

    // Tagged (case-insensitive): created with the basic fields + link.
    postContactWebhook(['contact_id' => 'c_real', 'phone' => '+15550555', 'tags' => ['VIP', 'Client']])->assertStatus(202);
    $created = $salon->clients()->firstOrFail();
    expect($created->name)->toBe('Paula Phone');
    expect($created->ghl_contact_id)->toBe('c_real');
    expect($created->phone)->toBe('+15550555');
    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::STATUS_CREATED_CLIENT);
});

it('applies updates to an already-linked client regardless of tags', function () {
    Http::fake();
    $salon = csSalon();
    Client::factory()->for($salon)->create([
        'name' => 'Paula Phone', 'phone' => '+15550111', 'email' => null, 'ghl_contact_id' => 'c_1',
    ]);

    // No tag at all — still an update to a known client.
    postContactWebhook(['tags' => [], 'email' => null, 'date_updated' => '2026-06-22T13:00:00Z'])->assertStatus(202);

    expect($salon->clients()->firstOrFail()->phone)->toBe('+15550999');
    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::STATUS_APPLIED);
});

// ---------------------------------------------------------------------------
// Echo + last-edit-wins
// ---------------------------------------------------------------------------

it('ignores the webhook that merely echoes what the app just pushed', function () {
    fakeContactApi();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create([
        'name' => 'Paula Phone', 'phone' => '+15550111', 'email' => 'paula@example.com', 'ghl_contact_id' => 'c_1',
    ]);

    // App edit → outbound push (one PUT).
    app(UpdateClient::class)->handle($salon, $client, [
        'name' => 'Paula Phone', 'phone' => '+15550999', 'email' => 'paula@example.com',
    ]);
    Http::assertSentCount(2); // the PUT + the one-time tag add

    // GHL echoes the same state back — ignored, and no second push.
    postContactWebhook(['date_updated' => '2026-06-22T12:30:00Z'])->assertStatus(202);

    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::STATUS_IGNORED_ECHO);
    expect($client->refresh()->phone)->toBe('+15550999');
    Http::assertSentCount(2); // still just the original push + tag
});

it('keeps the newer app state when the inbound change is older (last-edit-wins)', function () {
    Http::fake();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create([
        'name' => 'Paula Phone', 'phone' => '+15550111', 'email' => 'paula@example.com', 'ghl_contact_id' => 'c_1',
    ]); // updated_at = frozen now (12:00Z)

    postContactWebhook(['date_updated' => '2026-06-22T11:00:00Z'])->assertStatus(202); // older than the app edit

    expect($client->refresh()->phone)->toBe('+15550111'); // app wins
    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::STATUS_IGNORED_STALE);
});

it('treats a timestamp-less payload equal to the last push as an echo', function () {
    Http::fake();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create([
        'name' => 'Paula Renamed', 'phone' => '+15550999', 'email' => 'paula@example.com', 'ghl_contact_id' => 'c_1',
    ]);
    // The app previously pushed the ORIGINAL state, then renamed locally.
    $client->forceFill([
        'ghl_pushed_hash' => GhlContactSync::basicHash('Paula Phone', '+15550999', 'paula@example.com'),
    ])->save();

    // The late echo of that old push arrives with no timestamp.
    postContactWebhook()->assertStatus(202); // payload name: "Paula Phone", no date_updated

    expect($client->refresh()->name)->toBe('Paula Renamed'); // kept
    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::STATUS_IGNORED_ECHO);
});

// ---------------------------------------------------------------------------
// Tenant isolation + idempotency
// ---------------------------------------------------------------------------

it('never lets one location\'s contact touch another salon\'s client', function () {
    Http::fake();
    $salonA = csSalon('loc_a', 'secret-a');
    $salonB = csSalon('loc_b', 'secret-b');
    $clientA = Client::factory()->for($salonA)->create([
        'name' => 'Paula Phone', 'phone' => '+15550111', 'email' => null, 'ghl_contact_id' => 'c_1',
    ]);

    // Same contact id arriving for salon B's location: B has no such client,
    // and it is untagged — nothing is created and A stays untouched.
    postContactWebhook(['locationId' => 'loc_b', 'date_updated' => '2026-06-22T13:00:00Z'], 'secret-b')->assertStatus(202);

    expect($clientA->refresh()->phone)->toBe('+15550111');
    expect($salonB->clients()->count())->toBe(0);
});

it('dedupes an identical replayed webhook', function () {
    Http::fake();
    $salon = csSalon();
    Client::factory()->for($salon)->create([
        'name' => 'Paula Phone', 'phone' => '+15550111', 'email' => null, 'ghl_contact_id' => 'c_1',
    ]);

    $payload = ['email' => null, 'date_updated' => '2026-06-22T13:00:00Z'];
    postContactWebhook($payload)->assertStatus(202);
    postContactWebhook($payload)->assertStatus(202); // byte-identical replay

    expect(WebhookEvent::query()->count())->toBe(2);
    expect(WebhookEvent::query()->latest('id')->first()->status)->toBe(WebhookEvent::STATUS_IGNORED_REPLAY);
    expect($salon->clients()->firstOrFail()->phone)->toBe('+15550999'); // applied exactly once
});

// ---------------------------------------------------------------------------
// Auto-tagging — only REAL clients (bookings + app-side pushes)
// ---------------------------------------------------------------------------

it('tags the contact once when a booking is pushed — merge endpoint, never overwrite', function () {
    Http::fake([
        '*/contacts/upsert' => Http::response(['contact' => ['id' => 'c_book']]),
        '*/contacts/*/tags' => Http::response([]),
        '*/calendars/events/appointments*' => Http::response(['id' => 'a_tag1']),
    ]);
    $salon = csSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_tag']);
    $service = serviceFor($salon, $stylist, 60);

    $booking = makeBooking($salon, salonOwnerOf($salon), $stylist, $service, '2026-06-22 10:00', 'Tag Tia');

    // The tag goes through the ADD-tags endpoint (merges server-side) —
    // never a tags write on the contact body (which would overwrite).
    Http::assertSent(fn ($r): bool => $r->method() === 'POST'
        && str_ends_with($r->url(), '/contacts/c_book/tags')
        && $r['tags'] === ['client']);
    Http::assertNotSent(fn ($r): bool => $r->method() === 'PUT' && str_contains($r->url(), '/contacts/'));
    expect($booking->client->refresh()->ghl_client_tagged_at)->not->toBeNull();

    // A second push for the same client (check-in status change) re-pushes
    // the appointment but never re-tags.
    app(TransitionBookingStatus::class)
        ->handle(salonOwnerOf($salon), $salon, $booking, BookingStatus::Arrived);
    expect(Http::recorded(fn ($r): bool => str_ends_with($r->url(), '/tags'))->count())->toBe(1);
});

it('tags via a voice-API booking too (same push path)', function () {
    Http::fake([
        '*/contacts/upsert' => Http::response(['contact' => ['id' => 'c_voice']]),
        '*/contacts/*/tags' => Http::response([]),
        '*/calendars/events/appointments*' => Http::response(['id' => 'a_tag2']),
    ]);
    $salon = csSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_v']);
    $service = serviceFor($salon, $stylist, 60);
    $service->update(['name' => 'Haircut']);
    $token = BookingApiToken::generate($salon);

    test()->postJson(route('api.booking.create'), [
        'service' => 'Haircut',
        'datetime' => '2026-06-22T14:00:00-04:00',
        'client' => ['name' => 'Voice Val', 'phone' => '+15550777'],
    ], ['Authorization' => "Bearer {$token}"])->assertCreated();

    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/contacts/c_voice/tags') && $r['tags'] === ['client']);
});

it('tags the contact of a GHL-originated booking, skipping the API call when the payload already carries it', function () {
    Http::fake(['*/contacts/*/tags' => Http::response([])]);
    $salon = csSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    StylistProfile::updateOrCreate(['salon_id' => $salon->id, 'user_id' => $stylist->id], ['ghl_user_id' => 'prov_in']);
    Service::factory()->for($salon)->create(['name' => 'Cut & Style', 'duration_min' => 45]);

    $appointment = fn (string $id) => [
        'id' => $id, 'calendarId' => 'cal_master', 'assignedUserId' => 'prov_in',
        'appointmentStatus' => 'confirmed', 'title' => 'Cut & Style',
        'startTime' => '2026-06-23T15:00:00-04:00', 'endTime' => '2026-06-23T15:45:00-04:00',
    ];

    // Untagged contact books through GHL (voice AI): the app adds the tag.
    test()->postJson(route('webhooks.ghl'), [
        'locationId' => 'loc_cs', 'appointment' => $appointment('ghl_in1'),
        'contact' => ['id' => 'c_in1', 'name' => 'Inbound Ida', 'email' => 'ida@example.com'],
    ], ['X-Webhook-Secret' => 'cs-secret'])->assertStatus(202);

    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/contacts/c_in1/tags') && $r['tags'] === ['client']);

    // Payload already tagged: recorded without any API call.
    test()->postJson(route('webhooks.ghl'), [
        'locationId' => 'loc_cs', 'appointment' => $appointment('ghl_in2'),
        'contact' => ['id' => 'c_in2', 'name' => 'Tagged Tom', 'email' => 'tom@example.com', 'tags' => ['client']],
    ], ['X-Webhook-Secret' => 'cs-secret'])->assertStatus(202);

    expect(Http::recorded(fn ($r): bool => str_contains($r->url(), 'c_in2'))->count())->toBe(0);
    expect($salon->clients()->where('ghl_contact_id', 'c_in2')->firstOrFail()->ghl_client_tagged_at)->not->toBeNull();
});

it('never tags for a voice availability check or an untagged inbound contact', function () {
    Http::fake();
    $salon = csSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $service->update(['name' => 'Haircut']);
    $token = BookingApiToken::generate($salon);

    // Someone just checking times — no booking, no contact, no tag.
    test()->postJson(route('api.booking.availability'), ['service' => 'Haircut'],
        ['Authorization' => "Bearer {$token}"])->assertOk();
    Http::assertNothingSent();

    // A random untagged GHL contact webhook: no client created, no tag call.
    postContactWebhook(['contact_id' => 'c_random', 'phone' => '+15550888'])->assertStatus(202);
    Http::assertNothingSent();
});

it('tags on an app client push, once', function () {
    fakeContactApi();
    $salon = csSalon();
    $client = Client::factory()->for($salon)->create([
        'name' => 'Edith Edit', 'phone' => '+15550666', 'email' => null, 'ghl_contact_id' => 'c_1',
    ]);

    app(UpdateClient::class)->handle($salon, $client, ['name' => 'Edith Edited', 'phone' => '+15550666', 'email' => null]);
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/contacts/c_1/tags') && $r['tags'] === ['client']);

    // Another edit: pushed again, but the tag call never repeats.
    app(UpdateClient::class)->handle($salon, $client->refresh(), ['name' => 'Edith Final', 'phone' => '+15550666', 'email' => null]);
    expect(Http::recorded(fn ($r): bool => str_ends_with($r->url(), '/tags'))->count())->toBe(1);
});
