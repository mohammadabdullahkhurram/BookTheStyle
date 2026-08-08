<?php

namespace App\Actions\Services;

use App\Actions\Bookings\PurgeBookings;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PERMANENTLY delete a service, its stylist associations, and every
 * appointment that used it — record and history gone ("stop offering but
 * keep history" stays SetServiceActive). Gated to the salon owner + agency
 * owner/admin (SalonPolicy::hardDelete), salon-scoped, never in the demo.
 *
 * Future bookings are never deleted silently: when open upcoming
 * appointments use the service, the caller must pass an explicit
 * acknowledgment (the UI's confirm modal surfaces them first) or the
 * whole delete refuses. A multi-service visit is removed WHOLE — an
 * appointment cannot survive with half its work missing.
 */
class DeleteService
{
    public function __construct(private PurgeBookings $purge) {}

    public function handle(User $actor, Salon $salon, Service $service, bool $acknowledgedUpcoming = false): void
    {
        if ($service->salon_id !== $salon->id) {
            throw new AuthorizationException('That service is not in this salon.');
        }

        if ($salon->is_demo || ! $actor->can('hardDelete', $salon)) {
            throw new AuthorizationException('You may not permanently delete services here.');
        }

        $bookings = self::bookingsUsing($salon, $service);

        if (PurgeBookings::upcoming($bookings)->isNotEmpty() && ! $acknowledgedUpcoming) {
            throw ValidationException::withMessages([
                'acknowledge' => __('This service has upcoming appointments — confirm you understand they will be permanently deleted.'),
            ]);
        }

        DB::transaction(function () use ($salon, $service, $bookings): void {
            $this->purge->handle($salon, $bookings);
            $service->stylists()->detach();
            $service->delete();
        });
    }

    /**
     * Every booking with at least one item of this service — the service's
     * blast radius, shared with the confirm UI.
     *
     * @return Collection<int, Booking>
     */
    public static function bookingsUsing(Salon $salon, Service $service): Collection
    {
        return Booking::query()
            ->where('salon_id', $salon->id)
            ->whereHas('items', fn ($q) => $q->where('service_id', $service->id))
            ->with(['items', 'client:id,name'])
            ->get();
    }
}
