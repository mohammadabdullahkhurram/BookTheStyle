<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Display-only service pricing (product research: missing prices are a
     * demo credibility gap). Integer cents avoid float money bugs; NULL means
     * "price varies" / not stated — existing services stay NULL. Currency is
     * per salon (salons span regions), default USD. No payments anywhere:
     * this is record + display only.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->nullable()->after('duration_min');
        });

        Schema::table('salons', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('price_cents');
        });

        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
