<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Salon onboarding wizard state. Most step completion is COMPUTED from
     * live data (stylists exist, connection verified, availability synced…);
     * this column persists only what cannot be derived: the current step
     * pointer (resume) and self-attestations for steps that happen inside
     * GHL's own UI (webhook workflow, voice-AI custom actions). onboarded_at
     * marks the salon as live — set once every required step is complete.
     */
    public function up(): void
    {
        // No ->after(): it referenced api_token_generated_at, a column the
        // 2026_07_22 migration creates TEN DAYS LATER in the sequence. SQLite
        // (dev/CI) ignores column position so it slipped through; MySQL
        // (production) rejects it. Position is cosmetic — append the columns.
        Schema::table('salons', function (Blueprint $table) {
            $table->json('onboarding')->nullable();
            $table->timestamp('onboarded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['onboarding', 'onboarded_at']);
        });
    }
};
