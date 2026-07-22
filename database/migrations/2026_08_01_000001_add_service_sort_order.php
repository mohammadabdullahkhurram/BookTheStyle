<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-controlled menu ordering. Every service-listing surface (widget,
 * admin list, booking pickers, filters) orders by sort_order then name, so
 * a salon's menu opens with what THEY lead with — not the alphabet.
 * Additive: existing rows keep the default 0 and fall back to the old
 * name ordering until the owner reorders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropColumn('sort_order');
        });
    }
};
