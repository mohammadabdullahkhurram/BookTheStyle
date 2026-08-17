<?php

namespace App\Services\Diagnostics;

use App\Enums\AvailabilityKind;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Enums\StylistArrangement;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingApi\ApiError;
use App\Services\BookingApi\VoiceBookingApi;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The health check's disposable-record manager: creates/tears down the
 * leak-proof is_test records, builds the GHL round-trip payloads, and
 * tracks the last authenticated API call. The CHECKS themselves live in
 * App\Services\Health (HealthCheckRegistry + one small class per check).
 *
 * THE HONEST SPLIT: everything here exercises the BTS side only — the real
 * slot engine, the real booking path, the real public endpoints. BTS cannot
 * fire a GHL Custom Action itself (those originate inside GHL), so the GHL
 * wiring is verified by the manual round-trip on the page: ready-to-paste
 * payloads + the last-received-call indicator (recorded by
 * AuthenticateBookingApi). Never present the BTS checks as proof of GHL.
 *
 * Test records are flagged is_test and excluded from every client-facing
 * surface (widget catalogue AND guessed-id lookups, the in-app booking
 * flow, client directory, reports, GHL sync). Teardown removes them plus
 * any bookings they took; diagnostics:sweep-test-records catches abandoned
 * runs so a live salon never keeps a phantom test stylist.
 */
class ConnectionDiagnostics
{
    public const STYLIST_NAME = 'Bluejaypro Stylist';

    public const SERVICE_NAME = 'Bluejaypro Hair Cut';

    public const CLIENT_NAME = 'Bluejaypro Test Client';

    public const CLIENT_PHONE = '+1 555 010 0000';

    /** For MANUAL Voice AI Custom Action end-to-end testing (book/cancel/reschedule). */
    public const VOICE_CLIENT_NAME = 'Bluejaypro Voice AI Test Client';

    public const VOICE_CLIENT_PHONE = '+1 555 010 0001';

    /**
     * The health check's test appointment is PINNED to one fixed slot —
     * 28 June 3004 at 2:00 PM, salon timezone. Far-future on purpose: real
     * booking policy caps advance booking at a year, so nobody can ever
     * hold this slot, and the test never drifts into or collides with real
     * testing. A previous run's appointment at the slot is REUSED, never
     * duplicated, never moved to a different day or time.
     */
    public const TEST_BOOKING_DATE = '3004-06-28';

    public const TEST_BOOKING_TIME = '2:00 PM';

    /** Health-check-created/refreshed records expire this soon after a run. */
    public const TTL_RUN_MINUTES = 60;

    /** Salon-setup records get a longer window before the sweep takes them. */
    public const TTL_SETUP_HOURS = 48;

    /** Legacy fallback: is_test records with NO expiry are swept after this. */
    public const SWEEP_AFTER_HOURS = 24;

    public function __construct(private VoiceBookingApi $api) {}

    /**
     * Cache key of the last authenticated booking-API call for a salon —
     * written by AuthenticateBookingApi, read by the round-trip indicator.
     */
    public static function lastCallKey(Salon $salon): string
    {
        return 'diagnostics:last-api-call:'.$salon->id;
    }

    /** @return array{at: string, path: string}|null */
    public static function lastReceivedCall(Salon $salon): ?array
    {
        return Cache::get(self::lastCallKey($salon));
    }

    /**
     * Create (idempotently) the disposable records for THIS salon — works
     * on any salon, already-built ones included.
     *
     * @return array{stylist: User, service: Service, client: Client}
     */
    public function ensureTestRecords(Salon $salon, ?CarbonInterface $expiresAt = null): array
    {
        // The hard-TTL clock: every create/refresh stamps the salon's
        // expiry — the sweep enforces it no matter what else happens.
        $salon->forceFill([
            'test_records_expire_at' => $expiresAt ?? now()->addMinutes(self::TTL_RUN_MINUTES),
        ])->save();

        return DB::transaction(function () use ($salon): array {
            $stylist = User::withTrashed()->firstOrCreate(
                ['email' => 'diagnostics+'.$salon->id.'@bluejaypro.invalid'],
                [
                    'name' => self::STYLIST_NAME,
                    'password' => Str::random(40), // never logs in
                    'must_change_password' => false,
                    'email_verified_at' => now(),
                ],
            );
            $stylist->forceFill(['is_test' => true, 'deleted_at' => null])->save();

            $salon->memberships()->updateOrCreate(
                ['user_id' => $stylist->id],
                ['salon_role' => SalonRole::Stylist, 'staff_type' => StaffType::Stylist, 'arrangement' => StylistArrangement::Employee, 'active' => true],
            );

            // Full availability, every day — slots must exist regardless of
            // when the check runs or how the real salon's week looks.
            foreach (range(0, 6) as $weekday) {
                Availability::withoutGlobalScopes()->firstOrCreate([
                    'salon_id' => $salon->id,
                    'user_id' => $stylist->id,
                    'weekday' => $weekday,
                    'kind' => AvailabilityKind::Work,
                    'start_minute' => 8 * 60,
                    'end_minute' => 20 * 60,
                ]);
            }

            $service = Service::withoutGlobalScopes()->firstOrCreate(
                ['salon_id' => $salon->id, 'name' => self::SERVICE_NAME, 'is_test' => true],
                ['duration_min' => 30, 'active' => true],
            );
            // Qualified for the test stylist ONLY — that is what keeps the
            // stylist unreachable through every real service.
            $service->stylists()->sync([$stylist->id => ['salon_id' => $salon->id]]);

            // Both designated clients match by NAME alone and get the flag
            // FORCED — an unflagged survivor is reclaimed, never duplicated.
            $client = Client::withoutGlobalScopes()->withTrashed()->firstOrCreate(
                ['salon_id' => $salon->id, 'name' => self::CLIENT_NAME],
                ['is_test' => true, 'phone' => self::CLIENT_PHONE, 'email' => 'test-client+'.$salon->id.'@bluejaypro.invalid'],
            );
            $client->forceFill(['is_test' => true, 'deleted_at' => null])->save();

            // The second designated client: the Voice AI test lane — used
            // for MANUAL Custom Action round-trips (book/cancel/reschedule
            // by phone), window-exempt like every is_test client.
            Client::withoutGlobalScopes()->withTrashed()->firstOrCreate(
                ['salon_id' => $salon->id, 'name' => self::VOICE_CLIENT_NAME],
                ['is_test' => true, 'phone' => self::VOICE_CLIENT_PHONE, 'email' => 'voice-test-client+'.$salon->id.'@bluejaypro.invalid'],
            )->forceFill(['is_test' => true, 'deleted_at' => null])->save();

            return ['stylist' => $stylist, 'service' => $service, 'client' => $client];
        });
    }

    /**
     * Whether a client identity IS one of the two designated test clients —
     * matched by reserved name (exact) or designated phone (format-blind).
     * This is the single authority every creation funnel and the teardown
     * consult, so a test client can never exist unflagged: the voice lane
     * and the health check book these clients by NAME/PHONE through the
     * shared engine, and a re-creation that raced a sweep used to mint an
     * unflagged row — invisible to the badge and to every is_test-keyed
     * cleanup.
     */
    public static function isDesignatedTestClient(?string $name, ?string $phone): bool
    {
        if (in_array(trim((string) $name), [self::CLIENT_NAME, self::VOICE_CLIENT_NAME], true)) {
            return true;
        }

        foreach ([self::CLIENT_PHONE, self::VOICE_CLIENT_PHONE] as $designated) {
            if ($phone !== null && trim($phone) !== '' && PhoneNumber::matches($phone, $designated)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The designated test client ALONE (no stylist/service/availability) —
     * what the widget-preview test booking rides on. Same identity keys as
     * ensureTestRecords, so the two lanes share one client; the sweep TTL
     * is stamped (extended, never shortened) so an abandoned preview
     * booking ages out through the exact same teardown as everything else.
     */
    public function ensureTestClient(Salon $salon): Client
    {
        $expiry = now()->addMinutes(self::TTL_RUN_MINUTES);

        if ($salon->test_records_expire_at === null || $salon->test_records_expire_at->lt($expiry)) {
            $salon->forceFill(['test_records_expire_at' => $expiry])->save();
        }

        // Match by NAME alone: a designated row that somehow lost its flag
        // is reclaimed and re-flagged, never duplicated.
        $client = Client::withoutGlobalScopes()->withTrashed()->firstOrCreate(
            ['salon_id' => $salon->id, 'name' => self::CLIENT_NAME],
            ['is_test' => true, 'phone' => self::CLIENT_PHONE, 'email' => 'test-client+'.$salon->id.'@bluejaypro.invalid'],
        );
        $client->forceFill(['is_test' => true, 'deleted_at' => null])->save();

        return $client;
    }

    /** Whether this salon currently holds any disposable test records. */
    public function hasTestRecords(Salon $salon): bool
    {
        return Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->exists()
            || $salon->memberships()->whereHas('user', fn ($q) => $q->where('is_test', true))->exists();
    }

    /** The pinned test-appointment instant in the salon's timezone. */
    public static function testBookingInstant(Salon $salon): CarbonImmutable
    {
        return CarbonImmutable::parse(
            self::TEST_BOOKING_DATE.' '.self::TEST_BOOKING_TIME,
            $salon->timezone,
        );
    }

    /**
     * The ready-to-paste GHL round-trip payloads, pre-filled with the test
     * records and a genuinely open slot (a later one, so it does not collide
     * with the check's own test booking).
     *
     * @return array{availability: array<string, mixed>, create: array<string, mixed>|null}
     */
    public function roundTripPayloads(Salon $salon): array
    {
        $create = null;

        try {
            $slots = $this->api->availability($salon, ['service' => self::SERVICE_NAME])['slots'] ?? [];
            $slot = $slots[1] ?? $slots[0] ?? null;

            if ($slot !== null) {
                $create = [
                    'service' => self::SERVICE_NAME,
                    'stylist' => self::STYLIST_NAME,
                    'date' => $slot['date'],
                    'time' => $slot['time'],
                    'client' => ['name' => self::CLIENT_NAME, 'phone' => '+1 555 010 0000'],
                    'notes' => 'GHL round-trip test — cleaned up automatically.',
                ];
            }
        } catch (ApiError) {
            // No open slot — the availability payload alone still proves the wiring.
        }

        return [
            'availability' => ['service' => self::SERVICE_NAME],
            'create' => $create,
        ];
    }

    /**
     * Tear down everything the check created: the test stylist (account,
     * membership, availability, profile), service, client, and every
     * appointment either of them is on.
     */
    public function teardown(Salon $salon): void
    {
        DB::transaction(function () use ($salon): void {
            $stylist = User::withTrashed()->where('email', 'diagnostics+'.$salon->id.'@bluejaypro.invalid')->first();
            // is_test OR the reserved designated names: the names are minted
            // only by this class, so a row that lost its flag is still test
            // data and must go — real clients can never carry these names.
            $clients = Client::withoutGlobalScopes()->withTrashed()->where('salon_id', $salon->id)
                ->where(fn ($q) => $q
                    ->where('is_test', true)
                    ->orWhereIn('name', [self::CLIENT_NAME, self::VOICE_CLIENT_NAME]))
                ->get();

            $bookings = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)
                ->where(fn ($q) => $q
                    ->when($clients->isNotEmpty(), fn ($qq) => $qq->orWhereIn('client_id', $clients->pluck('id')))
                    ->when($stylist !== null, fn ($qq) => $qq->orWhereHas('items', fn ($i) => $i->where('stylist_id', $stylist->id))))
                ->get();
            $bookings->each->delete();

            if ($stylist !== null) {
                Availability::withoutGlobalScopes()->where('salon_id', $salon->id)->where('user_id', $stylist->id)->delete();
                DB::table('stylist_profiles')->where('salon_id', $salon->id)->where('user_id', $stylist->id)->delete();
                $salon->memberships()->where('user_id', $stylist->id)->delete();
                $stylist->forceDelete();
            }

            // forceDelete: Client/Service are SoftDeletes now (solo-delete
            // tombstones) — disposable test records must actually go.
            Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->get()->each->forceDelete();
            $clients->each->forceDelete();

            $salon->forceFill(['test_records_expire_at' => null])->save();
            Cache::forget(self::lastCallKey($salon));
        });
    }
}
