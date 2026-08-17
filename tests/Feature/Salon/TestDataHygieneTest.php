<?php

use App\Models\Booking;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingApi\VoiceBookingApi;
use App\Services\Diagnostics\ConnectionDiagnostics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
| Test-data hygiene: every record the test lanes create carries is_test,
| and cleanup removes ALL of it — both designated clients included. The
| historical bug: the designated clients are booked by NAME/PHONE through
| the shared engine, and a re-creation that raced a sweep minted an
| UNFLAGGED row — no TEST badge, invisible to the is_test-keyed teardown.
| Root fix: resolveClient flags designated identities at the source, the
| ensure* paths reclaim-and-reflag by name, the teardown also matches the
| reserved names, and a migration backfills production's stray rows.
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('flags every record the test lanes create: stylist, service, both clients, preview client', function () {
    $salon = bookingSalon();

    $records = app(ConnectionDiagnostics::class)->ensureTestRecords($salon);

    expect($records['stylist']->is_test)->toBeTrue();
    expect($records['service']->is_test)->toBeTrue();
    expect($records['client']->is_test)->toBeTrue();

    $voice = Client::withoutGlobalScopes()->where('salon_id', $salon->id)
        ->where('name', ConnectionDiagnostics::VOICE_CLIENT_NAME)->sole();
    expect($voice->is_test)->toBeTrue();

    // The widget preview's lane too.
    $previewClient = app(ConnectionDiagnostics::class)->ensureTestClient($salon);
    expect($previewClient->is_test)->toBeTrue();
    expect($previewClient->id)->toBe($records['client']->id); // one row, both lanes
});

it('flags a designated client minted through the ENGINE funnel — the unflagged-recreation root cause', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);

    // No designated client exists (a sweep just ran). A voice-lane booking
    // arrives by NAME/PHONE — the exact path that used to mint an
    // unflagged row.
    expect(Client::withoutGlobalScopes()->where('salon_id', $salon->id)->exists())->toBeFalse();

    $result = app(VoiceBookingApi::class)->createVisit($salon, [
        'services' => [$service->id],
        'stylist' => 'any',
        'date' => '2026-06-22',
        'time' => '2:00 PM',
        'client' => ['name' => ConnectionDiagnostics::VOICE_CLIENT_NAME, 'phone' => ConnectionDiagnostics::VOICE_CLIENT_PHONE],
    ]);
    expect($result['success'])->toBeTrue();

    $voice = Client::withoutGlobalScopes()->where('salon_id', $salon->id)
        ->where('name', ConnectionDiagnostics::VOICE_CLIENT_NAME)->sole();
    expect($voice->is_test)->toBeTrue(); // flagged at creation, not left bare
});

it('self-heals an EXISTING unflagged designated client on next contact, and the badge follows', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);

    // Production's broken state: the designated client exists WITHOUT the
    // flag (created by an older code path).
    $stray = Client::withoutGlobalScopes()->create([
        'salon_id' => $salon->id, 'name' => ConnectionDiagnostics::VOICE_CLIENT_NAME,
        'phone' => '+15550100001', 'is_test' => false,
    ]);

    app(VoiceBookingApi::class)->createVisit($salon, [
        'services' => [$service->id],
        'stylist' => 'any',
        'date' => '2026-06-22',
        'time' => '2:00 PM',
        'client' => ['name' => ConnectionDiagnostics::VOICE_CLIENT_NAME, 'phone' => ConnectionDiagnostics::VOICE_CLIENT_PHONE],
    ]);

    expect($stray->refresh()->is_test)->toBeTrue(); // reclaimed, not duplicated
    expect(Client::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(1);

    // Both designated clients show the TEST badge on the clients screen.
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon);
    $page = $this->actingAs(salonOwnerOf($salon))->get(route('salon.clients', $salon))->assertOk();
    $page->assertSee(ConnectionDiagnostics::CLIENT_NAME)
        ->assertSee(ConnectionDiagnostics::VOICE_CLIENT_NAME)
        ->assertSee(__('TEST'));
    expect(substr_count($page->getContent(), '>'.__('TEST').'<'))->toBeGreaterThanOrEqual(2);
});

it('backfills stray unflagged designated clients — and never touches a real client', function () {
    $salon = bookingSalon();
    $strayVoice = Client::withoutGlobalScopes()->create([
        'salon_id' => $salon->id, 'name' => ConnectionDiagnostics::VOICE_CLIENT_NAME,
        'phone' => '+15550100001', 'is_test' => false,
    ]);
    $strayMain = Client::withoutGlobalScopes()->create([
        'salon_id' => $salon->id, 'name' => ConnectionDiagnostics::CLIENT_NAME,
        'phone' => '+1 555 010 0000', 'is_test' => false,
    ]);
    $real = Client::withoutGlobalScopes()->create([
        'salon_id' => $salon->id, 'name' => 'Rhea Real', 'phone' => '+1 555 777 8888', 'is_test' => false,
    ]);

    (require database_path('migrations/2026_08_17_000001_backfill_is_test_on_designated_test_clients.php'))->up();

    expect($strayVoice->refresh()->is_test)->toBeTrue();
    expect($strayMain->refresh()->is_test)->toBeTrue();
    expect($real->refresh()->is_test)->toBeFalse();
});

it('teardown removes EVERY test record — stylist, service, BOTH clients (even a stray unflagged one), and their appointments — leaving real data and other salons alone', function () {
    $salon = bookingSalon();
    $other = bookingSalon();

    // Full test-lane setup + a test booking via the health-check identity…
    $records = app(ConnectionDiagnostics::class)->ensureTestRecords($salon);
    $booking = app(VoiceBookingApi::class)->createVisit($salon, [
        'services' => [$records['service']->id],
        'stylist' => 'any',
        'date' => ConnectionDiagnostics::TEST_BOOKING_DATE,
        'time' => ConnectionDiagnostics::TEST_BOOKING_TIME,
        'client' => ['name' => ConnectionDiagnostics::CLIENT_NAME, 'phone' => ConnectionDiagnostics::CLIENT_PHONE],
    ]);
    expect($booking['success'])->toBeTrue();

    // …a stray UNFLAGGED voice client (the production bug)…
    Client::withoutGlobalScopes()
        ->where('salon_id', $salon->id)->where('name', ConnectionDiagnostics::VOICE_CLIENT_NAME)
        ->update(['is_test' => false]);

    // …and REAL data in this salon plus records in another salon.
    $realStylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $realService = serviceFor($salon, $realStylist, 60);
    $realClient = Client::withoutGlobalScopes()->create([
        'salon_id' => $salon->id, 'name' => 'Rhea Real', 'phone' => '+1 555 777 8888',
    ]);
    $otherRecords = app(ConnectionDiagnostics::class)->ensureTestRecords($other);

    app(ConnectionDiagnostics::class)->teardown($salon);

    // Every test record in THIS salon is gone — clients included.
    expect(Client::withoutGlobalScopes()->withTrashed()->where('salon_id', $salon->id)
        ->whereIn('name', [ConnectionDiagnostics::CLIENT_NAME, ConnectionDiagnostics::VOICE_CLIENT_NAME])->exists())->toBeFalse();
    expect(Service::withoutGlobalScopes()->withTrashed()->where('salon_id', $salon->id)->where('is_test', true)->exists())->toBeFalse();
    expect(User::withTrashed()->where('email', 'diagnostics+'.$salon->id.'@bluejaypro.invalid')->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->count())->toBe(0);

    // Real data untouched; the other salon's test records untouched.
    expect($realClient->refresh()->exists)->toBeTrue();
    expect($realService->refresh()->exists)->toBeTrue();
    expect(User::whereKey($realStylist->id)->exists())->toBeTrue();
    expect(Client::withoutGlobalScopes()->where('salon_id', $other->id)->where('is_test', true)->count())->toBe(2);
    expect($otherRecords['stylist']->refresh()->exists)->toBeTrue();
});

it('the hourly sweep clears both clients through the same teardown', function () {
    $salon = bookingSalon();
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon);
    expect(Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->count())->toBe(2);

    $salon->forceFill(['test_records_expire_at' => now()->subMinute()])->save();
    $this->artisan('diagnostics:sweep-test-records')->assertExitCode(0);

    expect(Client::withoutGlobalScopes()->withTrashed()->where('salon_id', $salon->id)->count())->toBe(0);
    expect($salon->refresh()->test_records_expire_at)->toBeNull();
});

it('gives both test clients their FIXED non-deliverable contacts — created and backfilled alike', function () {
    $salon = bookingSalon();

    // Fresh creation: exactly the canonical values.
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon);
    $main = Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('name', ConnectionDiagnostics::CLIENT_NAME)->sole();
    $voice = Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('name', ConnectionDiagnostics::VOICE_CLIENT_NAME)->sole();

    expect($main->email)->toBe('bjptestclient@bluejaypro.invalid');
    expect($main->phone)->toBe('+1 555 010 0000');
    expect($voice->email)->toBe('bjpvoiceaitestclient@bluejaypro.invalid');
    expect($voice->phone)->toBe('+1 555 010 0001');
    expect($main->is_test && $voice->is_test)->toBeTrue();

    // Existing rows with old generated/missing contact normalize: via the
    // ensure path…
    $main->forceFill(['email' => 'test-client+96@bluejaypro.invalid', 'phone' => null])->save();
    $voice->forceFill(['email' => null])->save();
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon);
    expect($main->refresh()->email)->toBe(ConnectionDiagnostics::CLIENT_EMAIL);
    expect($main->phone)->toBe(ConnectionDiagnostics::CLIENT_PHONE);
    expect($voice->refresh()->email)->toBe(ConnectionDiagnostics::VOICE_CLIENT_EMAIL);

    // …and via the deploy-time backfill, which never touches a real client.
    $main->forceFill(['email' => 'test-client+96@bluejaypro.invalid'])->save();
    $real = Client::withoutGlobalScopes()->create([
        'salon_id' => $salon->id, 'name' => 'Rhea Real', 'phone' => '+1 555 777 8888', 'email' => 'rhea@example.com',
    ]);
    (require database_path('migrations/2026_08_17_000002_normalize_designated_test_client_contacts.php'))->up();
    expect($main->refresh()->email)->toBe(ConnectionDiagnostics::CLIENT_EMAIL);
    expect($real->refresh()->email)->toBe('rhea@example.com');
    expect($real->phone)->toBe('+1 555 777 8888');
});

it('keeps the two phones distinct so phone lookups resolve the right client', function () {
    $salon = bookingSalon();
    app(ConnectionDiagnostics::class)->ensureTestRecords($salon);

    // findClientByPhone is the engine's private lookup — reflect for a
    // direct assertion of exactly which client each phone resolves to.
    $lookup = new ReflectionMethod(VoiceBookingApi::class, 'findClientByPhone');
    $api = app(VoiceBookingApi::class);
    $byMain = $lookup->invoke($api, $salon, '5550100000');
    $byVoice = $lookup->invoke($api, $salon, '+1 (555) 010-0001');

    expect($byMain?->name)->toBe(ConnectionDiagnostics::CLIENT_NAME);
    expect($byVoice?->name)->toBe(ConnectionDiagnostics::VOICE_CLIENT_NAME);
    expect($byMain->id)->not->toBe($byVoice->id);
});
