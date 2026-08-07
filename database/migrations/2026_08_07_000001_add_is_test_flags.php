<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Disposable connection-check records: the "Check connections" diagnostics
 * creates a test stylist / service / client per salon, all flagged is_test
 * so every client-facing surface can exclude them (the demo-showcase
 * pattern, per-record). Additive + MySQL-safe: three nullable-free boolean
 * columns with a false default, appended (no ->after()), no data changes —
 * every existing row simply reads false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false);
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false);
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('is_test')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_test'));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('is_test'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('is_test'));
    }
};
