<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-salon feature flags were phased-development scaffolding; every
 * feature they gated is now built and permanently ON for all salons
 * (per-stylist cleanup buffers ungated; online booking / voice AI were
 * never actually read; chat widget was never built). The Features tab,
 * catalogue, and reads are gone — this drops the now-unused column.
 *
 * Safe single-column drop (native ALTER on MySQL and modern SQLite — no
 * table rebuild, no cascade risk; never migrate:fresh, CLAUDE.md rule 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('feature_flags');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->json('feature_flags')->nullable();
        });
    }
};
