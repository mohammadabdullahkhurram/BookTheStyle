<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6a additions, both nullable so existing salons are untouched and stay
 * "not connected" / unmapped by default:
 *
 * - salon_ghl_connections.last_verified_at — when "Test connection" last
 *   confirmed the token + location against the GHL API.
 * - stylist_profiles.ghl_user_id — the GHL team-member (user) this stylist maps
 *   to on the salon's master GHL calendar; 6b routes appointment pushes with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salon_ghl_connections', function (Blueprint $table) {
            $table->timestamp('last_verified_at')->nullable()->after('connected_at');
        });

        Schema::table('stylist_profiles', function (Blueprint $table) {
            $table->string('ghl_user_id')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('salon_ghl_connections', function (Blueprint $table) {
            $table->dropColumn('last_verified_at');
        });

        Schema::table('stylist_profiles', function (Blueprint $table) {
            $table->dropColumn('ghl_user_id');
        });
    }
};
