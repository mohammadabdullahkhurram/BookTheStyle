<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone')->default('UTC');
            // Per-salon branding (accent override, logo, display name).
            $table->json('branding')->nullable();

            // GoHighLevel connection — populated in a later phase. The token is
            // encrypted at rest via the model's 'encrypted' cast; never plaintext.
            $table->string('ghl_location_id')->nullable();
            $table->text('ghl_token')->nullable();

            // Booking policy (Section 4 of SPEC).
            $table->boolean('allow_walkins')->default(true);
            $table->boolean('allow_same_day')->default(true);
            $table->unsignedSmallInteger('max_advance_days')->default(90);
            $table->unsignedInteger('min_notice_minutes')->default(0);

            // Per-salon feature toggles so salons can diverge over time.
            $table->json('feature_flags')->nullable();

            $table->timestamps();

            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salons');
    }
};
