<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b sync state. ghl_appointment_id (bookings) and ghl_contact_id
 * (clients) have existed since Phase 3; this adds the per-booking outcome of
 * the queued GHL push so failures are visible instead of silent:
 *
 * - ghl_sync_status: null (never attempted) | synced | skipped | failed
 * - ghl_sync_error:  the safe, token-free message behind a failed/skipped push
 * - last_synced_at:  when the push last succeeded (SPEC §4; 6c echo-loop aid)
 *
 * All nullable — existing bookings are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('ghl_sync_status')->nullable()->after('ghl_appointment_id');
            $table->string('ghl_sync_error', 500)->nullable()->after('ghl_sync_status');
            $table->timestamp('last_synced_at')->nullable()->after('ghl_sync_error');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['ghl_sync_status', 'ghl_sync_error', 'last_synced_at']);
        });
    }
};
