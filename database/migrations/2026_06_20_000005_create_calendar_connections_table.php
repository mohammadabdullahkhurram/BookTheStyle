<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user personal calendar feed (Phase 5). Each user may hold one revocable,
 * high-entropy ICS feed token. Only the SHA-256 hash is stored — the feed is
 * looked up by hashing the presented token, so a database leak never exposes a
 * working subscribe URL. `token_hash` null means no/revoked feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_connections');
    }
};
