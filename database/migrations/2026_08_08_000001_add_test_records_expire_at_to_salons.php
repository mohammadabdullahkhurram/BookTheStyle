<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hard TTL for a salon's disposable test records (the health-check /
 * salon-setup set). The records live and die as one unit per salon, so the
 * expiry is ONE per-salon clock: set on create/refresh, cleared on
 * teardown, enforced by the scheduled sweep regardless of anything else.
 * Additive + MySQL-safe: one nullable timestamp, appended, no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table): void {
            $table->timestamp('test_records_expire_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salons', fn (Blueprint $table) => $table->dropColumn('test_records_expire_at'));
    }
};
