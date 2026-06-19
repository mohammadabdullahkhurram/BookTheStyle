<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-salon business + point-of-contact profile, mirroring how a GoHighLevel
 * sub-account/location is set up (so these map straight across in Phase 6).
 *
 * The existing `salons.name` is the business / trading name (= the GHL
 * sub-account name); these columns add the rest as discrete, GHL-aligned fields
 * (not one address blob). Required columns are NOT NULL with a '' default so the
 * already-seeded rows don't break; existing rows are backfilled (legal name ←
 * trading name) and the seeder repopulates real values. App-level validation
 * keeps them genuinely required on create/edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            // Business identity (name = trading name already exists).
            $table->string('legal_business_name')->default('')->after('name');
            $table->string('business_email')->default('')->after('legal_business_name');
            $table->string('business_phone')->default('')->after('business_email');
            $table->string('website')->nullable()->after('business_phone');

            // Address — international, discrete fields (free-text region/country).
            $table->string('address_line1')->default('')->after('website');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city')->default('')->after('address_line2');
            $table->string('region')->default('')->after('city');
            $table->string('postal_code')->default('')->after('region');
            $table->string('country')->default('')->after('postal_code');

            // Primary contact / admin point-of-contact (distinct from business).
            $table->string('contact_name')->default('')->after('country');
            $table->string('contact_email')->default('')->after('contact_name');
            $table->string('contact_phone')->default('')->after('contact_email');
        });

        // Backfill existing rows with a sensible starting point so the not-null
        // columns hold real-ish data; the seeder overwrites demo/other salons.
        DB::table('salons')->where('legal_business_name', '')->update([
            'legal_business_name' => DB::raw('name'),
        ]);
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn([
                'legal_business_name', 'business_email', 'business_phone', 'website',
                'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country',
                'contact_name', 'contact_email', 'contact_phone',
            ]);
        });
    }
};
