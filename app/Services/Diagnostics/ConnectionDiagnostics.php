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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The "Check connections" engine: disposable, leak-proof test records plus
 * the BookTheStyle-side checks, each reported in plain language.
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

    /** Abandoned test records older than this are swept. */
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
    public function ensureTestRecords(Salon $salon): array
    {
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

            $client = Client::withoutGlobalScopes()->firstOrCreate(
                ['salon_id' => $salon->id, 'name' => self::CLIENT_NAME, 'is_test' => true],
                ['phone' => '+1 555 010 0000', 'email' => 'test-client+'.$salon->id.'@bluejaypro.invalid'],
            );

            return ['stylist' => $stylist, 'service' => $service, 'client' => $client];
        });
    }

    /**
     * Run every BTS-side check. Each line: key, label, passed, and a plain-
     * language message a non-engineer can act on.
     *
     * @return list<array{key: string, label: string, passed: bool, message: string}>
     */
    public function run(Salon $salon): array
    {
        $records = $this->ensureTestRecords($salon);
        $report = [];

        // 1 — API token issued.
        $tokenIssued = $salon->api_token_hash !== null;
        $report[] = $this->line('token', __('Booking API token'), $tokenIssued, $tokenIssued
            ? __('A token was generated on :date. Its correctness is proven by the GHL round-trip below — BookTheStyle only stores a fingerprint, so make sure GHL holds the latest copy.', ['date' => $salon->api_token_generated_at?->toFormattedDateString() ?? '—'])
            : __('No token has been generated yet. Open Settings → Integrations → Voice-AI Booking API, generate one, and paste it into both GHL Custom Actions.'));

        // 2 — booking endpoint publicly reachable (answers with its own 401
        // for a bad token: routing, TLS, and auth all alive).
        $report[] = $this->endpointCheck();

        // 3 — availability finds slots for the test records via the REAL
        // engine (the same code path the Voice AI hits).
        $slot = null;
        try {
            $availability = $this->api->availability($salon, ['service' => self::SERVICE_NAME]);
            $slot = $availability['slots'][0] ?? null;
            $report[] = $this->line('availability', __('Availability'), $slot !== null, $slot !== null
                ? __('The engine found :count open times for :service with :stylist — availability works.', ['count' => count($availability['slots']), 'service' => self::SERVICE_NAME, 'stylist' => self::STYLIST_NAME])
                : __('No open times came back for the test stylist even with full hours — check the salon\'s booking policy (advance-notice limits) and try again.'));
        } catch (ApiError $e) {
            $report[] = $this->line('availability', __('Availability'), false, __('The availability engine refused the test request: :reason', ['reason' => $e->toResponse()['message'] ?? $e->errorCode]));
        }

        // 4 — a REAL booking through the same path the Voice AI uses.
        if ($slot !== null) {
            try {
                $created = $this->api->create($salon, [
                    'service' => self::SERVICE_NAME,
                    'stylist' => self::STYLIST_NAME,
                    'date' => $slot['date'],
                    'time' => $slot['time'],
                    'client' => ['name' => self::CLIENT_NAME, 'phone' => $records['client']->phone, 'email' => $records['client']->email],
                    'notes' => 'Connection check — safe to ignore; cleaned up automatically.',
                ]);
                $ok = ($created['success'] ?? false) === true;
                $report[] = $this->line('booking', __('Test booking'), $ok, $ok
                    ? __(':client booked :service with :stylist for :time — the booking engine works end to end. The test appointment is removed at clean-up.', ['client' => self::CLIENT_NAME, 'service' => self::SERVICE_NAME, 'stylist' => self::STYLIST_NAME, 'time' => $created['confirmation']['spoken_time'] ?? $slot['spoken']])
                    : __('The engine did not confirm the booking: :reason', ['reason' => $created['message'] ?? __('unknown')]));
            } catch (ApiError $e) {
                $report[] = $this->line('booking', __('Test booking'), false, __('The booking was refused: :reason', ['reason' => $e->toResponse()['message'] ?? $e->errorCode]));
            }
        } else {
            $report[] = $this->line('booking', __('Test booking'), false, __('Skipped — no open time was available to book (fix availability first).'));
        }

        // 5 — webhook secret configured.
        $connection = $salon->ghlConnection()->first();
        $hasSecret = filled($connection?->webhook_secret);
        $report[] = $this->line('webhook', __('Inbound webhook secret'), $hasSecret, $hasSecret
            ? __('A webhook secret is set — GHL events sent with the matching X-Webhook-Secret header will be accepted.')
            : __('No webhook secret is set, so GHL-side booking events cannot flow back in. Set it on Settings → Integrations and in the GHL workflow that posts to the webhook.'));

        // 6 — GHL connection configured (location / calendar / token).
        $ghlConfigured = $connection !== null && filled($connection->location_id) && $connection->hasToken();
        $report[] = $this->line('ghl', __('GoHighLevel connection'), $ghlConfigured, $ghlConfigured
            ? __('Location and token are configured:verified. Use the Integrations tab\'s own verify buttons for a live GHL API test.', ['verified' => $connection->last_verified_at !== null ? __(' (last verified :when)', ['when' => $connection->last_verified_at->diffForHumans()]) : ''])
            : __('The GHL connection is not set up (location id / integration token missing). Bookings still work in BookTheStyle, but nothing will sync to GHL until it is connected.'));

        // 7 — the public widget answers on the salon's own address.
        $report[] = $this->widgetCheck($salon);

        return $report;
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
            $client = Client::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->first();

            $bookings = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)
                ->where(fn ($q) => $q
                    ->when($client !== null, fn ($qq) => $qq->orWhere('client_id', $client->id))
                    ->when($stylist !== null, fn ($qq) => $qq->orWhereHas('items', fn ($i) => $i->where('stylist_id', $stylist->id))))
                ->get();
            $bookings->each->delete();

            if ($stylist !== null) {
                Availability::withoutGlobalScopes()->where('salon_id', $salon->id)->where('user_id', $stylist->id)->delete();
                DB::table('stylist_profiles')->where('salon_id', $salon->id)->where('user_id', $stylist->id)->delete();
                $salon->memberships()->where('user_id', $stylist->id)->delete();
                $stylist->forceDelete();
            }

            Service::withoutGlobalScopes()->where('salon_id', $salon->id)->where('is_test', true)->get()->each->delete();
            $client?->delete();

            Cache::forget(self::lastCallKey($salon));
        });
    }

    /** @return array{key: string, label: string, passed: bool, message: string} */
    private function line(string $key, string $label, bool $passed, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'passed' => $passed, 'message' => $message];
    }

    /** @return array{key: string, label: string, passed: bool, message: string} */
    private function endpointCheck(): array
    {
        $url = route('api.booking.availability');

        try {
            $response = Http::timeout(8)->withToken('btsk_0_'.str_repeat('0', 40))->post($url, []);
            $reachable = $response->status() === 401;

            return $this->line('endpoint', __('Booking endpoint'), $reachable, $reachable
                ? __('The public booking endpoint answers and rejects bad credentials correctly — GHL can reach it.')
                : __('The endpoint answered with an unexpected status (:status) — a proxy or firewall rule may be interfering. Check the Cloudflare WAF skip list for /api/v1/booking/*.', ['status' => $response->status()]));
        } catch (\Throwable) {
            return $this->line('endpoint', __('Booking endpoint'), false, __('The public booking endpoint did not answer at :url — GHL will not reach it either. Check DNS/Cloudflare for the app host.', ['url' => $url]));
        }
    }

    /** @return array{key: string, label: string, passed: bool, message: string} */
    private function widgetCheck(Salon $salon): array
    {
        $url = route('salon.widget.services', ['salon' => $salon->slug]);

        try {
            $response = Http::timeout(8)->get($url);
            // 429 = the widget's own rate limiter answered — the endpoint is
            // alive and reachable, which is exactly what this check proves.
            $ok = $response->successful() || $response->status() === 429;

            return $this->line('widget', __('Booking widget'), $ok, $ok
                ? __('The public widget answers on the salon\'s own address — the embed on their website will work.')
                : __('The widget endpoint answered :status on :url — check that the salon\'s subdomain exists in hPanel and is proxied by Cloudflare.', ['status' => $response->status(), 'url' => $url]));
        } catch (\Throwable) {
            return $this->line('widget', __('Booking widget'), false, __('The widget did not answer at :url — usually the salon\'s subdomain is missing in hPanel (hostnames are created by hand, never by the app).', ['url' => $url]));
        }
    }
}
