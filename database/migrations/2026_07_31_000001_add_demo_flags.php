<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public demo mode (additive, idempotent). Demo salons are REAL salons under
 * a dedicated demo agency (so existing agency-scoped tenancy keeps them out
 * of every real console/report query by construction), flagged is_demo for
 * the inertness short-circuits (no GHL, no mail, no tokens, no widget) and
 * stamped with an expiry the hourly sweeper hard-deletes past.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('salons', 'is_demo')) {
            Schema::table('salons', function (Blueprint $table) {
                $table->boolean('is_demo')->default(false);
                $table->timestamp('demo_expires_at')->nullable();
            });
        }

        if (! Schema::hasColumn('agencies', 'is_demo')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->boolean('is_demo')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['is_demo', 'demo_expires_at']);
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
