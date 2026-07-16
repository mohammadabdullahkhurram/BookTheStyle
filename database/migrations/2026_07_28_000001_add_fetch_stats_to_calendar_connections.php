<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence-of-use for the personal ICS feed (additive, nullable). The feed is
 * pull-based — calendar apps fetch on their own schedule and the app cannot
 * probe them — so the only honest "connection status" is proof a client
 * actually fetched: which app (parsed from the User-Agent) and a sampled
 * fetch count. The WHEN reuses the existing last_used_at column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_connections', function (Blueprint $table) {
            $table->string('last_client', 40)->nullable();
            $table->unsignedInteger('fetch_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('calendar_connections', function (Blueprint $table) {
            $table->dropColumn(['last_client', 'fetch_count']);
        });
    }
};
