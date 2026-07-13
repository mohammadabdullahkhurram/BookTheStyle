<?php

namespace App\Services\BookingApi;

use App\Actions\Bookings\CreateBooking;
use App\Actions\Clients\CreateClient;
use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\DurationResolver;
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
     * @param  array{services: list<int|string>, stylist?: string|int|null, date?: string|null}  $input
     * @return array<string, mixed>
     */
    public function visitAvailability(Salon $salon, array $input): array
    {
        $services = $this->resolveVisitServices($salon, $input['services']);
        $stylists = $this->resolveVisitStylists($salon, $services, $input['stylist'] ?? null);
        $days = $this->resolveDays($salon, $input['date'] ?? null, null);

        $slots = $this->visitSlotsFor($salon, $services, $stylists, $days, (int) config('booking_api.max_slots_per_day'));

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
     * @param  array{services: list<int|string>, stylist?: string|int|null, date?: string|null, time?: string|null, datetime?: string|null, client: array{name: string, phone?: string|null, email?: string|null}, notes?: string|null}  $input
     * @return array<string, mixed>
     */
    public function createVisit(
        Salon $salon,
        array $input,
        BookingSource $source = BookingSource::WebWidget,
        BookedByType $bookedBy = BookedByType::WebWidget,
    ): array {
        $services = $this->resolveVisitServices($salon, $input['services']);
        $stylists = $this->resolveVisitStylists($salon, $services, $input['stylist'] ?? null);
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
            ->with('items.stylist')
            ->first();

        if ($existing !== null) {
            return $this->visitConfirmation($salon, $existing->id, $services, $existing->items->first()->stylist, $start, idempotent: true);
        }

        $stylist = $this->pickVisitStylist($salon, $services, $stylists, $start);

        if ($stylist === null) {
            return $this->visitSlotTaken($salon, $services, $stylists, $start);
        }

        try {
            $booking = $this->createBooking->handle(null, $salon, [
                'client' => ['id' => $client->id],
                'items' => array_map(
                    fn (Service $s): array => ['service_id' => (int) $s->id, 'stylist_id' => (int) $stylist->id],
                    $services,
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

            return $this->visitSlotTaken($salon, $services, $stylists, $start);
        }

        Log::info('Booking API created visit', [
            'category' => 'engine',
            'salon_id' => $salon->id,
            'booking_id' => $booking->id,
            'service_ids' => collect($services)->pluck('id')->all(),
            'stylist_id' => $stylist->id,
        ]);

        return $this->visitConfirmation($salon, $booking->id, $services, $stylist, $start);
    }

    /**
     * The dates in [from, to] with at least ONE slot where the whole visit
     * fits — the widget's month calendar. One engine pass per stylist per
     * day, short-circuiting the remaining stylists as soon as a day has any
     * opening; per-stylist blocked minutes are resolved once up front.
     *
     * @param  array{services: list<int|string>, stylist?: string|int|null}  $input
     * @return array{dates: list<string>, timezone: string}
     */
    public function visitAvailableDates(Salon $salon, array $input, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $services = $this->resolveVisitServices($salon, $input['services']);
        $stylists = $this->resolveVisitStylists($salon, $services, $input['stylist'] ?? null);

        $blockedByStylist = [];
        foreach ($stylists as $stylist) {
            $blockedByStylist[$stylist->id] = $this->visitBlockedMinutes($salon, $services, $stylist);
        }

        $dates = [];
        // Hard cap: a calendar month view plus its leading/trailing edges.
        for ($day = $from, $i = 0; $day->lte($to) && $i < 42; $day = $day->addDay(), $i++) {
            foreach ($stylists as $stylist) {
                if ($this->engine->slotsFor($salon, $stylist->id, $blockedByStylist[$stylist->id], $day) !== []) {
                    $dates[] = $day->format('Y-m-d');
                    break;
                }
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
     * Stylists able to perform EVERY selected service (a widget visit keeps
     * one stylist throughout), optionally narrowed to a requested one.
     *
     * @param  non-empty-list<Service>  $services
     * @return non-empty-list<User>
     */
    private function resolveVisitStylists(Salon $salon, array $services, string|int|null $ref): array
    {
        $perService = array_map(
            fn (Service $s): array => collect($this->resolveStylists($salon, $s, null))->keyBy('id')->all(),
            $services,
        );

        $shared = array_values(array_filter(
            $perService[0],
            function (User $stylist) use ($perService): bool {
                foreach ($perService as $qualified) {
                    if (! isset($qualified[$stylist->id])) {
                        return false;
                    }
                }

                return true;
            },
        ));

        if ($shared === []) {
            throw ApiError::validation(
                __('No single stylist offers all of those services together. Book them separately, or pick a different combination.'),
                'no_shared_stylist',
            );
        }

        $term = mb_strtolower(trim((string) ($ref ?? '')));
        if ($term === '' || $term === 'any' || $term === 'anyone' || $term === 'any available') {
            return $shared;
        }

        foreach ($shared as $stylist) {
            if ((is_numeric($ref) && (int) $stylist->id === (int) $ref)
                || str_contains(mb_strtolower($stylist->name), $term)) {
                return [$stylist];
            }
        }

        throw ApiError::validation(
            __(':term cannot take the whole visit. It can be taken by: :options.', [
                'term' => (string) $ref,
                'options' => collect($shared)->pluck('name')->join(', '),
            ]),
            'unknown_stylist',
            ['stylists' => collect($shared)->pluck('name')->values()->all()],
        );
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
     * Summed client-facing minutes for the visit (what the customer sees).
     *
     * @param  non-empty-list<Service>  $services
     */
    private function visitClientMinutes(Salon $salon, array $services, User $stylist): int
    {
        return (int) collect($services)
            ->sum(fn (Service $s): int => $this->resolver->resolve($salon, $s, $stylist->id)->clientFacingMinutes());
    }

    /**
     * Slots where the WHOLE visit fits, merged across the allowed stylists,
     * deduped per start, capped per day — the multi-service twin of slotsFor.
     *
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<User>  $stylists
     * @param  list<CarbonImmutable>  $days
     * @return list<array{starts_at: string, spoken: string, date: string, time: string, stylist_id: int, stylist: string, duration_minutes: int}>
     */
    private function visitSlotsFor(Salon $salon, array $services, array $stylists, array $days, int $perDayCap): array
    {
        $out = [];

        foreach ($days as $day) {
            $daySlots = [];

            foreach ($stylists as $stylist) {
                $blocked = $this->visitBlockedMinutes($salon, $services, $stylist);

                foreach ($this->engine->slotsFor($salon, $stylist->id, $blocked, $day) as $slot) {
                    $key = $slot->getTimestamp();
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
                        'duration_minutes' => $this->visitClientMinutes($salon, $services, $stylist),
                    ];
                }
            }

            ksort($daySlots);
            $out = array_merge($out, array_slice(array_values($daySlots), 0, $perDayCap));
        }

        return $out;
    }

    /**
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<User>  $stylists
     */
    private function pickVisitStylist(Salon $salon, array $services, array $stylists, CarbonImmutable $start): ?User
    {
        foreach ($stylists as $stylist) {
            $blocked = $this->visitBlockedMinutes($salon, $services, $stylist);

            if ($this->engine->isAvailable($salon, $stylist->id, $start, $blocked)) {
                return $stylist;
            }
        }

        return null;
    }

    /**
     * @param  non-empty-list<Service>  $services
     * @return array<string, mixed>
     */
    private function visitConfirmation(Salon $salon, int $bookingId, array $services, User $stylist, CarbonImmutable $start, bool $idempotent = false): array
    {
        $spoken = $start->setTimezone($salon->timezone)->format('l, F j \a\t g:i A');
        $names = collect($services)->pluck('name')->join(', ');

        return [
            'success' => true,
            'idempotent' => $idempotent,
            'booking_id' => $bookingId,
            'confirmation' => [
                'salon' => $salon->name,
                'services' => collect($services)->pluck('name')->values()->all(),
                'service' => $names,
                'stylist' => $stylist->name,
                'starts_at' => $start->setTimezone($salon->timezone)->toIso8601String(),
                'spoken_time' => $spoken,
            ],
            'message' => __("You're booked for :service with :stylist on :time at :salon.", [
                'service' => $names,
                'stylist' => $stylist->name,
                'time' => $spoken,
                'salon' => $salon->name,
            ]),
        ];
    }

    /**
     * @param  non-empty-list<Service>  $services
     * @param  non-empty-list<User>  $stylists
     * @return array<string, mixed>
     */
    private function visitSlotTaken(Salon $salon, array $services, array $stylists, CarbonImmutable $start): array
    {
        $days = [];
        $day = $start->setTimezone($salon->timezone)->startOfDay();
        for ($i = 0; $i < 3; $i++) {
            $days[] = $day->addDays($i);
        }

        $alternatives = collect($this->visitSlotsFor($salon, $services, $stylists, $days, PHP_INT_MAX))
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
