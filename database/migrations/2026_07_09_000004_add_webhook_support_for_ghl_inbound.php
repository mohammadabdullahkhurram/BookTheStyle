<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6c inbound channel:
 *
 * - salon_ghl_connections.webhook_secret — per-salon shared secret the GHL
 *   workflow sends back in the X-Webhook-Secret header; encrypted at rest
 *   like the integration token. Inbound calls that do not carry the right
 *   secret for the payload's location are rejected.
 * - webhook_events — the inbound event log (SPEC §4 WebhookEvent): raw
 *   payload + hash for replay dedupe, processing outcome, and a review flag
 *   for events that could not be applied cleanly. salon_id is nullable so
 *   even unresolvable payloads leave an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salon_ghl_connections', function (Blueprint $table) {
            $table->text('webhook_secret')->nullable()->after('private_integration_token');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type')->nullable();
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->string('status')->default('pending'); // pending|applied|created_booking|ignored_echo|ignored_stale|review|error
            $table->string('note', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['salon_id', 'payload_hash']); // replay lookups
            $table->index(['salon_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');

        Schema::table('salon_ghl_connections', function (Blueprint $table) {
            $table->dropColumn('webhook_secret');
        });
    }
};
