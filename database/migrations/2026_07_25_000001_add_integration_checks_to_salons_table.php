<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-salon integration check results (Settings → Integrations "Test"/"Verify"
 * buttons + the setup wizard): a JSON map of check key → last outcome
 * (state/message/hint/details/at). Additive and nullable — existing rows are
 * untouched. Never contains tokens or client PII.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->json('integration_checks')->nullable()->after('api_token_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('integration_checks');
        });
    }
};
