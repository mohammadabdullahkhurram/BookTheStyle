<?php

namespace App\Actions\Bookings;

use App\Actions\Clients\CreateClient;
use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingPolicy;
use App\Services\Booking\DurationResolver;
use App\Services\Booking\ResolvedDuration;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a multi-service booking. The flow: resolve/create the client, lay each
 * service item sequentially back-to-back from the chosen start (each in its own
 * stylist's block), resolving "any available" per item to the least-busy
 * qualified free stylist.
 *
 * Client-submitted times are never trusted: inside a transaction we lock the
 * involved stylists' rows and re-validate every item against availability and
 * existing bookings, rejecting with a clear conflict error if anything was just
 * taken. Booking policy is enforced authoritatively here too.
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
     * @param  array{
     *     client: array{id?: int, name?: string, phone?: string|null, email?: string|null},
     *     items: list<array{service_id: int, stylist_id: int|null}>,
     *     start?: string|null,
     *     is_walkin?: bool,
     *     notes?: string|null,
     * }  $data
     */
    public function handle(User $actor, Salon $salon, array $data): Booking
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

        return DB::transaction(function () use ($actor, $salon, $data, $start, $isWalkin): Booking {
            $client = $this->resolveClient($salon, $data['client']);

            // Lay items sequentially and resolve each stylist.
            $cursor = $start;
            $resolved = [];

            foreach ($data['items'] as $item) {
                $service = $salon->services()->where('active', true)->whereKey($item['service_id'])->first();
                if ($service === null) {
                    throw ValidationException::withMessages(['items' => __('That service is unavailable.')]);
                }

                $itemStart = $cursor;

                // Resolve the stylist + their duration. For "any available" each
                // candidate is evaluated with their OWN resolved blocked time.
                if ($item['stylist_id'] !== null) {
                    $stylistId = $this->assertQualified($service, (int) $item['stylist_id']);
                    $duration = $this->resolver->resolve($salon, $service, $stylistId);
                } else {
                    [$stylistId, $duration] = $this->leastBusyAvailable($salon, $service, $itemStart);
                }

                if ($stylistId === null || $duration === null) {
                    throw ValidationException::withMessages([
                        'items' => __('No qualified stylist is free for :service at that time.', ['service' => $service->name]),
                    ]);
                }

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

            $this->assertActorMayBook($actor, $salon, $resolved);

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

            $booking = $salon->bookings()->create([
                'client_id' => $client->id,
                'status' => $status,
                'booked_by_type' => BookedByType::fromActor($actor, $salon),
                'booked_by_user_id' => $actor->id,
                'source' => BookingSource::InApp,
                'is_walkin' => $isWalkin,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($resolved as $ri) {
                $booking->items()->create([
                    'salon_id' => $salon->id,
                    'service_id' => $ri['service']->id,
                    'stylist_id' => $ri['stylist_id'],
                    'starts_at' => $ri['starts_at'],
                    'ends_at' => $ri['ends_at'],
                    'buffer_min' => $ri['buffer_min'],
                ]);
            }

            // Status timeline: created (→ booked), and immediately arrived for
            // walk-ins (checked in in one step).
            $booking->statusEvents()->create([
                'salon_id' => $salon->id,
                'from_status' => null,
                'to_status' => BookingStatus::Booked,
                'actor_user_id' => $actor->id,
            ]);

            if ($isWalkin) {
                $booking->statusEvents()->create([
                    'salon_id' => $salon->id,
                    'from_status' => BookingStatus::Booked,
                    'to_status' => BookingStatus::Arrived,
                    'actor_user_id' => $actor->id,
                ]);
            }

            return $booking;
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
     * The least-busy qualified, active stylist who is free for the block, each
     * evaluated with their OWN resolved blocked time. Returns [stylistId,
     * ResolvedDuration] (tied together — the returned duration is the chosen
     * stylist's), or [null, null] when none fit.
     *
     * @return array{0: int|null, 1: ResolvedDuration|null}
     */
    private function leastBusyAvailable(Salon $salon, Service $service, CarbonImmutable $start): array
    {
        $activeStylistIds = $salon->stylistUsers()->pluck('users.id')->all();

        $candidates = $service->stylists()
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id) => in_array($id, $activeStylistIds, true))
            ->filter(fn (int $id) => $this->engine->isAvailable(
                $salon, $id, $start, $this->resolver->resolve($salon, $service, $id)->blockedMinutes()
            ))
            ->values();

        if ($candidates->isEmpty()) {
            return [null, null];
        }

        $day = $start->setTimezone($salon->timezone)->startOfDay();

        $chosen = (int) $candidates
            ->sortBy(fn (int $id) => [$this->busyCountOnDay($salon, $id, $day), $id])
            ->first();

        return [$chosen, $this->resolver->resolve($salon, $service, $chosen)];
    }

    private function busyCountOnDay(Salon $salon, int $stylistId, CarbonImmutable $day): int
    {
        return DB::table('booking_items')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->where('booking_items.salon_id', $salon->id)
            ->where('booking_items.stylist_id', $stylistId)
            ->where('bookings.status', '!=', BookingStatus::Cancelled->value)
            ->where('booking_items.starts_at', '>=', $day->utc())
            ->where('booking_items.starts_at', '<', $day->addDay()->utc())
            ->count();
    }

    /**
     * Non-managers (stylists) may only create bookings whose every item is their
     * own. Managers/front desk may book any stylist.
     *
     * @param  list<array{stylist_id: int, service: Service, starts_at: CarbonImmutable, ends_at: CarbonImmutable, buffer_min: int, blocked: int}>  $resolved
     */
    private function assertActorMayBook(User $actor, Salon $salon, array $resolved): void
    {
        if ($actor->can('manageBookings', $salon)) {
            return;
        }

        $ownStylist = $actor->stylistMembershipFor($salon) !== null;
        $allOwn = collect($resolved)->every(fn ($ri) => $ri['stylist_id'] === $actor->id);

        if (! ($ownStylist && $allOwn)) {
            throw new AuthorizationException('You may only book your own appointments.');
        }
    }
}
