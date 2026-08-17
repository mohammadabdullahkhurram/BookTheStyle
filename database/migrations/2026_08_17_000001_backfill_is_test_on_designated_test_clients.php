<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: designated test clients that exist WITHOUT the is_test flag.
 *
 * The two designated test clients ("Bluejaypro Test Client" and
 * "Bluejaypro Voice AI Test Client") are booked by NAME/PHONE through the
 * shared engine (voice Custom Actions, the health check), and a
 * re-creation that raced a sweep minted the row through the generic
 * client-creation path — UNFLAGGED. Such rows show no TEST badge and are
 * invisible to every is_test-keyed teardown/sweep, which is exactly the
 * "cleanup leaves the test clients behind" bug. The creation funnel now
 * flags designated identities at the source; this backfill repairs the
 * rows already in production.
 *
 * Additive data-only UPDATE (no schema change), idempotent, MySQL-safe.
 * The names and numbers are reserved by ConnectionDiagnostics and only
 * ever minted by our own test lanes — no real client can carry them. Both
 * phone spellings are matched (as our code stores them, and as a wire
 * round-trip may have normalised them).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->where(fn ($q) => $q
                ->whereIn('name', ['Bluejaypro Test Client', 'Bluejaypro Voice AI Test Client'])
                ->orWhereIn('phone', ['+1 555 010 0000', '+15550100000', '+1 555 010 0001', '+15550100001']))
            ->where('is_test', false)
            ->update(['is_test' => true]);
    }

    public function down(): void
    {
        // Data backfill — never reversed (unflagging test rows would
        // re-introduce the leak this migration repairs).
    }
};
