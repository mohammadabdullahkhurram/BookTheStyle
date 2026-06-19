<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the Phase-0 scaffolding GHL columns from `salons`. The per-salon GHL
 * connection now lives in its own `salon_ghl_connections` table (created in the
 * preceding migration), so secrets and connection state are isolated rather than
 * piled onto the salon row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['ghl_location_id', 'ghl_token']);
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->string('ghl_location_id')->nullable();
            $table->text('ghl_token')->nullable();
        });
    }
};
