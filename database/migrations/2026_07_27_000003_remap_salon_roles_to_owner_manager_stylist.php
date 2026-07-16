<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Role taxonomy v3 (data only, additive, idempotent): owner · manager ·
 * stylist. Front desk is removed — it was functionally identical to manager.
 *
 * Verified against the REAL enum history and data before writing (the last
 * remap shipped against a guessed value): current valid values on disk are
 * salon_role ∈ {salon_owner, salon_admin, staff} — 'salon_owner' rows were
 * confirmed live in production, plus rows the 2026_07_27_000001 corrective
 * sweep normalised — and staff_type ∈ {stylist, front_desk, manager, NULL}.
 *
 * Mapping (roles first — they read staff_type; then types):
 *   salon_role 'salon_admin'                       → 'salon_manager'
 *   anything else invalid + type manager/front_desk → 'salon_manager'
 *   anything else invalid ('staff', stylist/NULL/…) → 'stylist'
 *   staff_type := 'stylist' where role is stylist   (stylist = bookable)
 *   staff_type := NULL for every non-'stylist' type (labels died with the
 *     roles; an OWNER's 'stylist' type SURVIVES — bookable owners keep
 *     taking bookings)
 *
 * Ends with a loud assertion (the 000001 lesson): throws rather than
 * reporting success over stranded data. Safe to re-run — every statement
 * matches nothing on a converged DB.
 */
return new class extends Migration
{
    private const ROLES = ['salon_owner', 'salon_manager', 'stylist'];

    public function up(): void
    {
        DB::table('salon_memberships')
            ->where('salon_role', 'salon_admin')
            ->update(['salon_role' => 'salon_manager']);

        DB::table('salon_memberships')
            ->whereNotIn('salon_role', self::ROLES)
            ->whereIn('staff_type', ['manager', 'front_desk'])
            ->update(['salon_role' => 'salon_manager']);

        DB::table('salon_memberships')
            ->whereNotIn('salon_role', self::ROLES)
            ->update(['salon_role' => 'stylist']);

        // Bookability flag: every stylist-role member carries it…
        DB::table('salon_memberships')
            ->where('salon_role', 'stylist')
            ->whereNull('staff_type')
            ->update(['staff_type' => 'stylist']);

        // …and the former functional labels die. Owner + 'stylist' survives.
        DB::table('salon_memberships')
            ->whereNotNull('staff_type')
            ->where('staff_type', '!=', 'stylist')
            ->update(['staff_type' => null]);

        $this->assertNothingStranded();
    }

    public function down(): void
    {
        // Best effort: managers back to the admin role, stylist role back to
        // 'staff'. The manager/front_desk TYPE labels are unrecoverable.
        DB::table('salon_memberships')
            ->where('salon_role', 'salon_manager')
            ->update(['salon_role' => 'salon_admin']);

        DB::table('salon_memberships')
            ->where('salon_role', 'stylist')
            ->update(['salon_role' => 'staff']);
    }

    private function assertNothingStranded(): void
    {
        $strandedRoles = DB::table('salon_memberships')
            ->whereNotIn('salon_role', self::ROLES)->distinct()->pluck('salon_role');
        $strandedTypes = DB::table('salon_memberships')
            ->whereNotNull('staff_type')->where('staff_type', '!=', 'stylist')
            ->distinct()->pluck('staff_type');

        if ($strandedRoles->isNotEmpty() || $strandedTypes->isNotEmpty()) {
            throw new RuntimeException(
                'Role remap left stranded values — salon_role: ['.$strandedRoles->implode(', ')
                .'] staff_type: ['.$strandedTypes->implode(', ').']'
            );
        }
    }
};
