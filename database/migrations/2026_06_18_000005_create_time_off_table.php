<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-off time off that overrides the weekly availability for a stylist
     * (vacation, sick, blocked). Absolute datetime ranges in the salon's
     * timezone.
     */
    public function up(): void
    {
        Schema::create('time_off', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // vacation | sick | blocked
            $table->string('note')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();

            $table->index(['salon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_off');
    }
};
