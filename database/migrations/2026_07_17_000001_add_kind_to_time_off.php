<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A date-specific entry is now one of two kinds:
 *
 * - 'off'   — an UNAVAILABLE stretch (every pre-existing row: time off).
 * - 'hours' — the AVAILABLE hours for that date, replacing the weekly
 *             schedule (a date-specific override, like GHL's).
 *
 * Additive + backfill-safe: the default backfills every existing row as
 * 'off', which is exactly what they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_off', function (Blueprint $table) {
            $table->string('kind', 10)->default('off')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('time_off', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
