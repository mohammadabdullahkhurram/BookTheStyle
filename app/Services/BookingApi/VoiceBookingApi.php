<?php

namespace App\Services\BookingApi;

use App\Actions\Bookings\CreateBooking;
use App\Actions\Clients\CreateClient;
use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\DurationResolver;
use App\Services\Booking\ResolvedDuration;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The Voice-AI Booking API's brain — a thin, tenant-scoped layer over the
 * EXISTING engine: SlotEngine for truth about availability, DurationResolver
 * for exact per-stylist minutes, CreateBooking for the locked, re-validated
 * booking path (which also queues the GHL outbound push). Nothing here
 * re-implements booking; this resolves fuzzy voice input (service/stylist
 * names), shapes compact voice-friendly responses, and handles the two
 * realities of mid-call booking: races (slot taken since it was offered →
 * alternatives) and retries (idempotent replay returns the same booking).
 *
 * Every result is an array ready for JSON, always carrying a speakable
 * `message`. Failures raise ApiError (clean JSON upstream, never a trace).
 */
class VoiceBookingApi
{
    public function __construct(
        private SlotEngine $engine,
        private DurationResolver $resolver,
        private CreateBooking $createBooking,
        private CreateClient $createClient,
    ) {}

    /**
     * @param  array{service: string|int, stylist?: string|int|null, date?: string|null, date_to?: string|null}  $input
     * @return array<string, mixed>
     */
    public function availability(Salon $salon, array $input): array
    {
        $service = $this->resolveService($salon, $input['service']);
        $stylists = $this->resolveStylists($salon, $service, $input['stylist'] ?? null);
        $days = $this->resolveDays($salon, $input['date'] ?? null, $input['date_to'] ?? null);

        $slots = $this->slotsFor($salon, $service, $stylists, $days, (int) config('booking_api.max_slots_per_day'));

        $duration = count($stylists) === 1
            ? $this->resolver->resolve($salon, $service, $stylists[0]->id)->clientFacingMinutes()
            : $service->duration_min;

        return [
            'success' => true,
            'service' => ['id' => $service->id, 'name' => $service->name, 'duration_minutes' => $duration],
            'timezone' => $salon->timezone,
            'slots' => $slots,
            'message' => $slots === []
                ? __(':service has no openings in that period. Try another date.', ['service' => $service->name])
                : __('There are :count openings for :service. The earliest is :first with :stylist.', [
                    'count' => count($slots),
                    'service' => $service->name,
                    'first' => $slots[0]['spoken'],
                    'stylist' => $slots[0]['stylist'],
                ]),
        ];
    }

    /**
     * Create a booking through the shared engine. The voice AI is the
     * default caller; the public web widget books through the exact same
     * path with its own source/actor tags (same slot re-validation under
     * lock, same client upsert, same GHL push).
     *
     * @param  array{service: string|int, stylist?: string|int|null, datetime?: string|null, date?: string|null, time?: string|null, client: array{name: string, phone?: string|null, email?: string|null}, notes?: string|null, ghl_contact_id?: string|null}  $input
     * @return array<string, mixed>
     */
    public function create(
        Salon $salon,
        array $input,
        BookingSource $source = BookingSource::VoiceAi,
        BookedByType $bookedBy = BookedByType::VoiceAi,
    ): array {
        $service = $this->resolveService($salon, $input['service']);
        $stylists = $this->resolveStylists($salon, $service, $input['stylist'] ?? null);

        $start = $this->resolveStart($salon, $input);

        $client = $this->resolveClient($salon, $input['client'], $input['ghl_contact_id'] ?? null);

        // Idempotent retry: the AI re-sending the same booking (same client,
        // service, exact start, not cancelled) gets the SAME confirmation
        // back instead of a duplicate.
        $existing = $salon->bookings()
            ->where('client_id', $client->id)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->whereHas('items', fn ($q) => $q
                ->where('service_id', $service->id)
                ->where('starts_at', $start->utc()))
            ->with('items.stylist')
            ->first();

        if ($existing !== null) {
            return $this->confirmation($salon, $existing->id, $service, $existing->items->first()->stylist, $start, idempotent: true);
        }

        // Requested stylist, or the first qualified stylist free at that time.
        $stylist = $this->pickStylistFor($salon, $service, $stylists, $start);

        if ($stylist === null) {
            return $this->slotTaken($salon, $service, $stylists, $start);
        }

        try {
            $booking = $this->createBooking->handle(null, $salon, [
                'client' => ['id' => $client->id],
                'items' => [['service_id' => $service->id, 'stylist_id' => $stylist->id]],
                'start' => $start->format('Y-m-d H:i'),
                'notes' => isset($input['notes']) ? mb_substr((string) $input['notes'], 0, 1000) : null,
            ], $source, $bookedBy);
        } catch (ValidationException $e) {
            // The locked re-validation lost the race (or policy refused it).
            Log::info('Booking API create refused by engine', [
                'category' => 'engine',
                'salon_id' => $salon->id,
                'service_id' => $service->id,
                'errors' => array_keys($e->errors()),
            ]);

            return $this->slotTaken($salon, $service, $stylists, $start);
        }

        Log::info('Booking API created booking', [
            'category' => 'engine',
            'salon_id' => $salon->id,
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'stylist_id' => $stylist->id,
        ]);

        return $this->confirmation($salon, $booking->id, $service, $stylist, $start);
    }

    // -- Multi-service visits (the web widget) --------------------------------

    /**
     * Open slots where an ENTIRE multi-service visit fits: the services run
     * back-to-back with ONE stylist qualified for all of them, so a slot is
     * offered only when the summed blocked minutes (per-stylist durations +
     * buffers) fit one continuous free stretch — the exact layout
     * CreateBooking re-validates under lock. Never under-books.
     *
     * @param  array{services: list<int|string>, stylist?: string|int|null, stylists?: list<int|string|null>|null, date?: string|null}  $input
     * @return array<string, mixed>
     */
    public function visitAvailability(Salon $salon, array $input): array
    {
        $services = $this->resolveVisitServices($salon, $input['services']);
        $candidates = $this->resolvePerServiceStylists($salon, $services, $input['stylist'] ?? null, $input['stylists'] ?? null);
        $days = $this->resolveDays($salon, $input['date'] ?? null, null);

        $slots = $this->visitSlotsFor($salon, $services, $candidates, $days, (int) config('booking_api.max_slots_per_day'));

        $names = collect($services)->pluck('name')->join(', ');

        return [
            'success' => true,
            'services' => collect($services)->map(fn (Service $s): array => ['id' => (int) $s->id, 'name' => $s->name])->all(),
            'timezone' => $salon->timezone,
            'slots' => $slots,
            'message' => $slots === []
                ? __(':service has no openings in that period. Try another date.', ['service' => $names])
                : __('There are :count openings for :service.', ['count' => count($slots), 'service' => $names]),
        ];
    }

    /**
     * Book a whole visit (one or more services, one stylist, back-to-back)
     * through the SAME engine path as everything else: CreateBooking lays the
     * items sequentially, re-validates each under lock, links them as one
     * visit group, and queues the GHL push.
     *
     * @param  array{services: list<int|string>, stylist?: string|int|null, stylists?: list<int|string|null>|null, date?: string|null, time?: string|null, datetime?: string|null, client: array{name: string, phone?: string|null, email?: string|null}, notes?: string|null}  $input
     * @return array<string, mixed>
     */
    public function createVisit(
        Salon $salon,
        array $input,
        BookingSource $source = BookingSource::WebWidget,
        BookedByType $bookedBy = BookedByType::WebWidget,
    ): array {
        $services = $this->resolveVisitServices($salon, $input['services']);
        $candidates = $this->resolvePerServiceStylists($salon, $services, $input['stylist'] ?? null, $input['stylists'] ?? null);
        $start = $this->resolveStart($salon, $input);
        $client = $this->resolveClient($salon, $input['client'], null);

        // Idempotent retry: the same client re-submitting the same visit
        // (same first service at the same start, not cancelled) gets the
        // same confirmation back instead of a duplicate visit.
        $existing = $salon->bookings()
            ->where('client_id', $client->id)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->whereHas('items', fn ($q) => $q
                ->where('service_id', $services[0]->id)
                ->where('starts_at', $start->utc()))
            ->first();

        if ($existing !== null) {
            return $this->visitConfirmation($salon, $existing->id, $this->persistedChain($salon, $existing), $start, idempotent: true);
        }

        $chain = $this->pickVisitChain($salon, $services, $candidates, $start);

        if ($chain === null) {
            return $this->visitSlotTaken($salon, $services, $candidates, $start);
        }

        try {
            $booking = $this->createBooking->handle(null, $salon, [
                'client' => ['id' => $client->id],
                // Per-leg stylists; CreateBooking lays the items sequentially
                // with the SAME per-stylist blocked-minutes cursor the chain
                // was validated with, and re-validates each under lock.
                'items' => array_map(
                    fn (Service $s, array $leg): array => ['service_id' => (int) $s->id, 'stylist_id' => (int) $leg['stylist']->id],
                    $services,
                    $chain,
                ),
                'start' => $start->format('Y-m-d H:i'),
                'notes' => isset($input['notes']) ? mb_substr((string) $input['notes'], 0, 1000) : null,
            ], $source, $bookedBy);
        } catch (ValidationException $e) {
            Log::info('Booking API visit refused by engine', [
                'category' => 'engine',
                'salon_id' => $salon->id,
                'service_ids' => collect($services)->pluck('id')->all(),
                'errors' => array_keys($e->errors()),
            ]);

            return $this->visitSlotTaken($salon, $services, $candidates, $start);
        }

        Log::info('Booking API created visit', [
            'category' => 'engine',
            'salon_id' => $salon->id,
            'booking_id' => $booking->id,
            'service_ids' => collect($services)->pluck('id')->all(),
            'stylist_ids' => collect($chain)->map(fn (array $leg): int => (int) $leg['stylist']->id)->unique()->values()->all(),
        ]);

        return $this->visitConfirmation($salon, $booking->id, $this->confirmationLegs($salon, $services, $chain), $start);
    }

    /**
     * Book a visit of INDEPENDENTLY-TIMED appointments — the widget's
     * per-service loop. Each item names its service, its own stylist (or
     * "any") and its OWN start; gaps between items are fine, nothing is
     * forced back-to-back. One booking per service linked by the visit
     * group, through the same CreateBooking path (per-item explicit starts,
     * locked re-validation, internal same-stylist overlap rejection, GHL
     * push per booking).
     *
     * "any" resolves per item to a qualified stylist who is free at that
     * item's start AND clear of the visit's own other items. A refusal names
     * the exact items that need a new time (`conflicts`), both before
     * booking and after losing a race under lock.
     *
     * @param  array{items: list<array{service: int|string, stylist?: string|int|null, date?: string|null, time?: string|null}>, client: array{name: string, phone?: string|null, email?: string|null}, notes?: string|null}  $input
     * @return array<string, mixed>
     */
    public function createItinerary(
        Salon $salon,
        array $input,
        BookingSource $source = BookingSource::WebWidget,
        BookedByType $bookedBy = BookedByType::WebWidget,
    ): array {
        if ($input['items'] === []) {
            throw ApiError::validation(__('Choose at least one service.'), 'no_services');
        }

        // Parse every item (service, qualified stylists, start) BEFORE any
        // availability checks, so an idempotent replay of an already-booked
        // visit is recognised instead of reading its own slots as taken.
        $parsed = [];
        foreach ($input['items'] as $index => $item) {
            $service = $this->resolveService($salon, $item['service']);
            $parsed[] = [
                'index' => $index,
                'service' => $service,
                'qualified' => $this->resolveStylists($salon, $service, $item['stylist'] ?? null),
                'start' => $this->resolveStart($salon, $item),
            ];
        }

        $client = $this->resolveClient($salon, $input['client'], null);

        // Idempotent retry: same client, same first service at the same start.
        $existing = $salon->bookings()
            ->where('client_id', $client->id)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->whereHas('items', fn ($q) => $q
                ->where('service_id', $parsed[0]['service']->id)
                ->where('starts_at', $parsed[0]['start']->utc()))
            ->first();

        if ($existing !== null) {
            return $this->visitConfirmation($salon, $existing->id, $this->persistedChain($salon, $existing), $parsed[0]['start'], idempotent: true);
        }

        // Now pick each item's stylist: free at that start and clear of the
        // visit's OWN other items.
        $legs = [];
        $used = [];
        $conflicts = [];

        foreach ($parsed as $entry) {
            $index = $entry['index'];
            $service = $entry['service'];
            $qualified = $entry['qualified'];
            $start = $entry['start'];

            $chosen = null;
            $blocked = 0;

            if ($this->engine->offerable($salon, $start)) {
                foreach ($qualified as $stylist) {
                    $minutes = $this->resolver->resolve($salon, $service, $stylist->id)->blockedMinutes();
                    $end = $start->addMinutes($minutes);

                    if ($this->overlapsAny($used[$stylist->id] ?? [], $start, $end)) {
                        continue;
                    }
                    if (! $this->engine->isAvailable($salon, $stylist->id, $start, $minutes)) {
                        continue;
                    }

                    $chosen = $stylist;
                    $blocked = $minutes;
                    break;
                }
            }

            if ($chosen === null) {
                $conflicts[] = [
                    'index' => $index,
                    'service' => $service->name,
                    'message' => __(':service needs a new time — that slot is no longer available.', ['service' => $service->name]),
                ];

                continue;
            }

            $used[$chosen->id][] = [$start, $start->addMinutes($blocked)];
            $legs[] = ['service' => $service, 'stylist' => $chosen, 'start' => $start];
        }

        if ($conflicts !== [] || $legs === []) {
            return $this->itineraryConflict($conflicts !== [] ? $conflicts : [[
                'index' => 0,
                'service' => $parsed[0]['service']->name,
                'message' => __('The visit could not be booked as scheduled. Adjust a time and try again.'),
            ]]);
        }

        try {
            $booking = $this->createBooking->handle(null, $salon, [
                'client' => ['id' => $client->id],
                // Explicit per-item starts: CreateBooking re-validates each
                // under lock and rejects same-stylist internal overlaps.
                'items' => array_map(fn (array $leg): array => [
                    'service_id' => (int) $leg['service']->id,
                    'stylist_id' => (int) $leg['stylist']->id,
                    'start' => $leg['start']->format('Y-m-d H:i'),
                ], $legs),
                'start' => $legs[0]['start']->format('Y-m-d H:i'),
                'notes' => isset($input['notes']) ? mb_substr((string) $input['notes'], 0, 1000) : null,
            ], $source, $bookedBy);
        } catch (ValidationException $e) {
            // Lost a race under lock — re-check each leg to NAME the ones
            // that now need a new time.
            Log::info('Booking API itinerary refused by engine', [
                'category' => 'engine',
                'salon_id' => $salon->id,
                'service_ids' => collect($legs)->map(fn (array $leg): int => (int) $leg['service']->id)->all(),
                'errors' => array_keys($e->errors()),
            ]);

            $conflicts = [];
            foreach ($legs as $index => $leg) {
                $minutes = $this->resolver->resolve($salon, $leg['service'], $leg['stylist']->id)->blockedMinutes();

                if (! $this->engine->isAvailable($salon, $leg['stylist']->id, $leg['start'], $minutes)) {
                    $conflicts[] = [
                        'index' => $index,
                        'service' => $leg['service']->name,
                        'message' => __(':service needs a new time — that slot is no longer available.', ['service' => $leg['service']->name]),
                    ];
                }
            }

            return $this->itineraryConflict($conflicts !== [] ? $conflicts : [[
                'index' => 0,
                'service' => $legs[0]['service']->name,
                'message' => __('The visit could not be booked as scheduled. Adjust a time and try again.'),
            ]]);
        }

        Log::info('Booking API created itinerary', [
            'category' => 'engine',
            'salon_id' => $salon->id,
            'booking_id' => $booking->id,
            'service_ids' => collect($legs)->map(fn (array $leg): int => (int) $leg['service']->id)->all(),
            'stylist_ids' => collect($legs)->map(fn (array $leg): int => (int) $leg['stylist']->id)->unique()->values()->all(),
        ]);

        $legLabels = array_map(fn (array $leg): array => [
            'service' => $leg['service']->name,
            'stylist' => $leg['stylist']->name,
            'time' => $leg['start']->setTimezone($salon->timezone)->format('D, M j · g:i A'),
        ], $legs);

        return $this->visitConfirmation($salon, $booking->id, $legLabels, $legs[0]['start']);
    }

    /**
     * @param  non-empty-list<array{index: int, service: string, message: string}>  $conflicts
     * @return array<string, mixed>
     */
    private function itineraryConflict(array $conflicts): array
    {
        return [
            'success' => false,
            'error' => 'slot_unavailable',
            'conflicts' => $conflicts,
            'message' => __('Some times are no longer available: :services. Pick new times for them.', [
                'services' => collect($conflicts)->pluck('service')->unique()->join(', '),
            ]),
        ];
    }

    /** @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals */
    private function overlapsAny(array $intervals, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($intervals as [$usedStart, $usedEnd]) {
            if ($start->lt($usedEnd) && $usedStart->lt($end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The chain to book at a SPECIFIC start: a single shared stylist when one
     * is free for the whole block (preference order), else a composed
     * multi-stylist arrangement — the same order the slot search offers.
     *
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<non-empty-list<User>>  $candidates
     * @return non-empty-list<array{stylist: User, start: CarbonImmutable, duration: ResolvedDuration}>|null
     */
    private function pickVisitChain(Salon $salon, array $services, array $candidates, CarbonImmutable $start): ?array
    {
        foreach ($this->sharedCandidates($candidates) as $stylist) {
            $blocked = $this->visitBlockedMinutes($salon, $services, $stylist);

            if ($this->engine->isAvailable($salon, $stylist->id, $start, $blocked)) {
                return $this->singleChain($salon, $services, $stylist, $start->setTimezone($salon->timezone));
            }
        }

        $day = $start->setTimezone($salon->timezone)->startOfDay();

        $contexts = [];
        $durations = [];
        foreach ($candidates as $i => $stylists) {
            foreach ($stylists as $stylist) {
                $contexts[$stylist->id] ??= $this->engine->dayContext($salon, $stylist->id, $day);
                $durations[$i][$stylist->id] = $this->resolver->resolve($salon, $services[$i], $stylist->id);
            }
        }

        $chain = $this->chainAssign($salon, $services, $candidates, $contexts, $durations, $start->setTimezone($salon->timezone), 0, [], []);

        return $chain === [] ? null : $chain;
    }

    /**
     * Confirmation legs from a live chain: service + stylist names with each
     * leg's local start.
     *
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<array{stylist: User, start: CarbonImmutable, duration: ResolvedDuration}>  $chain
     * @return non-empty-list<array{service: string, stylist: string, time: string}>
     */
    private function confirmationLegs(Salon $salon, array $services, array $chain): array
    {
        return array_map(
            fn (Service $service, array $leg): array => [
                'service' => $service->name,
                'stylist' => $leg['stylist']->name,
                'time' => $leg['start']->setTimezone($salon->timezone)->format('g:i A'),
            ],
            $services,
            $chain,
        );
    }

    /**
     * Confirmation legs for an already-persisted visit (idempotent retries):
     * every booking in the visit group, earliest first.
     *
     * @return non-empty-list<array{service: string, stylist: string, time: string}>
     */
    private function persistedChain(Salon $salon, Booking $booking): array
    {
        $bookings = $booking->visit_group_id !== null
            ? $salon->bookings()->where('visit_group_id', $booking->visit_group_id)->with('items.service', 'items.stylist')->get()
            : collect([$booking->load('items.service', 'items.stylist')]);

        $legs = $bookings
            ->flatMap(fn (Booking $b) => $b->items)
            ->sortBy('starts_at')
            ->map(fn ($item): array => [
                'service' => $item->service->name,
                'stylist' => $item->stylist->name,
                'time' => $item->starts_at->setTimezone($salon->timezone)->format('g:i A'),
            ])
            ->all();

        return $legs === [] ? [['service' => '', 'stylist' => '', 'time' => '']] : array_values($legs);
    }

    /**
     * The dates in [from, to] with at least ONE slot where the whole visit
     * fits — the widget's month calendar. One engine pass per stylist per
     * day, short-circuiting the remaining stylists as soon as a day has any
     * opening; per-stylist blocked minutes are resolved once up front.
     *
     * @param  array{services: list<int|string>, stylist?: string|int|null, stylists?: list<int|string|null>|null}  $input
     * @return array{dates: list<string>, timezone: string}
     */
    public function visitAvailableDates(Salon $salon, array $input, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $services = $this->resolveVisitServices($salon, $input['services']);
        $candidates = $this->resolvePerServiceStylists($salon, $services, $input['stylist'] ?? null, $input['stylists'] ?? null);
        $shared = $this->sharedCandidates($candidates);

        $blockedByStylist = [];
        foreach ($shared as $stylist) {
            $blockedByStylist[$stylist->id] = $this->visitBlockedMinutes($salon, $services, $stylist);
        }

        $dates = [];
        // Hard cap: a calendar month view plus its leading/trailing edges.
        for ($day = $from, $i = 0; $day->lte($to) && $i < 42; $day = $day->addDay(), $i++) {
            $found = false;

            foreach ($shared as $stylist) {
                if ($this->engine->slotsFor($salon, $stylist->id, $blockedByStylist[$stylist->id], $day) !== []) {
                    $found = true;
                    break;
                }
            }

            // Same fallback order as the slot search: a day counts when a
            // multi-stylist arrangement fits even though no one stylist can
            // host the whole visit (stop at the first arrangement found).
            if (! $found) {
                $found = $this->arrangementSlotsFor($salon, $services, $candidates, $day, limit: 1) !== [];
            }

            if ($found) {
                $dates[] = $day->format('Y-m-d');
            }
        }

        return ['dates' => $dates, 'timezone' => $salon->timezone];
    }

    /**
     * Resolve 1..N distinct services for a visit (ids or names, deduped).
     *
     * @param  list<int|string>  $refs
     * @return non-empty-list<Service>
     */
    private function resolveVisitServices(Salon $salon, array $refs): array
    {
        if ($refs === []) {
            throw ApiError::validation(__('Choose at least one service.'), 'no_services');
        }

        $services = [];
        foreach ($refs as $ref) {
            $service = $this->resolveService($salon, $ref);
            $services[$service->id] = $service;
        }

        return array_values($services);
    }

    /**
     * The stylist CANDIDATES per service, parallel to $services. Three shapes:
     *
     * - Auto ("any"): every qualified stylist per service — the slot search
     *   prefers one stylist for the whole visit and composes a back-to-back
     *   multi-stylist arrangement only when no single stylist can host it.
     * - Auto with a preferred stylist: the preferred one is pinned wherever
     *   they qualify; services they don't perform keep all qualified
     *   candidates (graceful fallback instead of a refusal).
     * - Manual ($assigned, parallel to $services, id/name or "any" per line):
     *   an explicit per-service pick is STRICT — it must be qualified for
     *   that exact service, or the request is refused with the options.
     *
     * @param  non-empty-list<Service>  $services
     * @param  list<int|string|null>|null  $assigned
     * @return non-empty-list<non-empty-list<User>>
     */
    private function resolvePerServiceStylists(Salon $salon, array $services, string|int|null $preferred, ?array $assigned = null): array
    {
        $isAny = function (string|int|null $ref): bool {
            $term = mb_strtolower(trim((string) ($ref ?? '')));

            return $term === '' || $term === 'any' || $term === 'anyone' || $term === 'any available';
        };

        $candidates = [];

        foreach ($services as $i => $service) {
            /** @var non-empty-list<User> $qualified — resolveStylists throws no_stylists when empty */
            $qualified = $this->resolveStylists($salon, $service, null);
            $ref = $assigned !== null ? ($assigned[$i] ?? null) : $preferred;

            if ($isAny($ref)) {
                $candidates[] = $qualified;

                continue;
            }

            $match = null;
            $term = mb_strtolower(trim((string) $ref));
            foreach ($qualified as $stylist) {
                if ((is_numeric($ref) && (int) $stylist->id === (int) $ref)
                    || str_contains(mb_strtolower($stylist->name), $term)) {
                    $match = $stylist;
                    break;
                }
            }

            if ($match !== null) {
                // Pinned first: the single-stylist pass and the arrangement
                // search both try them before anyone else.
                $candidates[] = $assigned !== null
                    ? [$match]
                    : array_merge([$match], array_values(array_filter($qualified, fn (User $u): bool => $u->id !== $match->id)));

                continue;
            }

            if ($assigned !== null) {
                throw ApiError::validation(
                    __(':term does not perform :service. It can be taken by: :options.', [
                        'term' => (string) $ref,
                        'service' => $service->name,
                        'options' => collect($qualified)->pluck('name')->join(', '),
                    ]),
                    'unknown_stylist',
                    ['stylists' => collect($qualified)->pluck('name')->values()->all()],
                );
            }

            // Auto + preferred stylist who doesn't perform this service:
            // fall back to everyone qualified rather than refusing the visit.
            $candidates[] = $qualified;
        }

        // A preferred stylist that matched NO service at all is a typo/unknown
        // name — refuse with the real options instead of silently ignoring it.
        if ($assigned === null && ! $isAny($preferred)) {
            $term = mb_strtolower(trim((string) $preferred));
            $union = collect($candidates)->flatten()->unique('id');
            $known = $union->contains(fn (User $u): bool => (is_numeric($preferred) && (int) $u->id === (int) $preferred)
                || str_contains(mb_strtolower($u->name), $term));

            if (! $known) {
                throw ApiError::validation(
                    __(':term is not one of our stylists. The team: :options.', [
                        'term' => (string) $preferred,
                        'options' => $union->pluck('name')->join(', '),
                    ]),
                    'unknown_stylist',
                    ['stylists' => $union->pluck('name')->values()->all()],
                );
            }
        }

        return $candidates;
    }

    /**
     * Stylists present in EVERY service's candidate list (ordered by the
     * first service's preference order) — the ones who can host the whole
     * visit alone.
     *
     * @param  non-empty-list<non-empty-list<User>>  $candidates
     * @return list<User>
     */
    private function sharedCandidates(array $candidates): array
    {
        return array_values(array_filter(
            $candidates[0],
            function (User $stylist) use ($candidates): bool {
                foreach ($candidates as $qualified) {
                    if (! collect($qualified)->contains(fn (User $u): bool => $u->id === $stylist->id)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Summed blocked minutes (per-stylist durations + buffers) for the visit.
     *
     * @param  non-empty-list<Service>  $services
     */
    private function visitBlockedMinutes(Salon $salon, array $services, User $stylist): int
    {
        return (int) collect($services)
            ->sum(fn (Service $s): int => $this->resolver->resolve($salon, $s, $stylist->id)->blockedMinutes());
    }

    /**
     * Slots where the WHOLE visit fits, capped per day. Per day: first the
     * single-stylist pass (every shared candidate, deduped per start) — one
     * stylist for the whole visit is always preferred; only when NO single
     * stylist can host the visit that day does it compose back-to-back
     * multi-stylist arrangements from the per-service candidates.
     *
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<non-empty-list<User>>  $candidates  parallel to $services
     * @param  list<CarbonImmutable>  $days
     * @return list<array<string, mixed>>
     */
    private function visitSlotsFor(Salon $salon, array $services, array $candidates, array $days, int $perDayCap): array
    {
        $shared = $this->sharedCandidates($candidates);
        $out = [];

        foreach ($days as $day) {
            $daySlots = [];

            foreach ($shared as $stylist) {
                $blocked = $this->visitBlockedMinutes($salon, $services, $stylist);

                foreach ($this->engine->slotsFor($salon, $stylist->id, $blocked, $day) as $slot) {
                    $key = $slot->getTimestamp();
                    if (! isset($daySlots[$key])) {
                        $daySlots[$key] = $this->formatVisitSlot($salon, $services, $this->singleChain($salon, $services, $stylist, $slot), $slot);
                    }
                }
            }

            if ($daySlots === []) {
                $daySlots = $this->arrangementSlotsFor($salon, $services, $candidates, $day);
            }

            ksort($daySlots);
            $out = array_merge($out, array_slice(array_values($daySlots), 0, $perDayCap));
        }

        return $out;
    }

    /**
     * Multi-stylist arrangements for one day: every candidate start where the
     * services chain back-to-back, each leg with a qualified stylist who is
     * free for exactly that leg. Day shapes are fetched ONCE per stylist
     * (SlotEngine::dayContext) and the chain search is pure in-memory
     * interval math, so a whole month sweep stays cheap.
     *
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<non-empty-list<User>>  $candidates
     * @return array<int, array<string, mixed>> keyed by start timestamp
     */
    private function arrangementSlotsFor(Salon $salon, array $services, array $candidates, CarbonImmutable $day, int $limit = PHP_INT_MAX): array
    {
        $contexts = [];
        $durations = [];
        foreach ($candidates as $i => $stylists) {
            foreach ($stylists as $stylist) {
                $contexts[$stylist->id] ??= $this->engine->dayContext($salon, $stylist->id, $day);
                $durations[$i][$stylist->id] = $this->resolver->resolve($salon, $services[$i], $stylist->id);
            }
        }

        // Candidate visit starts: the grid over the FIRST service's
        // candidates' work windows, anchored at each window start (the same
        // anchoring slotsFor uses).
        $step = $this->engine->granularity();
        $starts = [];
        foreach ($candidates[0] as $stylist) {
            foreach ($contexts[$stylist->id]['windows'] as [$windowStart, $windowEnd]) {
                for ($t = $windowStart; $t->lt($windowEnd); $t = $t->addMinutes($step)) {
                    $starts[$t->getTimestamp()] = $t;
                }
            }
        }
        ksort($starts);

        $slots = [];
        foreach ($starts as $ts => $start) {
            if (! $this->engine->offerable($salon, $start)) {
                continue;
            }

            $chain = $this->chainAssign($salon, $services, $candidates, $contexts, $durations, $start, 0, [], []);
            if ($chain !== null && $chain !== []) {
                $slots[$ts] = $this->formatVisitSlot($salon, $services, $chain, $start);
                if (count($slots) >= $limit) {
                    break;
                }
            }
        }

        return $slots;
    }

    /**
     * Depth-first assignment of services (in order) to stylists from $cursor:
     * each leg needs its stylist structurally free for [leg start, +blocked)
     * AND clear of the legs already assigned to them in THIS chain. Prefers
     * the previous leg's stylist (fewest handoffs), then candidate order.
     * Returns the first complete chain, or null.
     *
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<non-empty-list<User>>  $candidates
     * @param  array<int, array{windows: list<array{0: CarbonImmutable, 1: CarbonImmutable}>, free: list<array{0: CarbonImmutable, 1: CarbonImmutable}>}>  $contexts
     * @param  array<int, array<int, ResolvedDuration>>  $durations
     * @param  list<array{stylist: User, start: CarbonImmutable, duration: ResolvedDuration}>  $chain
     * @param  array<int, list<array{0: CarbonImmutable, 1: CarbonImmutable}>>  $used  in-chain blocked intervals per stylist
     * @return list<array{stylist: User, start: CarbonImmutable, duration: ResolvedDuration}>|null
     */
    private function chainAssign(Salon $salon, array $services, array $candidates, array $contexts, array $durations, CarbonImmutable $cursor, int $index, array $chain, array $used): ?array
    {
        if ($index === count($services)) {
            return $chain;
        }

        $previousId = $chain !== [] ? $chain[count($chain) - 1]['stylist']->id : null;
        $ordered = collect($candidates[$index])
            ->sortBy(fn (User $u): int => $u->id === $previousId ? 0 : 1)
            ->values();

        foreach ($ordered as $stylist) {
            $duration = $durations[$index][$stylist->id];
            $end = $cursor->addMinutes($duration->blockedMinutes());

            if (! $this->fitsFreeIntervals($contexts[$stylist->id]['free'], $cursor, $end)) {
                continue;
            }

            $clashes = false;
            foreach ($used[$stylist->id] ?? [] as [$usedStart, $usedEnd]) {
                if ($cursor->lt($usedEnd) && $usedStart->lt($end)) {
                    $clashes = true;
                    break;
                }
            }
            if ($clashes) {
                continue;
            }

            $nextUsed = $used;
            $nextUsed[$stylist->id] = array_merge($used[$stylist->id] ?? [], [[$cursor, $end]]);

            $result = $this->chainAssign(
                $salon, $services, $candidates, $contexts, $durations,
                $end, $index + 1,
                array_merge($chain, [['stylist' => $stylist, 'start' => $cursor, 'duration' => $duration]]),
                $nextUsed,
            );

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $free */
    private function fitsFreeIntervals(array $free, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($free as [$freeStart, $freeEnd]) {
            if ($start->gte($freeStart) && $end->lte($freeEnd)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The chain a single stylist hosting the whole visit produces (legs laid
     * sequentially — exactly CreateBooking's cursor walk).
     *
     * @param  non-empty-list<Service>  $services
     * @return non-empty-list<array{stylist: User, start: CarbonImmutable, duration: ResolvedDuration}>
     */
    private function singleChain(Salon $salon, array $services, User $stylist, CarbonImmutable $start): array
    {
        $chain = [];
        $cursor = $start;

        foreach ($services as $service) {
            $duration = $this->resolver->resolve($salon, $service, $stylist->id);
            $chain[] = ['stylist' => $stylist, 'start' => $cursor, 'duration' => $duration];
            $cursor = $cursor->addMinutes($duration->blockedMinutes());
        }

        return $chain;
    }

    /**
     * The public slot payload for a visit chain: the usual start fields plus
     * the per-service arrangement (who takes what, when) so callers can both
     * SHOW the staffing and submit it back verbatim.
     *
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<array{stylist: User, start: CarbonImmutable, duration: ResolvedDuration}>  $chain
     * @return array<string, mixed>
     */
    private function formatVisitSlot(Salon $salon, array $services, array $chain, CarbonImmutable $start): array
    {
        $local = $start->setTimezone($salon->timezone);

        $arrangement = [];
        $minutes = 0;
        foreach ($services as $i => $service) {
            $leg = $chain[$i];
            $legLocal = $leg['start']->setTimezone($salon->timezone);
            $arrangement[] = [
                'service_id' => (int) $service->id,
                'service' => $service->name,
                'stylist_id' => (int) $leg['stylist']->id,
                'stylist' => $leg['stylist']->name,
                'starts_at' => $legLocal->toIso8601String(),
                'time' => $legLocal->format('g:i A'),
            ];
            $minutes += $leg['duration']->clientFacingMinutes();
        }

        $names = array_values(array_unique(array_column($arrangement, 'stylist')));

        return [
            'starts_at' => $local->toIso8601String(),
            'spoken' => $local->format('l, F j \a\t g:i A'),
            'date' => $local->format('Y-m-d'),
            'time' => $local->format('g:i A'),
            'stylist_id' => $arrangement[0]['stylist_id'],
            'stylist' => implode(' + ', $names),
            'duration_minutes' => $minutes,
            'multi_stylist' => count($names) > 1,
            'stylists' => $arrangement,
        ];
    }

    /**
     * @param  non-empty-list<array{service: string, stylist: string, time: string}>  $legs
     * @return array<string, mixed>
     */
    private function visitConfirmation(Salon $salon, int $bookingId, array $legs, CarbonImmutable $start, bool $idempotent = false): array
    {
        $spoken = $start->setTimezone($salon->timezone)->format('l, F j \a\t g:i A');
        $names = collect($legs)->pluck('service')->join(', ');
        $stylistNames = collect($legs)->pluck('stylist')->unique()->values();

        // One stylist reads as before; a composed visit spells out each leg
        // ("Haircut with Maya (9:00 AM), then Full colour with Sarah (10:00 AM)").
        $staffing = $stylistNames->count() <= 1
            ? __(':service with :stylist', ['service' => $names, 'stylist' => $stylistNames->first() ?? ''])
            : collect($legs)
                ->map(fn (array $leg): string => __(':service with :stylist (:time)', $leg))
                ->join(', '.__('then').' ');

        return [
            'success' => true,
            'idempotent' => $idempotent,
            'booking_id' => $bookingId,
            'confirmation' => [
                'salon' => $salon->name,
                'services' => collect($legs)->pluck('service')->values()->all(),
                'service' => $names,
                'stylist' => $stylistNames->join(' + '),
                'arrangement' => $legs,
                'multi_stylist' => $stylistNames->count() > 1,
                'starts_at' => $start->setTimezone($salon->timezone)->toIso8601String(),
                'spoken_time' => $spoken,
            ],
            'message' => __("You're booked for :staffing on :time at :salon.", [
                'staffing' => $staffing,
                'time' => $spoken,
                'salon' => $salon->name,
            ]),
        ];
    }

    /**
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<non-empty-list<User>>  $candidates
     * @return array<string, mixed>
     */
    private function visitSlotTaken(Salon $salon, array $services, array $candidates, CarbonImmutable $start): array
    {
        $days = [];
        $day = $start->setTimezone($salon->timezone)->startOfDay();
        for ($i = 0; $i < 3; $i++) {
            $days[] = $day->addDays($i);
        }

        $alternatives = collect($this->visitSlotsFor($salon, $services, $candidates, $days, PHP_INT_MAX))
            ->filter(fn (array $slot): bool => CarbonImmutable::parse($slot['starts_at'])->gte($start->subMinutes(1)))
            ->take((int) config('booking_api.alternatives'))
            ->values()
            ->all();

        $names = collect($services)->pluck('name')->join(', ');

        return [
            'success' => false,
            'error' => 'slot_unavailable',
            'alternatives' => $alternatives,
            'message' => $alternatives === []
                ? __('That time is no longer available, and there are no nearby openings for :service. Try another day.', ['service' => $names])
                : __('That time was just taken. The next openings for :service are: :options.', [
                    'service' => $names,
                    'options' => collect($alternatives)->map(fn (array $s): string => "{$s['spoken']} with {$s['stylist']}")->join('; '),
                ]),
        ];
    }

    // -- Fuzzy resolution ---------------------------------------------------

    /** Accept an id or the (case-insensitive, partial) name the AI heard. */
    private function resolveService(Salon $salon, string|int $ref): Service
    {
        $active = $salon->services()->where('active', true)->orderBy('name')->get();

        if (is_numeric($ref)) {
            $service = $active->firstWhere('id', (int) $ref);
            if ($service !== null) {
                return $service;
            }
        }

        $term = mb_strtolower(trim((string) $ref));
        if ($term !== '') {
            $exact = $active->filter(fn (Service $s): bool => mb_strtolower($s->name) === $term);
            if ($exact->count() === 1) {
                return $exact->first();
            }

            $partial = $active->filter(fn (Service $s): bool => str_contains(mb_strtolower($s->name), $term));
            if ($partial->count() === 1) {
                return $partial->first();
            }

            if ($partial->count() > 1) {
                throw ApiError::validation(
                    __('A few services match ":term" — which one: :options?', ['term' => (string) $ref, 'options' => $partial->pluck('name')->join(', ')]),
                    'ambiguous_service',
                    ['services' => $partial->pluck('name')->values()->all()],
                );
            }
        }

        throw ApiError::validation(
            __('I could not find a service called ":term". The services offered are: :options.', ['term' => (string) $ref, 'options' => $active->pluck('name')->join(', ')]),
            'unknown_service',
            ['services' => $active->pluck('name')->values()->all()],
        );
    }

    /**
     * The stylists to consider: a named/id'd one (must perform the service),
     * or every active member qualified for it ("any"/omitted).
     *
     * @return list<User>
     */
    private function resolveStylists(Salon $salon, Service $service, string|int|null $ref): array
    {
        $activeMemberIds = $salon->stylistUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $qualified = $service->stylists()->orderBy('name')->get()
            ->filter(fn (User $u): bool => in_array((int) $u->id, $activeMemberIds, true))
            ->values();

        if ($qualified->isEmpty()) {
            throw ApiError::validation(__('No stylist currently offers :service.', ['service' => $service->name]), 'no_stylists');
        }

        $term = mb_strtolower(trim((string) ($ref ?? '')));
        if ($term === '' || $term === 'any' || $term === 'anyone' || $term === 'any available') {
            return array_values($qualified->all());
        }

        if (is_numeric($ref)) {
            $stylist = $qualified->firstWhere('id', (int) $ref);
            if ($stylist !== null) {
                return [$stylist];
            }
        }

        $matches = $qualified->filter(fn (User $u): bool => str_contains(mb_strtolower($u->name), $term));
        if ($matches->count() === 1) {
            return [$matches->first()];
        }

        throw ApiError::validation(
            $matches->isEmpty()
                ? __(':term does not offer :service. It is offered by: :options.', ['term' => (string) $ref, 'service' => $service->name, 'options' => $qualified->pluck('name')->join(', ')])
                : __('A few stylists match ":term" — which one: :options?', ['term' => (string) $ref, 'options' => $matches->pluck('name')->join(', ')]),
            $matches->isEmpty() ? 'unknown_stylist' : 'ambiguous_stylist',
            ['stylists' => $qualified->pluck('name')->values()->all()],
        );
    }

    /**
     * The salon-local days to scan: an explicit date (optionally through
     * date_to, capped at 7 days), or the configured next-N-days window.
     *
     * @return list<CarbonImmutable> day starts in the salon timezone
     */
    private function resolveDays(Salon $salon, ?string $date, ?string $dateTo): array
    {
        $tz = $salon->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();

        if ($date === null || trim($date) === '') {
            return array_values(collect(range(0, max(1, (int) config('booking_api.days_ahead')) - 1))
                ->map(fn (int $i): CarbonImmutable => $today->addDays($i))
                ->all());
        }

        try {
            $from = CarbonImmutable::parse($date, $tz)->startOfDay();
            $to = $dateTo !== null && trim($dateTo) !== '' ? CarbonImmutable::parse($dateTo, $tz)->startOfDay() : $from;
        } catch (\Throwable) {
            throw ApiError::validation(__('I could not understand that date. Please use a date like 2026-07-25.'), 'invalid_date');
        }

        if ($to->lt($from)) {
            $to = $from;
        }

        $days = [];
        for ($day = $from; $day->lte($to) && count($days) < 7; $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * The requested start instant, from either accepted shape. Primary
     * (GHL-friendly — its Custom Actions reject combined ISO datetimes
     * before sending): separate `date` + `time`, exactly as the
     * availability response returns per slot ("2026-07-27" + "11:00 AM"),
     * combined and interpreted in the SALON's timezone — so booking the
     * slot the AI offered lands on precisely that instant, DST included.
     * Time accepts the common spoken/AI shapes ("11:00 AM", "11:00am",
     * "11 AM", "11:00", "13:00"). Alternative: a combined ISO 8601
     * `datetime` with offset (curl/direct callers). If neither shape
     * yields a valid time, a clear speakable 422.
     *
     * @param  array{datetime?: string|null, date?: string|null, time?: string|null}  $input
     */
    private function resolveStart(Salon $salon, array $input): CarbonImmutable
    {
        $datetime = trim((string) ($input['datetime'] ?? ''));

        if ($datetime !== '') {
            try {
                return CarbonImmutable::parse($datetime)->setTimezone($salon->timezone);
            } catch (\Throwable) {
                // Fall through — a supplied date + time pair may still work.
            }
        }

        $date = trim((string) ($input['date'] ?? ''));
        $time = trim((string) ($input['time'] ?? ''));

        if ($date !== '' && $time !== '') {
            try {
                return CarbonImmutable::parse("{$date} {$time}", $salon->timezone);
            } catch (\Throwable) {
                // Falls through to the speakable error below.
            }
        }

        throw ApiError::validation(
            __('I need a valid date and time to book. Send date and time (like 2026-07-25 and 11:00 AM), or an exact datetime like 2026-07-25T11:30.'),
            'invalid_datetime',
        );
    }

    // -- Slot assembly -------------------------------------------------------

    /**
     * Genuinely bookable slots straight from the slot engine, merged across
     * the allowed stylists, deduped per start time, capped per day. Exact
     * per-stylist durations throughout — never a slot the app wouldn't book.
     *
     * @param  list<User>  $stylists
     * @param  list<CarbonImmutable>  $days
     * @return list<array{starts_at: string, spoken: string, date: string, time: string, stylist_id: int, stylist: string, duration_minutes: int}>
     */
    private function slotsFor(Salon $salon, Service $service, array $stylists, array $days, int $perDayCap): array
    {
        $out = [];

        foreach ($days as $day) {
            $daySlots = [];

            foreach ($stylists as $stylist) {
                $duration = $this->resolver->resolve($salon, $service, $stylist->id);

                foreach ($this->engine->slotsFor($salon, $stylist->id, $duration->blockedMinutes(), $day) as $slot) {
                    $key = $slot->getTimestamp();
                    // One offer per start time — first (alphabetical) stylist wins.
                    if (isset($daySlots[$key])) {
                        continue;
                    }

                    $local = $slot->setTimezone($salon->timezone);
                    $daySlots[$key] = [
                        'starts_at' => $local->toIso8601String(),
                        'spoken' => $local->format('l, F j \a\t g:i A'),
                        'date' => $local->format('Y-m-d'),
                        'time' => $local->format('g:i A'),
                        'stylist_id' => (int) $stylist->id,
                        'stylist' => $stylist->name,
                        'duration_minutes' => $duration->clientFacingMinutes(),
                    ];
                }
            }

            ksort($daySlots);
            $out = array_merge($out, array_slice(array_values($daySlots), 0, $perDayCap));
        }

        return $out;
    }

    /**
     * The stylist to book: the single requested one if free at $start, or the
     * first of the allowed stylists whose engine check passes. Null = nobody.
     *
     * @param  list<User>  $stylists
     */
    private function pickStylistFor(Salon $salon, Service $service, array $stylists, CarbonImmutable $start): ?User
    {
        foreach ($stylists as $stylist) {
            $blocked = $this->resolver->resolve($salon, $service, $stylist->id)->blockedMinutes();

            if ($this->engine->isAvailable($salon, $stylist->id, $start, $blocked)) {
                return $stylist;
            }
        }

        return null;
    }

    // -- Clients --------------------------------------------------------------

    /**
     * Upsert the caller: by GHL contact id first, then exact phone, then
     * email (case-insensitive), else create — and backfill a missing
     * ghl_contact_id link when GHL supplied one.
     *
     * @param  array{name: string, phone?: string|null, email?: string|null}  $data
     */
    private function resolveClient(Salon $salon, array $data, ?string $ghlContactId): Client
    {
        $phone = isset($data['phone']) && trim((string) $data['phone']) !== '' ? trim((string) $data['phone']) : null;
        $email = isset($data['email']) && trim((string) $data['email']) !== '' ? mb_strtolower(trim((string) $data['email'])) : null;

        $client = null;

        if ($ghlContactId !== null && $ghlContactId !== '') {
            $client = $salon->clients()->where('ghl_contact_id', $ghlContactId)->first();
        }

        $client ??= $phone !== null ? $salon->clients()->where('phone', $phone)->first() : null;
        $client ??= $email !== null ? $salon->clients()->whereRaw('lower(email) = ?', [$email])->first() : null;

        if ($client === null) {
            $client = $this->createClient->handle($salon, [
                'name' => trim($data['name']),
                'phone' => $phone,
                'email' => $email,
            ]);
        }

        if ($ghlContactId !== null && $ghlContactId !== '' && $client->ghl_contact_id === null) {
            $client->update(['ghl_contact_id' => $ghlContactId]);
        }

        return $client;
    }

    // -- Responses -------------------------------------------------------------

    /** @return array<string, mixed> */
    private function confirmation(Salon $salon, int $bookingId, Service $service, User $stylist, CarbonImmutable $start, bool $idempotent = false): array
    {
        $spoken = $start->setTimezone($salon->timezone)->format('l, F j \a\t g:i A');

        return [
            'success' => true,
            'idempotent' => $idempotent,
            'booking_id' => $bookingId,
            'confirmation' => [
                'salon' => $salon->name,
                'service' => $service->name,
                'stylist' => $stylist->name,
                'starts_at' => $start->setTimezone($salon->timezone)->toIso8601String(),
                'spoken_time' => $spoken,
            ],
            'message' => __("You're booked for :service with :stylist on :time at :salon.", [
                'service' => $service->name,
                'stylist' => $stylist->name,
                'time' => $spoken,
                'salon' => $salon->name,
            ]),
        ];
    }

    /**
     * The requested slot is gone — say so and offer the nearest genuinely
     * bookable alternatives (same day from that time, then following days).
     *
     * @param  list<User>  $stylists
     * @return array<string, mixed>
     */
    private function slotTaken(Salon $salon, Service $service, array $stylists, CarbonImmutable $start): array
    {
        $days = [];
        $day = $start->setTimezone($salon->timezone)->startOfDay();
        for ($i = 0; $i < 3; $i++) {
            $days[] = $day->addDays($i);
        }

        // Uncapped per day here — the nearest options may be late in the day,
        // and capping before the >= $start filter would drop them.
        $alternatives = collect($this->slotsFor($salon, $service, $stylists, $days, PHP_INT_MAX))
            ->filter(fn (array $slot): bool => CarbonImmutable::parse($slot['starts_at'])->gte($start->subMinutes(1)))
            ->take((int) config('booking_api.alternatives'))
            ->values()
            ->all();

        return [
            'success' => false,
            'error' => 'slot_unavailable',
            'alternatives' => $alternatives,
            'message' => $alternatives === []
                ? __('That time is no longer available, and there are no nearby openings for :service. Try another day.', ['service' => $service->name])
                : __('That time was just taken. The next openings for :service are: :options.', [
                    'service' => $service->name,
                    'options' => collect($alternatives)->map(fn (array $s): string => "{$s['spoken']} with {$s['stylist']}")->join('; '),
                ]),
        ];
    }
}
