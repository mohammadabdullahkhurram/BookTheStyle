<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A stylist's weekly recurring availability. Each row is one window on one
     * weekday: kind 'work' (a working window) or 'break' (carved out of work).
     * Multiple work rows per weekday model split shifts. Times are minutes from
     * midnight (0–1440), wall-clock in the salon's timezone — no date, no slot
     * generation (that's Phase 3).
     */
    public function up(): void
    {
        Schema::create('availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // 0 = Monday … 6 = Sunday.
            $table->unsignedTinyInteger('weekday');
            $table->string('kind'); // work | break
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->timestamps();

            $table->index(['salon_id', 'user_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availabilities');
    }
};
