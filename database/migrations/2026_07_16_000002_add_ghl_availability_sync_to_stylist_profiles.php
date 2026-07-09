<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6e: per-stylist availability mirroring into GoHighLevel. Each mapped
 * stylist gets ONE GHL "user availability schedule" (weekly rules + date
 * overrides, salon timezone) applied to the master calendar; its id and the
 * sync bookkeeping (status / error / payload hash / last success) live here.
 * Additive + nullable — existing rows sync on their first push.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stylist_profiles', function (Blueprint $table) {
            $table->string('ghl_schedule_id')->nullable()->after('ghl_user_id');
            $table->string('ghl_availability_status', 20)->nullable()->after('ghl_schedule_id');
            $table->string('ghl_availability_error', 500)->nullable()->after('ghl_availability_status');
            $table->string('ghl_availability_hash', 64)->nullable()->after('ghl_availability_error');
            $table->timestamp('ghl_availability_synced_at')->nullable()->after('ghl_availability_hash');
        });
    }

    public function down(): void
    {
        Schema::table('stylist_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'ghl_schedule_id',
                'ghl_availability_status',
                'ghl_availability_error',
                'ghl_availability_hash',
                'ghl_availability_synced_at',
            ]);
        });
    }
};
