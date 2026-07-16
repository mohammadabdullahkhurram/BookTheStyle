<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users soft-delete. Hard deletes are off the table: booking_items.stylist_id
 * cascades on user delete, so a hard delete destroys booking history outright
 * (and availabilities, GHL mappings, memberships with it). deleted_at keeps
 * every FK intact while the global scope removes the user from login, staff
 * lists, and bookable-stylist queries; history relations opt back in with
 * withTrashed().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
