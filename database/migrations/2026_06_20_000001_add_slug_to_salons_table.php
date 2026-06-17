<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Added nullable first so existing rows can be backfilled before the
        // unique index is applied. Presence is enforced at the application
        // layer (slug is required on create/edit and always set by the seeder).
        Schema::table('salons', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // Backfill any pre-existing salons. The two seeded demo salons get the
        // documented slugs; everything else derives a unique slug from its name.
        $used = [];
        foreach (DB::table('salons')->orderBy('id')->get() as $salon) {
            $slug = match ($salon->name) {
                'Demo Salon' => 'demo',
                'Other Salon' => 'other',
                default => Str::slug($salon->name) ?: 'salon',
            };

            // Guarantee uniqueness for derived slugs.
            $candidate = $slug;
            $n = 1;
            while (in_array($candidate, $used, true)) {
                $candidate = $slug.'-'.(++$n);
            }
            $used[] = $candidate;

            DB::table('salons')->where('id', $salon->id)->update(['slug' => $candidate]);
        }

        Schema::table('salons', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
