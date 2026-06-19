<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-salon GoHighLevel connection credentials (one-to-one with a salon).
 *
 * Kept in a dedicated table rather than columns on `salons` so the secret (the
 * Private Integration Token) and connection state are isolated. All fields are
 * optional: a salon can be created without them and connected later (Phase 6
 * builds the actual sync; this is groundwork — storage + UI only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_ghl_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('location_id')->nullable();          // GHL sub-account/location id
            $table->text('private_integration_token')->nullable(); // encrypted at rest (model cast)
            $table->string('calendar_id')->nullable();          // the salon's master GHL calendar id
            $table->timestamp('connected_at')->nullable();      // set when a token is first saved
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_ghl_connections');
    }
};
