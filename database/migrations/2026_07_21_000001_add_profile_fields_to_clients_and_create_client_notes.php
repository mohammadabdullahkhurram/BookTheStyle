<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client profiles (product research: client history/notes/preferences is
     * the top daily-use gap). All preference fields are nullable — existing
     * clients are unaffected. Notes are timestamped rows with an author so
     * "who said the client prefers cooler tones, and when" is answerable.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('allergies')->nullable()->after('email');
            $table->text('formula_notes')->nullable()->after('allergies');
            // Deliberately NO DB-level foreign key: adding one to an existing
            // SQLite table forces a table rebuild whose drop-old-table step
            // cascades deletes into bookings (bookings.client_id is
            // cascadeOnDelete). Stylist validity is enforced in
            // UpdateClientPreferences; a stale id reads as "no preference".
            $table->unsignedBigInteger('preferred_stylist_id')->nullable()->after('formula_notes')->index();
            $table->string('preferred_contact_method', 20)->nullable()->after('preferred_stylist_id');
            $table->date('birthday')->nullable()->after('preferred_contact_method');
        });

        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['salon_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['preferred_stylist_id']);
            $table->dropColumn(['preferred_stylist_id', 'allergies', 'formula_notes', 'preferred_contact_method', 'birthday']);
        });
    }
};
