<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One booking per SERVICE (correcting the previous per-stylist grouping): a
 * booking holding several service items — even for the same stylist — splits
 * so each item is its own booking, all linked by a shared visit_group_id.
 *
 * Data-only, no schema change, safe on empty databases. The first item keeps
 * the original booking row and its ghl_appointment_id (the next push shrinks
 * that GHL appointment to this single service via the payload-hash diff);
 * split-off bookings start unsynced and create their own appointments on
 * their first push. Status history is copied onto each split booking.
 * (Local dev data is the clean-slate seed — a reseed is equally fine there.)
 */
return new class extends Migration
{
    public function up(): void
    {
        $grouped = DB::table('booking_items')
            ->select('booking_id', DB::raw('count(*) as item_count'))
            ->groupBy('booking_id')
            ->having('item_count', '>', 1)
            ->pluck('booking_id');

        foreach ($grouped as $bookingId) {
            $booking = DB::table('bookings')->where('id', $bookingId)->first();
            if ($booking === null) {
                continue;
            }

            $items = DB::table('booking_items')
                ->where('booking_id', $bookingId)
                ->orderBy('starts_at')->orderBy('id')
                ->get();

            $visitGroup = $booking->visit_group_id ?? (string) Str::uuid();
            DB::table('bookings')->where('id', $bookingId)->update(['visit_group_id' => $visitGroup]);

            // Every item after the first becomes its own booking.
            foreach ($items->slice(1) as $item) {
                $newId = DB::table('bookings')->insertGetId([
                    'salon_id' => $booking->salon_id,
                    'client_id' => $booking->client_id,
                    'status' => $booking->status,
                    'booked_by_type' => $booking->booked_by_type,
                    'booked_by_user_id' => $booking->booked_by_user_id,
                    'source' => $booking->source,
                    'is_walkin' => $booking->is_walkin,
                    'notes' => $booking->notes,
                    'visit_group_id' => $visitGroup,
                    'ghl_appointment_id' => null, // its own appointment on first push
                    'created_at' => $booking->created_at,
                    'updated_at' => $booking->updated_at,
                ]);

                DB::table('booking_items')->where('id', $item->id)->update(['booking_id' => $newId]);

                foreach (DB::table('booking_status_events')->where('booking_id', $bookingId)->orderBy('id')->get() as $event) {
                    DB::table('booking_status_events')->insert([
                        'salon_id' => $event->salon_id,
                        'booking_id' => $newId,
                        'from_status' => $event->from_status,
                        'to_status' => $event->to_status,
                        'actor_user_id' => $event->actor_user_id,
                        'created_at' => $event->created_at,
                    ]);
                }
            }

            // The kept booking's GHL appointment now covers only its first
            // service — clear the hash so the next push corrects time+title.
            DB::table('bookings')->where('id', $bookingId)->update(['ghl_payload_hash' => null]);
        }
    }

    public function down(): void
    {
        // The split is not meaningfully reversible (which items belonged
        // together is exactly what visit_group_id now records); down() is a
        // deliberate no-op so migrate:rollback stays safe.
    }
};
