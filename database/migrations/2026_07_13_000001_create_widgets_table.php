<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A salon can run MULTIPLE embeddable booking widgets (one per website /
 * location), each fully independent: its own name, its own branding JSON
 * (colors/logo/font — same shape as salons.branding), its own theme key,
 * and its own public id for the embed. The salon's existing single widget
 * becomes the first row (branding copied over — no data loss); the salon
 * also gets an app_theme (Settings → Branding picks it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The embed identifier — public, unguessable-enough, never secret.
            $table->string('public_id', 24)->unique();
            $table->json('branding')->nullable();
            $table->string('theme', 40)->default('marble');
            $table->timestamps();

            $table->index('salon_id');
        });

        Schema::table('salons', function (Blueprint $table) {
            $table->string('app_theme', 40)->default('default');
        });

        // Migrate the existing single widget: one row per salon. Branding
        // stays NULL — a widget with no branding of its own INHERITS the
        // salon's live (WidgetBranding merges salon → widget), so existing
        // embeds render exactly as before and keep following Settings →
        // Branding until the widget gets its own overrides.
        foreach (DB::table('salons')->get(['id']) as $salon) {
            DB::table('widgets')->insert([
                'salon_id' => $salon->id,
                'name' => 'Booking widget',
                'public_id' => Str::lower(Str::random(20)),
                'branding' => null,
                'theme' => 'marble',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('app_theme');
        });
        Schema::dropIfExists('widgets');
    }
};
