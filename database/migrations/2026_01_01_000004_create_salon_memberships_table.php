<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salon_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('salon_role');
            // The functional staff type (stylist | front_desk | manager).
            $table->string('staff_type')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // A user has at most one membership row per salon.
            $table->unique(['user_id', 'salon_id']);
            $table->index('salon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_memberships');
    }
};
