<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per health-check run (manual page run or the scheduled monitor):
 * when, which source, the pass/warn/fail counts, every check's outcome, and
 * any green→red regressions detected against the previous run. Powers the
 * "last checked / history" view and the alert trail. Additive + MySQL-safe;
 * rows prune after the retention window (model's MassPrunable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_check_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('source'); // manual | scheduled
            $table->unsignedSmallInteger('pass_count')->default(0);
            $table->unsignedSmallInteger('warn_count')->default(0);
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->json('results'); // check key → {label, status, message}
            $table->json('regressions')->nullable(); // checks that flipped to fail this run
            $table->timestamps();

            $table->index(['salon_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_check_runs');
    }
};
