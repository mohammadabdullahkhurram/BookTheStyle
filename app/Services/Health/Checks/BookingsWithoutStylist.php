<?php

namespace App\Services\Health\Checks;

use App\Enums\BookingStatus;
use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Integrity spot check: upcoming appointments whose stylist is gone —
 * deleted account, removed membership, or membership switched off. These
 * blow up later (the client shows up, nobody is assigned) so they are
 * flagged now, by name and date. Read-only.
 */
class BookingsWithoutStylist implements HealthCheck
{
    public function key(): string
    {
        return 'bookings-without-stylist';
    }

    public function label(): string
    {
        return __('Appointments with no stylist');
    }

    public function run(HealthContext $context): CheckResult
    {
        $salon = $context->salon;

        $orphans = DB::table('booking_items')
            ->join('bookings', 'bookings.id', '=', 'booking_items.booking_id')
            ->leftJoin('users', 'users.id', '=', 'booking_items.stylist_id')
            ->leftJoin('salon_memberships', function ($join) use ($salon): void {
                $join->on('salon_memberships.user_id', '=', 'booking_items.stylist_id')
                    ->where('salon_memberships.salon_id', $salon->id);
            })
            ->where('booking_items.salon_id', $salon->id)
            ->whereIn('bookings.status', [BookingStatus::Booked->value, BookingStatus::Confirmed->value])
            ->where('booking_items.starts_at', '>=', now())
            ->where(function ($q): void {
                $q->whereNull('users.id')
                    ->orWhereNotNull('users.deleted_at')
                    ->orWhereNull('salon_memberships.id')
                    ->orWhere('salon_memberships.active', false);
            })
            ->orderBy('booking_items.starts_at')
            ->limit(20)
            ->get(['booking_items.booking_id', 'booking_items.starts_at', 'users.name']);

        if ($orphans->isEmpty()) {
            return CheckResult::pass(__('Every upcoming appointment has a working stylist behind it.'));
        }

        $items = $orphans->map(fn ($row): string => __('appointment #:id on :date (:who)', [
            'id' => $row->booking_id,
            'date' => CarbonImmutable::parse($row->starts_at)->timezone($salon->timezone)->format('D j M, g:ia'),
            'who' => $row->name !== null ? __('stylist :name no longer takes bookings here', ['name' => $row->name]) : __('stylist account is gone'),
        ]))->implode('; ');

        return CheckResult::fail(
            trans_choice(
                ':count upcoming appointment points at a stylist who is gone or inactive — the client will arrive and nobody is assigned: :items.|:count upcoming appointments point at stylists who are gone or inactive — those clients will arrive and nobody is assigned: :items.',
                $orphans->count(),
                ['count' => $orphans->count(), 'items' => $items],
            ),
            __('Open each appointment on the calendar and reassign it to a working stylist, or cancel and rebook.'),
        );
    }
}
