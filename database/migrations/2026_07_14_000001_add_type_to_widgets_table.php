<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widgets get a TYPE (WidgetTypeRegistry): booking today, chat/lead
 * form/reviews later. Additive and backfill-safe — the column default makes
 * every existing widget a booking widget, which is exactly what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->string('type', 40)->default('booking')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('widgets', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
