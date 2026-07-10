<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-salon booking automation (audit + product research: auto-no-show was
     * too aggressive as an always-on behavior). Auto-no-show becomes OPT-IN —
     * existing salons get it OFF, deliberately flipping the old always-on
     * default; staff can always mark no-shows manually. Auto-complete keeps
     * the historical behavior (ON) but becomes a toggle.
     */
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->boolean('auto_no_show')->default(false)->after('min_notice_minutes');
            $table->unsignedSmallInteger('auto_no_show_grace_minutes')->default(15)->after('auto_no_show');
            $table->boolean('auto_complete')->default(true)->after('auto_no_show_grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['auto_no_show', 'auto_no_show_grace_minutes', 'auto_complete']);
        });
    }
};
