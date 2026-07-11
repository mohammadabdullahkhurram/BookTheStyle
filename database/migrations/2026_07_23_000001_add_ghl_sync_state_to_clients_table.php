<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client↔GHL-contact sync state, mirroring the booking sync's columns:
     * the hash of the basic fields we last pushed (echo suppression), when,
     * and a per-client status + error for surfacing failures. Additive; all
     * nullable — existing clients simply have no sync state yet.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('ghl_pushed_hash', 64)->nullable()->after('ghl_contact_id');
            $table->timestamp('ghl_pushed_at')->nullable()->after('ghl_pushed_hash');
            $table->string('ghl_sync_status', 20)->nullable()->after('ghl_pushed_at');
            $table->string('ghl_sync_error', 500)->nullable()->after('ghl_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['ghl_pushed_hash', 'ghl_pushed_at', 'ghl_sync_status', 'ghl_sync_error']);
        });
    }
};
