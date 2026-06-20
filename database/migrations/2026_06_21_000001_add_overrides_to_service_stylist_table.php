<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-stylist-per-service duration + buffer overrides on the service_stylist
 * pivot. Both nullable: null duration → use the service default; null buffer →
 * 0 (no buffer). Additive + backfill-safe — existing rows get null overrides and
 * behave exactly as today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_stylist', function (Blueprint $table) {
            // This stylist's time for this service (minutes); null = service default.
            $table->unsignedSmallInteger('duration_override')->nullable()->after('user_id');
            // Cleanup/turnaround after the appointment (minutes); null = 0.
            $table->unsignedSmallInteger('buffer_override')->nullable()->after('duration_override');
        });
    }

    public function down(): void
    {
        Schema::table('service_stylist', function (Blueprint $table) {
            $table->dropColumn(['duration_override', 'buffer_override']);
        });
    }
};
