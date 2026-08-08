<?php

namespace App\Actions\Services;

use App\Models\Booking;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SOLO delete of a service: the service is removed — every APPOINTMENT
 * that used it IS KEPT, upcoming ones included. FK integrity via
 * tombstone: the row soft-deletes (the kept appointments' name snapshot;
 * SoftDeletes hides it from the menu, widget and pickers automatically).
 * The stylist⇄service "who offers it" pivot rows are pure links that
 * cannot exist without the service — removed as FK cleanup; that is not
 * an appointment. Gated to the salon owner + agency owner/admin
 * (SalonPolicy::hardDelete), salon-scoped, never in the demo.
 * "Stop offering but keep it on the menu history" stays SetServiceActive.
 */
class DeleteService
{
    public function handle(User $actor, Salon $salon, Service $service): void
    {
        if ($service->salon_id !== $salon->id) {
            throw new AuthorizationException('That service is not in this salon.');
        }

        if ($salon->is_demo || ! $actor->can('hardDelete', $salon)) {
            throw new AuthorizationException('You may not delete services here.');
        }

        DB::transaction(function () use ($service): void {
            $service->stylists()->detach(); // pure link rows — FK cleanup only
            $service->delete(); // soft — the name stays for kept appointments
        });
    }

    /**
     * Every booking with at least one item of this service — shown in the
     * confirm UI as what will be KEPT.
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
