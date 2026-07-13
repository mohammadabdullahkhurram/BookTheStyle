<?php

namespace App\Services\Reporting;

use App\Enums\BookingStatus;
use App\Models\Agency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agency-wide reporting: SalonReport's definitions aggregated across EVERY
 * salon of one agency in a fixed handful of GROUPED queries (grouped by
 * salon and by source — never one query per salon, so the cost is bounded
 * regardless of how many salons the agency runs). Same time semantics as
 * SalonReport: a booking belongs to [start, end) when any item starts in it;
 * revenue is the ESTIMATED sum of completed items' service prices, kept in
 * cents PER CURRENCY (salons may bill in different currencies — never summed
 * across them).
 */
class AgencyReport
{
    /**
     * @return array{
     *     totals: array{total: int, completed: int, cancelled: int, no_shows: int, no_show_rate: float|null, unpriced_completed_items: int},
     *     revenue: array<string, int>,
     *     source_mix: array<int, array{source: string, count: int, share: float}>,
     *     salons: array<int, array{salon_id: int, name: string, currency: string, total: int, completed: int, cancelled: int, no_shows: int, no_show_rate: float|null, revenue_cents: int, sources: array<string, int>}>,
     * }
     */
    public function build(Agency $agency, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $start = $start->utc();
        $end = $end->utc();

        $salons = DB::table('salons')
            ->where('agency_id', $agency->id)
            ->orderBy('name')
            ->get(['id', 'name', 'currency']);
        $salonIds = array_values($salons->pluck('id')->map(fn ($id): int => (int) $id)->all());

        // One pass per fact, each grouped by salon: statuses, sources, revenue.
        $statusRows = $this->bookingsInRange($salonIds, $start, $end)
            ->selectRaw('bookings.salon_id, bookings.status, count(*) as c')
            ->groupBy('bookings.salon_id', 'bookings.status')
            ->get();

        $sourceRows = $this->bookingsInRange($salonIds, $start, $end)
            ->selectRaw('bookings.salon_id, bookings.source, count(*) as c')
            ->groupBy('bookings.salon_id', 'bookings.source')
            ->get();

        $revenueRows = DB::table('booking_items')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->join('services', 'services.id', '=', 'booking_items.service_id')
            ->whereIn('booking_items.salon_id', $salonIds)
            ->where('booking_items.starts_at', '>=', $start)
            ->where('booking_items.starts_at', '<', $end)
            ->where('bookings.status', BookingStatus::Completed->value)
            ->selectRaw('booking_items.salon_id, coalesce(sum(services.price_cents), 0) as cents, sum(case when services.price_cents is null then 1 else 0 end) as unpriced')
            ->groupBy('booking_items.salon_id')
            ->get()
            ->keyBy('salon_id');

        // Index the grouped rows, then build every per-salon shape in ONE
        // pass — no partial rows, no mutation after construction.
        $statusBySalon = [];
        foreach ($statusRows as $row) {
            $statusBySalon[(int) $row->salon_id][(string) $row->status] = (int) $row->c;
        }

        $sourcesBySalon = [];
        $sourceTotals = [];
        foreach ($sourceRows as $row) {
            $sourcesBySalon[(int) $row->salon_id][(string) $row->source] = (int) $row->c;
            $sourceTotals[(string) $row->source] = ($sourceTotals[(string) $row->source] ?? 0) + (int) $row->c;
        }

        $perSalon = [];
        $revenue = [];
        $grandTotal = 0;
        $grandCompleted = 0;
        $grandCancelled = 0;
        $grandNoShows = 0;

        foreach ($salons as $salon) {
            $statuses = $statusBySalon[(int) $salon->id] ?? [];
            $total = (int) array_sum($statuses);
            $completed = $statuses[BookingStatus::Completed->value] ?? 0;
            $cancelled = $statuses[BookingStatus::Cancelled->value] ?? 0;
            $noShows = $statuses[BookingStatus::NoShow->value] ?? 0;
            $eligible = $total - $cancelled;
            $cents = (int) ($revenueRows[$salon->id]->cents ?? 0);

            $perSalon[] = [
                'salon_id' => (int) $salon->id,
                'name' => (string) $salon->name,
                'currency' => (string) $salon->currency,
                'total' => $total,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_shows' => $noShows,
                // No-show rate over bookings meant to happen.
                'no_show_rate' => $eligible > 0 ? round($noShows / $eligible * 100, 1) : null,
                'revenue_cents' => $cents,
                'sources' => $sourcesBySalon[(int) $salon->id] ?? [],
            ];

            $grandTotal += $total;
            $grandCompleted += $completed;
            $grandCancelled += $cancelled;
            $grandNoShows += $noShows;

            // Revenue grouped per currency — cross-currency cents never mix.
            if ($cents > 0) {
                $revenue[(string) $salon->currency] = ($revenue[(string) $salon->currency] ?? 0) + $cents;
            }
        }
        ksort($revenue);

        $grandEligible = $grandTotal - $grandCancelled;
        $totals = [
            'total' => $grandTotal,
            'completed' => $grandCompleted,
            'cancelled' => $grandCancelled,
            'no_shows' => $grandNoShows,
            'no_show_rate' => $grandEligible > 0 ? round($grandNoShows / $grandEligible * 100, 1) : null,
            'unpriced_completed_items' => (int) $revenueRows->sum('unpriced'),
        ];

        arsort($sourceTotals);
        $sourceMix = [];
        foreach ($sourceTotals as $source => $count) {
            $sourceMix[] = [
                'source' => (string) $source,
                'count' => $count,
                'share' => $grandTotal > 0 ? round($count / $grandTotal * 100, 1) : 0.0,
            ];
        }

        // Activity ranking: most-booked salons first.
        usort($perSalon, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'totals' => $totals,
            'revenue' => $revenue,
            'source_mix' => $sourceMix,
            'salons' => $perSalon,
        ];
    }

    /**
     * Bookings (across the given salons) with at least one item starting in
     * [start, end) — mirrors SalonReport::bookingsInRange.
     *
     * @param  list<int>  $salonIds
     * @return Builder
     */
    private function bookingsInRange(array $salonIds, CarbonImmutable $start, CarbonImmutable $end)
    {
        return DB::table('bookings')
            ->whereIn('bookings.salon_id', $salonIds)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('booking_items')
                ->whereColumn('booking_items.booking_id', 'bookings.id')
                ->where('booking_items.starts_at', '>=', $start)
                ->where('booking_items.starts_at', '<', $end));
    }
}
