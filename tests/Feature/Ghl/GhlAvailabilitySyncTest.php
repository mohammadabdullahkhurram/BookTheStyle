<?php

use App\Actions\Availability\AddTimeOff;
use App\Actions\Availability\SaveWeeklyHours;
use App\Enums\AvailabilityKind;
use App\Jobs\SyncAvailabilityToGhl;
use App\Jobs\SyncGhlCalendarSlotSettings;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\TimeOff;
use App\Models\User;
use App\Services\Ghl\GhlAvailabilityPusher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
| Phase 6e: the app's availability pushed INTO GoHighLevel. Each mapped
| stylist gets one GHL user availability schedule (weekly wday rules + date
| overrides for time off, salon timezone) applied to the master calendar;
| calendar slot settings mirror the worst-case service duration/buffer.
| Conservative mapping: GHL may under-offer, never over-offer.
*/

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')); // Mon 08:00 EDT
});
afterEach(fn () => Carbon::setTestNow());

function avSalon(): Salon
{
    $salon = bookingSalon(); // America/New_York
    SalonGhlConnection::factory()->for($salon)->create([
        'location_id' => 'loc_av',
        'private_integration_token' => 'pit-secret',
        'calendar_id' => 'cal_master',
    ]);

    return $salon;
}

function avStylist(Salon $salon, string $providerId = 'prov_av'): User
{
    $stylist = stylistOf($salon);
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => $providerId],
    );

    return $stylist;
}

function avProfile(Salon $salon, User $stylist): StylistProfile
{
    return StylistProfile::forSalon($salon)->where('user_id', $stylist->id)->firstOrFail();
}

/** @param list<array{0: int, 1: int}> $windows minutes from midnight */
function avWindows(Salon $salon, User $stylist, int $weekday, array $windows, AvailabilityKind $kind = AvailabilityKind::Work): void
{
    foreach ($windows as [$start, $end]) {
        Availability::factory()->create([
            'salon_id' => $salon->id, 'user_id' => $stylist->id,
            'weekday' => $weekday, 'kind' => $kind->value,
            'start_minute' => $start, 'end_minute' => $end,
        ]);
    }
}

/** Fake the schedule + calendar endpoints (create returns $scheduleId). */
function avFakeGhl(string $scheduleId = 'sched_1'): void
{
    Http::fake([
        'services.leadconnectorhq.com/calendars/schedules/*' => Http::response(['ok' => true]), // update + association
        'services.leadconnectorhq.com/calendars/schedules' => Http::response(['schedule' => ['id' => $scheduleId]], 201),
        'services.leadconnectorhq.com/calendars/cal_master' => Http::response(['calendar' => ['id' => 'cal_master']]),
    ]);
}

// ---------------------------------------------------------------------------
// The pushed payload
// ---------------------------------------------------------------------------

it('pushes weekly hours (with breaks and splits) as a GHL user availability schedule', function () {
    $salon = avSalon();
    $stylist = avStylist($salon);
    avWindows($salon, $stylist, 0, [[540, 1020]]);                       // Mon 09:00–17:00
    avWindows($salon, $stylist, 0, [[720, 780]], AvailabilityKind::Break); // Mon lunch 12:00–13:00
    avWindows($salon, $stylist, 1, [[540, 720], [840, 1080]]);           // Tue split 09:00–12:00, 14:00–18:00

    avFakeGhl();
    app(GhlAvailabilityPusher::class)->push(avProfile($salon, $stylist));

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/calendars/schedules')) {
            return false;
        }
        $body = $request->data();
        $monday = collect($body['rules'])->firstWhere('day', 'monday');
        $tuesday = collect($body['rules'])->firstWhere('day', 'tuesday');

        return $body['userId'] === 'prov_av'
            && $body['timezone'] === 'America/New_York'
            && $body['locationId'] === 'loc_av'
            && $body['calendarIds'] === ['cal_master']
            && $monday['type'] === 'wday'
            && $monday['intervals'] === [['from' => '09:00', 'to' => '12:00'], ['from' => '13:00', 'to' => '17:00']]
            && $tuesday['intervals'] === [['from' => '09:00', 'to' => '12:00'], ['from' => '14:00', 'to' => '18:00']];
    });

    // The schedule is applied to the master calendar and tracked locally.
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/schedules/sched_1/associations/cal_master'));

    $profile = avProfile($salon, $stylist)->fresh();
    expect($profile->ghl_schedule_id)->toBe('sched_1');
    expect($profile->ghl_availability_status)->toBe(GhlAvailabilityPusher::STATUS_SYNCED);
    expect($profile->ghl_availability_synced_at)->not->toBeNull();
});

it('re-pushes edited hours as an UPDATE to the same schedule — never a duplicate', function () {
    $salon = avSalon();
    $stylist = avStylist($salon);
    avWindows($salon, $stylist, 0, [[540, 1020]]);

    avFakeGhl();
    $pusher = app(GhlAvailabilityPusher::class);
    $pusher->push(avProfile($salon, $stylist));

    avWindows($salon, $stylist, 2, [[600, 960]]); // Wed 10:00–16:00 added
    $pusher->push(avProfile($salon, $stylist)->fresh());

    Http::assertSent(function ($request): bool {
        if ($request->method() !== 'PUT' || ! str_ends_with($request->url(), '/calendars/schedules/sched_1')) {
            return false;
        }
        $wednesday = collect($request->data()['rules'])->firstWhere('day', 'wednesday');

        return $wednesday !== null
            && $wednesday['intervals'] === [['from' => '10:00', 'to' => '16:00']];
    });

    // Exactly one CREATE ever.
    Http::assertSentCount(4); // create + assoc, then update + assoc — and nothing else
    expect(avProfile($salon, $stylist)->fresh()->ghl_schedule_id)->toBe('sched_1');
});

it('maps time off to date-specific overrides carrying only the REMAINING hours', function () {
    $salon = avSalon();
    $stylist = avStylist($salon);
    avWindows($salon, $stylist, 1, [[540, 1020]]); // Tue 09:00–17:00
    avWindows($salon, $stylist, 2, [[540, 1020]]); // Wed 09:00–17:00

    // Tue 2026-06-23: off 10:00–12:00 (partial). Wed 2026-06-24: whole day.
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 12:00', $salon->timezone),
    ]);
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-24 00:00', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-25 00:00', $salon->timezone),
    ]);

    $rules = GhlAvailabilityPusher::rulesFor($salon, $stylist->id);

    $tuesday = collect($rules)->firstWhere('date', '2026-06-23');
    expect($tuesday['type'])->toBe('date');
    expect($tuesday['intervals'])->toBe([['from' => '09:00', 'to' => '10:00'], ['from' => '12:00', 'to' => '17:00']]);

    $wednesday = collect($rules)->firstWhere('date', '2026-06-24');
    expect($wednesday['intervals'])->toBe([]); // fully off — GHL offers nothing
});

// ---------------------------------------------------------------------------
// Conservative mapping — never over-offer
// ---------------------------------------------------------------------------

it('always pushes a SUBSET of app availability where GHL is coarser', function () {
    $salon = avSalon();
    $stylist = avStylist($salon);
    avWindows($salon, $stylist, 0, [[540, 1440]]); // Mon 09:00–24:00
    avWindows($salon, $stylist, 1, [[540, 1020]]); // Tue 09:00–17:00

    // Time off with stray seconds: 10:00:30 – 11:59:30 on Tue 2026-06-23.
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-23 10:00:30', $salon->timezone),
        'ends_at' => CarbonImmutable::parse('2026-06-23 11:59:30', $salon->timezone),
    ]);

    $rules = GhlAvailabilityPusher::rulesFor($salon, $stylist->id);

    // 24:00 is not a valid HH:MM — the window shrinks to 23:59 (subset).
    $monday = collect($rules)->firstWhere('day', 'monday');
    expect($monday['intervals'])->toBe([['from' => '09:00', 'to' => '23:59']]);

    // The OFF block widens to whole minutes (10:00–12:00): the availability
    // around it can only shrink, never grow.
    $tuesday = collect($rules)->firstWhere('date', '2026-06-23');
    expect($tuesday['intervals'])->toBe([['from' => '09:00', 'to' => '10:00'], ['from' => '12:00', 'to' => '17:00']]);
});

it('keeps wall-clock hours across the DST fall-back (UTC time off lands on the right local hours)', function () {
    $salon = avSalon(); // America/New_York — DST ends Sun 2026-11-01
    $stylist = avStylist($salon);
    avWindows($salon, $stylist, 6, [[540, 1020]]); // Sun 09:00–17:00

    // Stored in UTC: 14:00Z–16:00Z on 2026-11-01 = 09:00–11:00 EST (UTC-5).
    TimeOff::factory()->create([
        'salon_id' => $salon->id, 'user_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-11-01 14:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-01 16:00', 'UTC'),
    ]);

    $rules = GhlAvailabilityPusher::rulesFor($salon, $stylist->id);

    $sunday = collect($rules)->firstWhere('date', '2026-11-01');
    expect($sunday['intervals'])->toBe([['from' => '11:00', 'to' => '17:00']]);
});

// ---------------------------------------------------------------------------
// Idempotency + self-healing
// ---------------------------------------------------------------------------

it('re-syncing unchanged availability makes no API call', function () {
    $salon = avSalon();
    $stylist = avStylist($salon);
    avWindows($salon, $stylist, 0, [[540, 1020]]);

    avFakeGhl();
    $pusher = app(GhlAvailabilityPusher::class);
    $pusher->push(avProfile($salon, $stylist));
    Http::assertSentCount(2); // create + association

    $pusher->push(avProfile($salon, $stylist)->fresh());
    Http::assertSentCount(2); // hash short-circuit: nothing more
});

it('recreates a schedule that was deleted inside GHL instead of failing forever', function () {
    $salon = avSalon();
    $stylist = avStylist($salon);
    avWindows($salon, $stylist, 0, [[540, 1020]]);

    $profile = avProfile($salon, $stylist);
    $profile->forceFill(['ghl_schedule_id' => 'sched_gone', 'ghl_availability_hash' => 'stale'])->save();

    Http::fake([
        'services.leadconnectorhq.com/calendars/schedules/sched_gone' => Http::response(['message' => 'not found'], 404),
        'services.leadconnectorhq.com/calendars/schedules/*' => Http::response(['ok' => true]),
        'services.leadconnectorhq.com/calendars/schedules' => Http::response(['schedule' => ['id' => 'sched_new']], 201),
    ]);

    app(GhlAvailabilityPusher::class)->push($profile);

    $profile->refresh();
    expect($profile->ghl_schedule_id)->toBe('sched_new');
    expect($profile->ghl_availability_status)->toBe(GhlAvailabilityPusher::STATUS_SYNCED);
});

// ---------------------------------------------------------------------------
// When sync fires
// ---------------------------------------------------------------------------

it('queues the sync when weekly hours are saved or time off is added — mapped stylists only', function () {
    $salon = avSalon();
    $mapped = avStylist($salon);
    $unmapped = stylistOf($salon);

    Bus::fake([SyncAvailabilityToGhl::class]);

    $week = [0 => [['start_minute' => 540, 'end_minute' => 1020]]];
    app(SaveWeeklyHours::class)->handle(salonOwnerOf($salon), $salon, $mapped->id, $week);
    Bus::assertDispatched(SyncAvailabilityToGhl::class, 1);

    app(AddTimeOff::class)->handle(salonOwnerOf($salon), $salon, $mapped->id, [
        'type' => 'vacation', 'starts_at' => '2026-06-23 10:00', 'ends_at' => '2026-06-23 12:00',
    ]);
    Bus::assertDispatched(SyncAvailabilityToGhl::class, 2);

    // Unmapped stylist: the hook quietly does nothing.
    app(SaveWeeklyHours::class)->handle(salonOwnerOf($salon), $salon, $unmapped->id, $week);
    Bus::assertDispatched(SyncAvailabilityToGhl::class, 2);
});

it('syncs all mapped stylists from the manual settings action and skips the unmapped', function () {
    $salon = avSalon();
    $anna = avStylist($salon, 'prov_anna');
    $ben = avStylist($salon, 'prov_ben');
    stylistOf($salon); // unmapped — never pushed

    // Another salon's mapped stylist must be untouched (tenant isolation).
    $other = avSalon();
    $otherStylist = avStylist($other, 'prov_other');

    Bus::fake([SyncAvailabilityToGhl::class, SyncGhlCalendarSlotSettings::class]);
    test()->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->call('syncGhlAvailability')
        ->assertHasNoErrors();

    $ids = [avProfile($salon, $anna)->id, avProfile($salon, $ben)->id];
    Bus::assertDispatched(SyncAvailabilityToGhl::class, fn ($job): bool => in_array($job->stylistProfileId, $ids, true));
    Bus::assertDispatchedTimes(SyncAvailabilityToGhl::class, 2);
    Bus::assertDispatched(SyncGhlCalendarSlotSettings::class, fn ($job): bool => $job->salonId === $salon->id);

    expect(avProfile($salon, $anna)->ghl_availability_status)->toBe(GhlAvailabilityPusher::STATUS_PENDING);
    expect(avProfile($other, $otherStylist)->ghl_availability_status)->toBeNull();
});

it('no-ops the manual sync for an unconnected salon', function () {
    $salon = bookingSalon(); // no GHL connection
    StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => stylistOf($salon)->id],
        ['ghl_user_id' => 'prov_x'],
    );

    Bus::fake([SyncAvailabilityToGhl::class, SyncGhlCalendarSlotSettings::class]);
    test()->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->call('syncGhlAvailability')
        ->assertHasNoErrors();

    Bus::assertNothingDispatched();
});

// ---------------------------------------------------------------------------
// Errors surfaced + retry
// ---------------------------------------------------------------------------

it('surfaces a failed availability sync on the settings page and retries it', function () {
    $salon = avSalon();
    $stylist = avStylist($salon);

    // What the queue does after the final attempt fails.
    $job = new SyncAvailabilityToGhl(avProfile($salon, $stylist)->id);
    $job->failed(new RuntimeException('GoHighLevel returned an unexpected error (HTTP 500). Try again shortly.'));

    $profile = avProfile($salon, $stylist)->fresh();
    expect($profile->ghl_availability_status)->toBe(GhlAvailabilityPusher::STATUS_FAILED);
    expect($profile->ghl_availability_error)->toContain('HTTP 500');
    expect($profile->ghl_availability_error)->not->toContain('pit-secret');

    Bus::fake([SyncAvailabilityToGhl::class]);
    test()->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->assertSee('Availability sync')
        ->assertSee('Failed')
        ->assertSee('Retry sync')
        ->call('retryGhlAvailability', $profile->id)
        ->assertHasNoErrors();

    Bus::assertDispatched(SyncAvailabilityToGhl::class, fn ($job): bool => $job->stylistProfileId === $profile->id);
    expect($profile->fresh()->ghl_availability_status)->toBe(GhlAvailabilityPusher::STATUS_PENDING);
});

it('never retries another salon\'s stylist profile', function () {
    $salonA = avSalon();
    $salonB = avSalon();
    $foreign = avProfile($salonB, avStylist($salonB, 'prov_b'));

    test()->actingAs(salonOwnerOf($salonA));

    expect(fn () => Livewire::test('pages::salon.settings', ['salon' => $salonA])
        ->call('retryGhlAvailability', $foreign->id))
        ->toThrow(ModelNotFoundException::class);
});

// ---------------------------------------------------------------------------
// Calendar slot settings
// ---------------------------------------------------------------------------

it('pushes worst-case slot settings: longest service duration, buffers only when the flag is on', function () {
    $salon = avSalon();
    $stylist = avStylist($salon);

    $short = Service::factory()->for($salon)->create(['duration_min' => 30, 'active' => true]);
    Service::factory()->for($salon)->create(['duration_min' => 60, 'active' => true]);
    Service::factory()->for($salon)->create(['duration_min' => 240, 'active' => false]); // inactive — ignored
    $short->stylists()->attach($stylist->id, ['salon_id' => $salon->id, 'duration_override' => 75, 'buffer_override' => 20]);

    avFakeGhl();
    $pusher = app(GhlAvailabilityPusher::class);

    // Buffers dormant (flag off): duration is the max of defaults + overrides.
    $pusher->pushCalendarSlotSettings($salon);
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/cal_master')
        && $r['slotDuration'] === 75 && $r['slotDurationUnit'] === 'mins'
        && $r['slotInterval'] === 15 && $r['slotBuffer'] === 0);

    // Flag on: the longest cleanup buffer applies.
    $salon->update(['feature_flags' => ['stylist_buffers' => true]]);
    $pusher->pushCalendarSlotSettings($salon->fresh());
    Http::assertSent(fn ($r): bool => $r->method() === 'PUT'
        && str_ends_with($r->url(), '/calendars/cal_master')
        && $r['slotBuffer'] === 20);
});

it('skips gracefully when unmapped or unconnected', function () {
    // Unmapped stylist on a connected salon.
    $salon = avSalon();
    $stylist = stylistOf($salon);
    $profile = StylistProfile::updateOrCreate(
        ['salon_id' => $salon->id, 'user_id' => $stylist->id],
        ['ghl_user_id' => null],
    );

    app(GhlAvailabilityPusher::class)->push($profile);
    expect($profile->fresh()->ghl_availability_status)->toBe(GhlAvailabilityPusher::STATUS_SKIPPED);

    // Mapped stylist on an unconnected salon.
    $bare = bookingSalon();
    $bareProfile = StylistProfile::updateOrCreate(
        ['salon_id' => $bare->id, 'user_id' => stylistOf($bare)->id],
        ['ghl_user_id' => 'prov_z'],
    );

    app(GhlAvailabilityPusher::class)->push($bareProfile);
    expect($bareProfile->fresh()->ghl_availability_status)->toBe(GhlAvailabilityPusher::STATUS_SKIPPED);

    Http::assertNothingSent();
});
