<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrective sweep for role values stranded outside their enums (data only,
 * additive, idempotent — every statement matches nothing on a healthy DB).
 *
 * Production 500'd with «"user" is not a valid backing value for enum
 * SalonRole»: real memberships were still on the legacy 'user' role after
 * the taxonomy rework shipped. The remap migration (2026_07_26) targeted
 * 'user' correctly, but nothing ever verified it EXECUTED against the data —
 * a data migration that matches zero rows reports success identically to one
 * that fixed everything. This migration therefore (a) maps EVERY legacy or
 * unknown value, not just the ones we remember, and (b) ends by asserting
 * loudly that no invalid value remains anywhere — so this class of failure
 * can never again ship silently.
 *
 * Mapping (least privilege wherever intent is unknowable):
 *   staff_type: 'front-desk' (hyphen variant) → 'front_desk'; any other
 *     unknown non-null value → NULL (no staff function; grants nothing).
 *   salon_role aliases: 'owner' → 'salon_owner', 'admin' → 'salon_admin'.
 *   any remaining invalid salon_role ('user', 'member', anything) splits by
 *     staff type — manager/front_desk → 'salon_admin', everything else
 *     (stylist, NULL, unknown) → 'staff'.
 *   users.agency_role aliases: 'owner'/'admin'/'user' → the 'agency_'-
 *     prefixed value; any remaining invalid non-null value → NULL (no agency
 *     role at all — a support call, never an accidental privilege grant).
 *
 * down() is intentionally a no-op: the original invalid values are garbage
 * whose exact prior form is unknowable, and restoring them would re-break
 * the app.
 */
return new class extends Migration
{
    private const SALON_ROLES = ['salon_owner', 'salon_admin', 'staff'];

    private const STAFF_TYPES = ['stylist', 'front_desk', 'manager'];

    private const AGENCY_ROLES = ['agency_owner', 'agency_admin', 'agency_user'];

    public function up(): void
    {
        // Staff type first — the role split below keys off it.
        DB::table('salon_memberships')
            ->where('staff_type', 'front-desk')
            ->update(['staff_type' => 'front_desk']);
        DB::table('salon_memberships')
            ->whereNotNull('staff_type')
            ->whereNotIn('staff_type', self::STAFF_TYPES)
            ->update(['staff_type' => null]);

        // Explicit salon-role aliases (unambiguous intent)…
        DB::table('salon_memberships')
            ->where('salon_role', 'owner')
            ->update(['salon_role' => 'salon_owner']);
        DB::table('salon_memberships')
            ->where('salon_role', 'admin')
            ->update(['salon_role' => 'salon_admin']);

        // …then EVERYTHING still invalid splits by staff type.
        DB::table('salon_memberships')
            ->whereNotIn('salon_role', self::SALON_ROLES)
            ->whereIn('staff_type', ['manager', 'front_desk'])
            ->update(['salon_role' => 'salon_admin']);
        DB::table('salon_memberships')
            ->whereNotIn('salon_role', self::SALON_ROLES)
            ->update(['salon_role' => 'staff']);

        // Agency roles: aliases, then unknown → NULL (least privilege).
        foreach (['owner' => 'agency_owner', 'admin' => 'agency_admin', 'user' => 'agency_user'] as $legacy => $valid) {
            DB::table('users')->where('agency_role', $legacy)->update(['agency_role' => $valid]);
        }
        DB::table('users')
            ->whereNotNull('agency_role')
            ->whereNotIn('agency_role', self::AGENCY_ROLES)
            ->update(['agency_role' => null]);

        // Fail LOUDLY if anything is still stranded — a data migration that
        // cannot prove its outcome is the defect that caused the outage.
        $this->assertNothingStranded();
    }

    public function down(): void
    {
        // Intentionally empty — see the class docblock.
    }

    private function assertNothingStranded(): void
    {
        $stranded = [
            'salon_memberships.salon_role' => DB::table('salon_memberships')
                ->whereNotIn('salon_role', self::SALON_ROLES)->distinct()->pluck('salon_role'),
            'salon_memberships.staff_type' => DB::table('salon_memberships')
                ->whereNotNull('staff_type')->whereNotIn('staff_type', self::STAFF_TYPES)->distinct()->pluck('staff_type'),
            'users.agency_role' => DB::table('users')
                ->whereNotNull('agency_role')->whereNotIn('agency_role', self::AGENCY_ROLES)->distinct()->pluck('agency_role'),
        ];

        foreach ($stranded as $column => $values) {
            if ($values->isNotEmpty()) {
                throw new RuntimeException(
                    "Migration left {$column} holding value(s) its enum rejects: ".$values->implode(', ')
                );
            }
        }
    }
};
