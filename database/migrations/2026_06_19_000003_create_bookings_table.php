<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('booked');
            $table->string('booked_by_type');
            $table->foreignId('booked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source')->default('in_app');
            $table->boolean('is_walkin')->default(false);
            $table->text('notes')->nullable();
            // Reserved for Phase 6 (GHL appointment mapping / echo-loop dedupe).
            $table->string('ghl_appointment_id')->nullable();
            $table->timestamps();

            $table->index(['salon_id', 'status']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
