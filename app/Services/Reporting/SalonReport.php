<?php

namespace App\Services\Reporting;

use App\Enums\BookingStatus;
use App\Models\Salon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reporting over EXISTING booking data (no tracking tables). All
 * metrics are SQL aggregates scoped to one salon and a UTC instant range
 * [start, end) — a handful of grouped queries per build, no N+1, nothing
 * runs unless the reports page asks for it.
 *
 * Time semantics: a booking belongs to the range when any of its items
 * starts inside it (post-split a booking has one stylist's items); item-level
 * metrics (revenue, stylists, services) filter items directly. Revenue is
 * ESTIMATED and informational — the sum of services.price_cents over
 * completed items whose service has a price; unpriced services are excluded
 * and surfaced as a count so the caveat is visible. The app takes no payments.
 */
class SalonReport
{
    /**
     * @return array{
     *     total: int,
     *     completed: int,
     *     cancelled: int,
     *     no_shows: int,
     *     no_show_rate: float|null,
     *     revenue_cents: int,
     *     unpriced_completed_items: int,
     *     source_mix: array<int, array{source: string, count: int, share: float}>,
     *     stylists: array<int, array{stylist_id: int, name: string, total: int, completed: int}>,
     *     top_services: array<int, array{service_id: int, name: string, count: int, revenue_cents: int|null}>,
     * }
     */
    public function build(Salon $salon, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $start = $start->utc();
        $end = $end->utc();

        $statusCounts = $this->bookingsInRange($salon, $start, $end)
            ->selectRaw('bookings.status, count(*) as c')
            ->groupBy('bookings.status')
            ->pluck('c', 'status')
            ->map(fn ($c): int => (int) $c);

        $total = (int) $statusCounts->sum();
        $completed = $statusCounts[BookingStatus::Completed->value] ?? 0;
        $cancelled = $statusCounts[BookingStatus::Cancelled->value] ?? 0;
        $noShows = $statusCounts[BookingStatus::NoShow->value] ?? 0;

        // No-show rate over bookings that were supposed to happen (cancelled
        // excluded). Null when there is nothing to rate.
        $eligible = $total - $cancelled;
        $noShowRate = $eligible > 0 ? round($noShows / $eligible * 100, 1) : null;

        $revenue = $this->itemsInRange($salon, $start, $end)
            ->join('services', 'services.id', '=', 'booking_items.service_id')
            ->where('bookings.status', BookingStatus::Completed->value)
            ->selectRaw('coalesce(sum(services.price_cents), 0) as cents, sum(case when services.price_cents is null then 1 else 0 end) as unpriced')
            ->first();

        $sourceMix = $this->bookingsInRange($salon, $start, $end)
            ->selectRaw('bookings.source, count(*) as c')
            ->groupBy('bookings.source')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($row): array => [
                'source' => (string) $row->source,
                'count' => (int) $row->c,
                'share' => $total > 0 ? round((int) $row->c / $total * 100, 1) : 0.0,
            ])
            ->values()
            ->all();

        $stylists = $this->itemsInRange($salon, $start, $end)
            ->join('users', 'users.id', '=', 'booking_items.stylist_id')
            ->where('bookings.status', '!=', BookingStatus::Cancelled->value)
            ->selectRaw('booking_items.stylist_id, users.name, count(*) as total, sum(case when bookings.status = ? then 1 else 0 end) as completed', [BookingStatus::Completed->value])
            ->groupBy('booking_items.stylist_id', 'users.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'stylist_id' => (int) $row->stylist_id,
                'name' => (string) $row->name,
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
            ])
            ->values()
            ->all();

        $topServices = $this->itemsInRange($salon, $start, $end)
            ->join('services', 'services.id', '=', 'booking_items.service_id')
            ->where('bookings.status', '!=', BookingStatus::Cancelled->value)
            ->selectRaw('booking_items.service_id, services.name, count(*) as c, sum(case when bookings.status = ? then services.price_cents else null end) as revenue', [BookingStatus::Completed->value])
            ->groupBy('booking_items.service_id', 'services.name')
            ->orderByDesc('c')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'service_id' => (int) $row->service_id,
                'name' => (string) $row->name,
                'count' => (int) $row->c,
                'revenue_cents' => $row->revenue !== null ? (int) $row->revenue : null,
            ])
            ->values()
            ->all();

        return [
            'total' => $total,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'no_shows' => $noShows,
            'no_show_rate' => $noShowRate,
            'revenue_cents' => (int) ($revenue->cents ?? 0),
            'unpriced_completed_items' => (int) ($revenue->unpriced ?? 0),
            'source_mix' => $sourceMix,
            'stylists' => $stylists,
            'top_services' => $topServices,
        ];
    }

    /**
     * Bookings with at least one item starting in [start, end) — plain query
     * builder, explicitly salon-scoped (never relies on the global scope).
     *
     * @return Builder
     */
    private function bookingsInRange(Salon $salon, CarbonImmutable $start, CarbonImmutable $end)
    {
        return DB::table('bookings')
            ->where('bookings.salon_id', $salon->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('booking_items')
                ->whereColumn('booking_items.booking_id', 'bookings.id')
                ->where('booking_items.starts_at', '>=', $start)
                ->where('booking_items.starts_at', '<', $end));
    }

    /**
     * Booking items starting in [start, end), joined to their booking.
     *
     * @return Builder
     */
    private function itemsInRange(Salon $salon, CarbonImmutable $start, CarbonImmutable $end)
    {
        return DB::table('booking_items')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->where('booking_items.salon_id', $salon->id)
            ->where('booking_items.starts_at', '>=', $start)
            ->where('booking_items.starts_at', '<', $end);
    }
}
