<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second tier of the GHL staff mapping: non-stylist staff (front desk,
 * managers, owners, admins) link to a GHL LOCATION USER for identity and
 * attribution only. Stylists keep their separate calendar-provider mapping
 * on stylist_profiles.ghl_user_id — that one routes booking pushes (6b);
 * this one never does.
 *
 * Additive + nullable: existing memberships stay unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salon_memberships', function (Blueprint $table) {
            $table->string('ghl_location_user_id')->nullable()->after('staff_type');
        });
    }

    public function down(): void
    {
        Schema::table('salon_memberships', function (Blueprint $table) {
            $table->dropColumn('ghl_location_user_id');
        });
    }
};
