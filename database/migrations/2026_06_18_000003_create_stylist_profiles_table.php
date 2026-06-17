<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lightweight per-(user, salon) stylist record. Holds a bio for now;
     * ics_feed_token (Phase 5) and ghl_calendar_id (Phase 6) are intentionally
     * NOT added yet.
     */
    public function up(): void
    {
        Schema::create('stylist_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('bio')->nullable();
            $table->timestamps();

            $table->unique(['salon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_profiles');
    }
};
