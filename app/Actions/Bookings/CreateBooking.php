<?php

namespace App\Actions\Bookings;

use App\Actions\Clients\CreateClient;
use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGhl;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingPolicy;
use App\Services\Booking\DurationResolver;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create the bookings for one composed visit — ONE BOOKING PER SERVICE
 * line, regardless of stylist overlap. Every item names its stylist
 * explicitly
 * (staff book deliberately — there is no "any available" resolution), and an
 * item may carry its own start time: same-time, back-to-back and
 * different-time layouts across stylists are all expressed per item. Items
 * without an explicit start lay sequentially after the previous one (and
 * walk-ins always start now, sequentially).
 *
 * Client-submitted times are never trusted: inside a transaction we lock the
 * involved stylists' rows and re-validate every item against availability and
 * existing bookings — plus overlap among the submission's own same-stylist
 * items — rejecting with a clear conflict error if anything was just taken.
 * Booking policy is enforced authoritatively here too.
 */
class CreateBooking
{
    public function __construct(
        private SlotEngine $engine,
        private BookingPolicy $policy,
        private CreateClient $createClient,
        private DurationResolver $resolver,
    ) {}

    /**
     * A null $actor is a SYSTEM caller (the token-authenticated Booking API):
     * the token already authorizes the whole salon, so the per-actor
     * permission check is skipped and $bookedByType/$source say who booked.
     * Human callers keep the exact previous behavior.
     *
     * @param  array{
     *     client: array{id?: int, name?: string, phone?: string|null, email?: string|null},
     *     items: list<array{service_id: int, stylist_id: int|null, start?: string|null}>,
     *     start?: string|null,
     *     is_walkin?: bool,
     *     notes?: string|null,
     * }  $data
     */
    public function handle(?User $actor, Salon $salon, array $data, BookingSource $source = BookingSource::InApp, ?BookedByType $bookedByType = null): Booking
    {
        $bookings = $this->create($actor, $salon, $data, $source, $bookedByType);

        // Mirror to GHL in the background AFTER the bookings committed — the
        // app bookings are the source of truth and never wait on GHL. Each
        // booking is one stylist, so each becomes one GHL appointment.
        foreach ($bookings as $booking) {
            SyncBookingToGhl::queueFor($booking);
        }

        return $bookings[0];
    }

    /**
     * @param  array{
     *     client: array{id?: int, name?: string, phone?: string|null, email?: string|null},
     *     items: list<array{service_id: int, stylist_id: int|null, start?: string|null}>,
     *     start?: string|null,
     *     is_walkin?: bool,
     *     notes?: string|null,
     * }  $data
     * @return list<Booking> one booking per distinct stylist, earliest first
     */
    private function create(?User $actor, Salon $salon, array $data, BookingSource $source, ?BookedByType $bookedByType): array
    {
        $isWalkin = (bool) ($data['is_walkin'] ?? false);
        $tz = $salon->timezone;

        if ($data['items'] === []) {
            throw ValidationException::withMessages(['items' => __('Add at least one service.')]);
        }

        $start = $isWalkin
            ? CarbonImmutable::now($tz)
            : CarbonImmutable::parse($data['start'] ?? throw ValidationException::withMessages(['start' => __('Choose a start time.')]), $tz);

        $this->policy->assertCreatable($salon, $start, $isWalkin);

        return DB::transaction(function () use ($actor, $salon, $data, $start, $isWalkin, $source, $bookedByType): array {
            $client = $this->resolveClient($salon, $data['client']);

            // Lay items sequentially and resolve each stylist.
            $cursor = $start;
            $resolved = [];

            foreach ($data['items'] as $item) {
                $service = $salon->services()->where('active', true)->whereKey($item['service_id'])->first();
                if ($service === null) {
                    throw ValidationException::withMessages(['items' => __('That service is unavailable.')]);
                }

                // Staff always choose the stylist deliberately — an item
                // without one is invalid, never auto-assigned.
                if (($item['stylist_id'] ?? null) === null) {
                    throw ValidationException::withMessages([
                        'items' => __('Choose a stylist for every service.'),
                    ]);
                }

                // An item may carry its own start (multi-stylist layouts);
                // otherwise it lays sequentially after the previous item.
                // Walk-ins always run from now.
                $itemStart = $cursor;
                if (! $isWalkin && filled($item['start'] ?? null)) {
                    $itemStart = CarbonImmutable::parse((string) $item['start'], $salon->timezone);
                    $this->policy->assertCreatable($salon, $itemStart, false);
                }

                $stylistId = $this->assertQualified($service, (int) $item['stylist_id']);
                $duration = $this->resolver->resolve($salon, $service, $stylistId);

                $resolved[] = [
                    'service' => $service,
                    'stylist_id' => $stylistId,
                    // Visible block = client-facing (service) minutes; the buffer
                    // is stored separately and occupies the stylist after it.
                    'starts_at' => $itemStart,
                    'ends_at' => $itemStart->addMinutes($duration->clientFacingMinutes()),
                    'buffer_min' => $duration->bufferMinutes,
                    'blocked' => $duration->blockedMinutes(),
                ];

                // Next service starts after this one's service + buffer.
                $cursor = $itemStart->addMinutes($duration->blockedMinutes());
            }

            if ($actor !== null) {
                $this->assertActorMayBook($actor, $salon, $resolved);
            }
            $this->assertNoInternalOverlap($resolved);

            // Lock the involved stylists (ordered, to avoid deadlocks) then
            // re-validate against availability + existing bookings under lock.
            $stylistIds = collect($resolved)->pluck('stylist_id')->unique()->sort()->values();
            foreach ($stylistIds as $id) {
                User::query()->whereKey($id)->lockForUpdate()->first();
            }

            foreach ($resolved as $ri) {
                if (! $this->engine->isAvailable($salon, $ri['stylist_id'], $ri['starts_at'], $ri['blocked'])) {
                    throw ValidationException::withMessages([
                        'start' => __('That time was just taken. Please choose another slot.'),
                    ]);
                }
            }

            $status = $isWalkin ? BookingStatus::Arrived : BookingStatus::Booked;

            // ONE BOOKING PER SERVICE: every service line of a composed
            // visit persists as its own booking — one service, its stylist,
            // its own start/end — even when the same stylist performs
            // several of them. Bookings made together share a visit group so
            // they read as one visit without being one booking. A
            // single-service visit stays one booking, no group.
            $lines = collect($resolved)
                ->sortBy(fn (array $ri) => [$ri['starts_at']->getTimestamp(), $ri['stylist_id']])
                ->values();

            $visitGroupId = $lines->count() > 1 ? (string) Str::uuid() : null;
            $bookings = [];

            foreach ($lines as $ri) {
                $booking = $salon->bookings()->create([
                    'client_id' => $client->id,
                    'status' => $status,
                    'booked_by_type' => $bookedByType ?? BookedByType::fromActor($actor ?? throw new AuthorizationException('A booked-by type is required for system bookings.'), $salon),
                    'booked_by_user_id' => $actor?->id,
                    'source' => $source,
                    'is_walkin' => $isWalkin,
                    'notes' => $data['notes'] ?? null,
                    'visit_group_id' => $visitGroupId,
                ]);

                $booking->items()->create([
                    'salon_id' => $salon->id,
                    'service_id' => $ri['service']->id,
                    'stylist_id' => $ri['stylist_id'],
                    'starts_at' => $ri['starts_at'],
                    'ends_at' => $ri['ends_at'],
                    'buffer_min' => $ri['buffer_min'],
                ]);

                // Status timeline: created (→ booked), and immediately
                // arrived for walk-ins (checked in in one step).
                $booking->statusEvents()->create([
                    'salon_id' => $salon->id,
                    'from_status' => null,
                    'to_status' => BookingStatus::Booked,
                    'actor_user_id' => $actor?->id,
                ]);

                if ($isWalkin) {
                    $booking->statusEvents()->create([
                        'salon_id' => $salon->id,
                        'from_status' => BookingStatus::Booked,
                        'to_status' => BookingStatus::Arrived,
                        'actor_user_id' => $actor?->id,
                    ]);
                }

                $bookings[] = $booking;
            }

            return $bookings;
        });
    }

    /**
     * @param  array{id?: int, name?: string, phone?: string|null, email?: string|null}  $data
     */
    private function resolveClient(Salon $salon, array $data): Client
    {
        if (! empty($data['id'])) {
            $client = $salon->clients()->whereKey($data['id'])->first();
            if ($client === null) {
                throw ValidationException::withMessages(['client' => __('That client is not in this salon.')]);
            }

            return $client;
        }

        if (empty($data['name'])) {
            throw ValidationException::withMessages(['client' => __('Choose or add a client.')]);
        }

        return $this->createClient->handle($salon, [
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ]);
    }

    private function assertQualified(Service $service, int $stylistId): int
    {
        if (! $service->stylists()->whereKey($stylistId)->exists()) {
            throw ValidationException::withMessages([
                'items' => __('That stylist does not perform :service.', ['service' => $service->name]),
            ]);
        }

        return $stylistId;
    }

    /**
     * With per-item start times, two items for the SAME stylist inside one
     * submission could overlap each other — something the slot engine cannot
     * see (it only knows persisted bookings). Reject it here.
     *
     * @param  list<array{stylist_id: int, service: Service, starts_at: CarbonImmutable, ends_at: CarbonImmutable, buffer_min: int, blocked: int}>  $resolved
     */
    private function assertNoInternalOverlap(array $resolved): void
    {
        foreach (collect($resolved)->groupBy('stylist_id') as $group) {
            $sorted = $group->sortBy(fn (array $ri) => $ri['starts_at']->getTimestamp())->values();

            for ($i = 1; $i < $sorted->count(); $i++) {
                $previous = $sorted[$i - 1];
                $blockedEnd = $previous['starts_at']->addMinutes($previous['blocked']);

                if ($sorted[$i]['starts_at']->lt($blockedEnd)) {
                    throw ValidationException::withMessages([
                        'start' => __('Two services for the same stylist overlap. Adjust the times.'),
                    ]);
                }
            }
        }
    }

    /**
     * Booking creation is a MANAGER surface — with ONE arrangement-aware
     * exception: a BOOTH-RENTING stylist runs their own business and may
     * create bookings whose every item is their own. Employee stylists never
     * book (the desk does); blocking out their time is what availability and
     * time off are for. System callers (null actor — voice AI, the widget,
     * GHL inbound) never reach here.
     *
     * @param  list<array{stylist_id: int, service: Service, starts_at: CarbonImmutable, ends_at: CarbonImmutable, buffer_min: int, blocked: int}>  $resolved
     */
    private function assertActorMayBook(User $actor, Salon $salon, array $resolved): void
    {
        if ($actor->can('manageBookings', $salon)) {
            return;
        }

        $boothRenter = $actor->boothRenterMembershipFor($salon) !== null;
        $allOwn = collect($resolved)->every(fn ($ri) => $ri['stylist_id'] === $actor->id);

        if (! ($boothRenter && $allOwn)) {
            throw new AuthorizationException('Only salon managers may create bookings.');
        }
    }
}
