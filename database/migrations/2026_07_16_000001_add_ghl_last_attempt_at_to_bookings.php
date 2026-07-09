<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the push job last TRIED to sync this booking (success or not) —
 * last_synced_at only moves on success, so together they let the sync-issues
 * view say "failed, last attempted X ago". Additive + nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('ghl_last_attempt_at')->nullable()->after('ghl_last_pushed_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('ghl_last_attempt_at');
        });
    }
};
