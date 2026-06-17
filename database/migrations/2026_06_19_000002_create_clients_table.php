<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            // Reserved for Phase 6 (GHL contact mapping).
            $table->string('ghl_contact_id')->nullable();
            $table->timestamps();

            $table->index('salon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
