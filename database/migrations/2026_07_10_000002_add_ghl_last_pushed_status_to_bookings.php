<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GHL status the app most recently pushed (or accepted inbound) for a
 * booking — the "last known GHL state". Outbound pushes record what they
 * sent the moment they send it, so the echoing webhook is recognisable as
 * our own even when the booking has since moved on locally, and stale
 * timestamp-less workflow events can be told apart from genuine changes.
 * Additive + nullable; existing rows self-correct on their next push or
 * via ghl:repair-sync-state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('ghl_last_pushed_status', 20)->nullable()->after('ghl_payload_hash');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('ghl_last_pushed_status');
        });
    }
};
