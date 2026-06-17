<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Duration in minutes. No price — scheduling only (SPEC §2).
            $table->unsignedSmallInteger('duration_min');
            // Hex color used to colour-code the service on the calendar (Phase 4).
            $table->string('color', 7)->default('#1F6F6B');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('salon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
