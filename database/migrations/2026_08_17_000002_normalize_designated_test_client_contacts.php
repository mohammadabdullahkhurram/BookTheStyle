<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: fixed, permanently non-deliverable contact details for the two
 * designated test clients — every salon. `.invalid` is an IETF-reserved
 * TLD that can never resolve, so no test booking confirmation can ever
 * reach a real inbox; the two phones stay distinct (…0000 vs …0001) so
 * phone-based booking/cancel/reschedule lookups never cross wires.
 *
 * Matched strictly by the reserved designated NAMES (minted only by our
 * own test lanes — no real client can carry them), scoped per row's own
 * salon by nature of the update. Existing rows may hold old per-salon
 * generated addresses (test-client+{id}@…) or none at all; both normalize
 * to the canonical values ConnectionDiagnostics now uses at creation.
 *
 * Additive data-only UPDATE (no schema change), idempotent, MySQL-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clients')
            ->where('name', 'Bluejaypro Test Client')
            ->update(['email' => 'bjptestclient@bluejaypro.invalid', 'phone' => '+1 555 010 0000']);

        DB::table('clients')
            ->where('name', 'Bluejaypro Voice AI Test Client')
            ->update(['email' => 'bjpvoiceaitestclient@bluejaypro.invalid', 'phone' => '+1 555 010 0001']);
    }

    public function down(): void
    {
        // Data backfill — never reversed (the old values were per-salon
        // generated or missing; there is nothing meaningful to restore).
    }
};
