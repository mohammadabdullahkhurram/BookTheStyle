<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which stylists perform which services (SPEC ServiceStylist). The pivot is
     * reached only through a salon-scoped Service, so it inherits tenant
     * isolation without its own salon_id; assignment validates the stylist is a
     * member of the service's salon.
     */
    public function up(): void
    {
        Schema::create('service_stylist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_stylist');
    }
};
