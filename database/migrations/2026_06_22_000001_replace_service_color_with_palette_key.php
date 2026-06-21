<?php

use App\Support\ServicePalette;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the free-form service `color` hex (manual picker) with a curated
 * palette key. Backfills existing services with distinct auto colours per
 * salon using the same rule as ServicePalette::pick — oldest first, counting
 * only active services for distinctness — so current data gets a clean spread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('color_key', 20)->nullable()->after('duration_min');
        });

        foreach (DB::table('salons')->orderBy('id')->pluck('id') as $salonId) {
            $counts = [];

            $services = DB::table('services')
                ->where('salon_id', $salonId)
                ->orderBy('id')
                ->get(['id', 'active']);

            foreach ($services as $service) {
                $key = ServicePalette::pick($counts);

                DB::table('services')->where('id', $service->id)->update(['color_key' => $key]);

                // Only active services contribute to the distinctness pool, to
                // match the create-time rule.
                if ((bool) $service->active) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('color', 7)->default('#1F6F6B')->after('duration_min');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('color_key');
        });
    }
};
