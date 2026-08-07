<?php

namespace App\Services\Clients;

use App\Enums\BookingStatus;
use App\Models\Client;
use App\Models\Salon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Clients directory query — one paginated SELECT with correlated
 * subqueries for every per-row stat (visit/service counts, last/upcoming
 * visit, estimated spend, no-shows). No booking models are hydrated and
 * nothing is computed per client in PHP, so a salon with thousands of
 * clients pays for one page, not its history. All stats are explicitly
 * salon-scoped through the client (and bookings carry the same salon_id).
 *
 * "Visits" counts completed VISITS, not bookings: a multi-service visit is
 * split into one booking per service (visit_group_id links them), so we
 * count DISTINCT COALESCE(visit_group_id, id). "Services" counts the
 * completed booking items themselves.
 */
class ClientDirectory
{
    public const SORTS = ['name', 'visits', 'recent', 'spent', 'newest'];

    /** Days a client counts as "new" (badge + filter). */
    public const NEW_CLIENT_DAYS = 30;

    /**
     * @param  array{search?: string, stylist_id?: int|null, service_id?: int|null, upcoming_only?: bool, new_only?: bool, sort?: string}  $filters
     * @return LengthAwarePaginator<int, Client>
     */
    public function paginate(Salon $salon, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $now = CarbonImmutable::now('UTC');
        $term = trim($filters['search'] ?? '');

        $query = $salon->clients()
            ->select('clients.*')
            ->addSelect([
                'total_visits' => $this->completedBookings()
                    ->selectRaw('count(distinct coalesce(visit_group_id, id))'),
                'total_services' => $this->completedItems()
                    ->selectRaw('count(*)'),
                'spent_cents' => $this->completedItems()
                    ->join('services', 'services.id', '=', 'booking_items.service_id')
                    ->selectRaw('coalesce(sum(services.price_cents), 0)'),
                'last_visit_at' => DB::table('booking_items')
                    ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->where('bookings.status', '!=', BookingStatus::Cancelled->value)
                    ->where('booking_items.starts_at', '<=', $now)
                    ->selectRaw('max(booking_items.starts_at)'),
                'upcoming_at' => DB::table('booking_items')
                    ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->whereIn('bookings.status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value, BookingStatus::Arrived->value])
                    ->where('booking_items.starts_at', '>', $now)
                    ->selectRaw('min(booking_items.starts_at)'),
                'no_show_count' => DB::table('bookings')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->where('bookings.status', BookingStatus::NoShow->value)
                    ->selectRaw('count(*)'),
            ])
            ->withCount('notes')
            ->with('preferredStylist:id,name')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->when(($filters['stylist_id'] ?? null) !== null, fn ($q) => $q->whereExists(
                fn (Builder $sub) => $sub->selectRaw('1')
                    ->from('booking_items')
                    ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->where('bookings.status', '!=', BookingStatus::Cancelled->value)
                    ->where('booking_items.stylist_id', (int) $filters['stylist_id'])))
            ->when(($filters['service_id'] ?? null) !== null, fn ($q) => $q->whereExists(
                fn (Builder $sub) => $sub->selectRaw('1')
                    ->from('booking_items')
                    ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->where('bookings.status', '!=', BookingStatus::Cancelled->value)
                    ->where('booking_items.service_id', (int) $filters['service_id'])))
            ->when($filters['upcoming_only'] ?? false, fn ($q) => $q->whereExists(
                fn (Builder $sub) => $sub->selectRaw('1')
                    ->from('booking_items')
                    ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
                    ->whereColumn('bookings.client_id', 'clients.id')
                    ->whereIn('bookings.status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value, BookingStatus::Arrived->value])
                    ->where('booking_items.starts_at', '>', $now)))
            ->when($filters['new_only'] ?? false, fn ($q) => $q
                ->where('clients.created_at', '>=', $now->subDays(self::NEW_CLIENT_DAYS)));

        match ($filters['sort'] ?? 'name') {
            'visits' => $query->orderByDesc('total_visits')->orderBy('name'),
            'recent' => $query->orderByDesc('last_visit_at')->orderBy('name'),
            'spent' => $query->orderByDesc('spent_cents')->orderBy('name'),
            'newest' => $query->orderByDesc('clients.created_at')->orderBy('name'),
            default => $query->orderBy('name'),
        };

        return $query->paginate($perPage);
    }

    /**
     * Header summary — two cheap aggregates over the salon's clients.
     *
     * @return array{total: int, new_this_month: int}
     */
    public function summary(Salon $salon): array
    {
        return [
            'total' => $salon->clients()->count(),
            'new_this_month' => $salon->clients()
                ->where('created_at', '>=', CarbonImmutable::now('UTC')->subDays(self::NEW_CLIENT_DAYS))
                ->count(),
        ];
    }

    private function completedBookings(): Builder
    {
        return DB::table('bookings')
            ->whereColumn('bookings.client_id', 'clients.id')
            ->where('bookings.status', BookingStatus::Completed->value);
    }

    private function completedItems(): Builder
    {
        return DB::table('booking_items')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->whereColumn('bookings.client_id', 'clients.id')
            ->where('bookings.status', BookingStatus::Completed->value);
    }
}
