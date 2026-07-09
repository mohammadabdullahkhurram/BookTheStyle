<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A booking's services can be performed by DIFFERENT stylists, and each
 * stylist needs their own GHL appointment on their own provider slot —
 * otherwise the second stylist looks free in GHL and voice/chat can
 * double-book them. This replaces the single booking-level
 * ghl_appointment_id (+ sync state) with one row per (booking, stylist):
 * its own GHL appointment id, sync status/error, last-synced time, and a
 * payload hash so reschedules only touch appointments that actually
 * changed. These per-stylist ids are what 6c's echo-loop dedupe keys on.
 *
 * Backfills the old single id onto the booking's first stylist (exactly
 * what the previous pusher created), then drops the booking-level columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_ghl_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stylist_id')->constrained('users')->cascadeOnDelete();
            $table->string('ghl_appointment_id')->nullable();
            $table->string('sync_status')->nullable();   // synced | skipped | failed
            $table->string('sync_error', 500)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['booking_id', 'stylist_id']);
            $table->index('ghl_appointment_id'); // 6c webhook lookups
        });

        // Backfill: the old pusher created one appointment assigned to the
        // FIRST item's stylist — attach the stored id (and sync state) there.
        foreach (DB::table('bookings')->whereNotNull('ghl_appointment_id')->get() as $booking) {
            $stylistId = DB::table('booking_items')
                ->where('booking_id', $booking->id)
                ->orderBy('starts_at')
                ->value('stylist_id');

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
                'last_synced_at' => $booking->last_synced_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['ghl_appointment_id', 'ghl_sync_status', 'ghl_sync_error', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('ghl_appointment_id')->nullable();
            $table->string('ghl_sync_status')->nullable();
            $table->string('ghl_sync_error', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
        });

        // Collapse back to one id per booking (the earliest stylist row) —
        // the shape the pre-per-stylist code understood.
        foreach (DB::table('booking_ghl_appointments')->orderBy('id')->get()->groupBy('booking_id') as $bookingId => $rows) {
            $first = $rows->first();

            DB::table('bookings')->where('id', $bookingId)->update([
                'ghl_appointment_id' => $first->ghl_appointment_id,
                'ghl_sync_status' => $first->sync_status,
                'ghl_sync_error' => $first->sync_error,
                'last_synced_at' => $first->last_synced_at,
            ]);
        }

        Schema::dropIfExists('booking_ghl_appointments');
    }
};
