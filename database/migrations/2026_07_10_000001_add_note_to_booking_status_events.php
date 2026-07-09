<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-text note on a status event, used by reschedules to record
 * "Rescheduled from X to Y" in the booking's timeline. Additive + nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_status_events', function (Blueprint $table) {
            $table->string('note')->nullable()->after('to_status');
        });
    }

    public function down(): void
    {
        Schema::table('booking_status_events', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
