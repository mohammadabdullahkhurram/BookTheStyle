<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * time_off.type (vacation | sick | blocked) is vestigial: the UI stopped
     * collecting a type and every new row was written as 'blocked', so the
     * column carried no information. The `kind` column (off | hours) is the
     * real discriminator and is untouched. Rolling back restores the column
     * with the historical constant as the default.
     */
    public function up(): void
    {
        Schema::table('time_off', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('time_off', function (Blueprint $table) {
            $table->string('type')->default('blocked');
        });
    }
};
