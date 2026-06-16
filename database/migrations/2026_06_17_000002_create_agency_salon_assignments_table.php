<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scopes an agency_user to specific salons. Agency owners/admins reach every
     * salon in their agency implicitly, so they do NOT need rows here — this
     * table only constrains agency_users to their assigned sub-accounts.
     */
    public function up(): void
    {
        Schema::create('agency_salon_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'salon_id']);
            $table->index('salon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_salon_assignments');
    }
};
