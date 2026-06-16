<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Agency a user belongs to (agency staff). Salon membership is
            // tracked separately in salon_memberships. Nullable: a user may be
            // attached to a salon without being agency staff.
            $table->foreignId('agency_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->string('agency_role')->nullable()->after('email');
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
            $table->dropColumn(['agency_role', 'must_change_password']);
        });
    }
};
