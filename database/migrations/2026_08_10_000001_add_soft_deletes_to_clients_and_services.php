<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solo deletes: removing a client or service must KEEP the appointments
 * that reference it. The FK-integrity option chosen is the tombstone
 * (soft delete): the row stays as the name snapshot the kept appointments
 * render, while SoftDeletes' global scope hides it from every directory,
 * picker, widget and count automatically. Chosen over ON DELETE SET NULL
 * deliberately — switching those FKs would ALTER bookings/booking_items,
 * and altering a cascade-referenced table rebuilds it on SQLite, firing
 * the children's cascadeOnDelete (the recorded data-wipe pitfall).
 * Additive + MySQL-safe: two nullable timestamp columns, nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->softDeletes());
        Schema::table('services', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('services', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
