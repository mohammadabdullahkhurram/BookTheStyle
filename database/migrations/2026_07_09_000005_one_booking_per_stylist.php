<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One booking per stylist. A visit composed across several stylists is now
 * stored as separate bookings — one per stylist, each holding only that
 * stylist's items — linked by a shared visit_group_id. That makes the GHL
 * mirror a clean 1:1 (one booking ↔ one appointment), so the per-stylist
 * booking_ghl_appointments table folds back into booking-level columns
 * (ghl_appointment_id / sync status / error / payload hash / last_synced_at).
 *
 * Backfill: single-stylist bookings absorb their slice row directly.
 * Multi-stylist bookings are SPLIT — each extra stylist gets a new booking
 * carrying their items, their slice's GHL appointment id, copies of the
 * status events, and the shared visit_group_id. Nothing is lost.
 * (Dev data is a clean slate post-reseed, so in practice this backfill runs
 * over empty or test-created rows.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('visit_group_id', 36)->nullable()->after('notes');
            $table->string('ghl_appointment_id')->nullable()->after('visit_group_id');
            $table->string('ghl_sync_status')->nullable()->after('ghl_appointment_id');
            $table->string('ghl_sync_error', 500)->nullable()->after('ghl_sync_status');
            $table->string('ghl_payload_hash', 64)->nullable()->after('ghl_sync_error');
            $table->timestamp('last_synced_at')->nullable()->after('ghl_payload_hash');

            $table->index('visit_group_id');
            $table->index('ghl_appointment_id');
        });

        foreach (DB::table('bookings')->orderBy('id')->get() as $booking) {
            $stylistIds = DB::table('booking_items')
                ->where('booking_id', $booking->id)
                ->orderBy('starts_at')->orderBy('id')
                ->pluck('stylist_id')->unique()->values();

            $slices = DB::table('booking_ghl_appointments')
                ->where('booking_id', $booking->id)
                ->get()->keyBy('stylist_id');

            $visitGroup = $stylistIds->count() > 1 ? (string) Str::uuid() : null;

            // The first stylist keeps the original booking row.
            $first = $stylistIds->first();
            $firstSlice = $first === null ? null : $slices->get($first);
            DB::table('bookings')->where('id', $booking->id)->update([
                'visit_group_id' => $visitGroup,
                'ghl_appointment_id' => $firstSlice->ghl_appointment_id ?? null,
                'ghl_sync_status' => $firstSlice->sync_status ?? null,
                'ghl_sync_error' => $firstSlice->sync_error ?? null,
                'ghl_payload_hash' => $firstSlice->payload_hash ?? null,
                'last_synced_at' => $firstSlice->last_synced_at ?? null,
            ]);

            // Every other stylist splits into their own booking.
            foreach ($stylistIds->slice(1) as $stylistId) {
                $slice = $slices->get($stylistId);

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
                    'ghl_appointment_id' => $slice->ghl_appointment_id ?? null,
                    'ghl_sync_status' => $slice->sync_status ?? null,
                    'ghl_sync_error' => $slice->sync_error ?? null,
                    'ghl_payload_hash' => $slice->payload_hash ?? null,
                    'last_synced_at' => $slice->last_synced_at ?? null,
                    'created_at' => $booking->created_at,
                    'updated_at' => $booking->updated_at,
                ]);

                DB::table('booking_items')
                    ->where('booking_id', $booking->id)
                    ->where('stylist_id', $stylistId)
                    ->update(['booking_id' => $newId]);

                // Carry the visit's status history onto the split booking.
                foreach (DB::table('booking_status_events')->where('booking_id', $booking->id)->orderBy('id')->get() as $event) {
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
        }

        Schema::dropIfExists('booking_ghl_appointments');
    }

    public function down(): void
    {
        Schema::create('booking_ghl_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stylist_id')->constrained('users')->cascadeOnDelete();
            $table->string('ghl_appointment_id')->nullable();
            $table->string('sync_status')->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'stylist_id']);
            $table->index('ghl_appointment_id');
        });

        // Rebuild slice rows from the 1:1 columns (bookings stay split — the
        // merge is not reversible; this restores the previous storage shape).
        foreach (DB::table('bookings')->whereNotNull('ghl_appointment_id')->get() as $booking) {
            $stylistId = DB::table('booking_items')->where('booking_id', $booking->id)->value('stylist_id');
            if ($stylistId === null) {
                continue;
            }

            DB::table('booking_ghl_appointments')->insert([
                'salon_id' => $booking->salon_id,
                'booking_id' => $booking->id,
                'stylist_id' => $stylistId,
                'ghl_appointment_id' => $booking->ghl_appointment_id,
                'sync_status' => $booking->ghl_sync_status,
                'sync_error' => $booking->ghl_sync_error,
                'payload_hash' => $booking->ghl_payload_hash,
                'last_synced_at' => $booking->last_synced_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['visit_group_id']);
            $table->dropIndex(['ghl_appointment_id']);
            $table->dropColumn(['visit_group_id', 'ghl_appointment_id', 'ghl_sync_status', 'ghl_sync_error', 'ghl_payload_hash', 'last_synced_at']);
        });
    }
};
