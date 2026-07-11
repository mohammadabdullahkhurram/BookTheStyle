<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-salon Booking API token (Stage 2: the app is the booking engine;
     * GHL Voice AI calls our endpoints). Stored HASHED (sha256) — the
     * plaintext is shown exactly once at generation, like the ICS feed
     * tokens. Nullable: a salon without a token simply has no API access.
     */
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->string('api_token_hash', 64)->nullable()->after('feature_flags');
            $table->timestamp('api_token_generated_at')->nullable()->after('api_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['api_token_hash', 'api_token_generated_at']);
        });
    }
};
