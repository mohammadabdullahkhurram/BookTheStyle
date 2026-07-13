<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marble becomes the salon app standard. Existing salons still on the
 * pre-rollout 'default' value move to 'marble' (they never made an explicit
 * choice); the original look survives as the selectable 'classic' theme.
 *
 * Deliberately DATA-ONLY: the column default stays as created and the
 * Salon model's $attributes supplies 'marble' for every new salon —
 * altering the column default would rebuild the table on SQLite, and a
 * rebuild cascade-wipes child rows through the FK constraints (the known
 * SQLite pitfall this repo already hit once).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('salons')->where('app_theme', 'default')->update(['app_theme' => 'marble']);
    }

    public function down(): void
    {
        DB::table('salons')->where('app_theme', 'marble')->update(['app_theme' => 'default']);
    }
};
