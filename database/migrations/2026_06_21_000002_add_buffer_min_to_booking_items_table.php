<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist each booking item's resolved cleanup buffer (minutes). starts_at/
 * ends_at stay the client-facing service block; buffer_min is the extra time the
 * stylist is occupied AFTER it — so the slot engine blocks [start, end+buffer)
 * and the calendar can show a muted tail. Default 0 → existing rows behave
 * exactly as today (additive, backfill-safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('buffer_min')->default(0)->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropColumn('buffer_min');
        });
    }
};
