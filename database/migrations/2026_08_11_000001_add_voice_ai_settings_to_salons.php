<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-salon Voice AI prompt settings (the Voice AI Prompts settings tab):
 * one JSON blob holding the team/policies/location/services answers the
 * knowledge-base generator builds articles from. Additive + MySQL-safe:
 * one nullable json column, appended, no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table): void {
            $table->json('voice_ai_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salons', fn (Blueprint $table) => $table->dropColumn('voice_ai_settings'));
    }
};
