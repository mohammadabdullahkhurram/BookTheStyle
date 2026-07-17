<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Salon types (employee · booth_rental · mix) + the per-membership stylist
 * arrangement (employee · booth_rental). Additive and idempotent: both
 * columns default to 'employee', which IS today's behavior — existing salons
 * and memberships change nothing. The explicit backfill UPDATEs are belt and
 * braces for drivers that leave pre-existing rows NULL, and match nothing on
 * re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salons', 'salon_type')) {
            Schema::table('salons', function (Blueprint $table) {
                $table->string('salon_type', 20)->default('employee');
            });
        }

        if (! Schema::hasColumn('salon_memberships', 'arrangement')) {
            Schema::table('salon_memberships', function (Blueprint $table) {
                $table->string('arrangement', 20)->default('employee');
            });
        }

        DB::table('salons')->whereNull('salon_type')->update(['salon_type' => 'employee']);
        DB::table('salon_memberships')->whereNull('arrangement')->update(['arrangement' => 'employee']);
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('salon_type');
        });
        Schema::table('salon_memberships', function (Blueprint $table) {
            $table->dropColumn('arrangement');
        });
    }
};
