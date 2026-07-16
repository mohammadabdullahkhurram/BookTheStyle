<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Role taxonomy remap (data only, additive-safe, no schema change): the old
 * member role 'user' splits by staff TYPE, because the role now carries the
 * permissions and types map to roles —
 *
 *   'user' + staff_type manager/front_desk → 'salon_admin'  (full salon admin)
 *   'user' + staff_type stylist (or none)  → 'staff'        (bookable, own-scope)
 *
 * salon_owner / salon_admin rows are untouched. Order matters: promote the
 * manager/front-desk rows FIRST, then rename the remainder. Reversible with
 * the same split (a promoted row is identifiable by its staff type).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('salon_memberships')
            ->where('salon_role', 'user')
            ->whereIn('staff_type', ['manager', 'front_desk'])
            ->update(['salon_role' => 'salon_admin']);

        DB::table('salon_memberships')
            ->where('salon_role', 'user')
            ->update(['salon_role' => 'staff']);
    }

    public function down(): void
    {
        DB::table('salon_memberships')
            ->where('salon_role', 'salon_admin')
            ->whereIn('staff_type', ['manager', 'front_desk'])
            ->update(['salon_role' => 'user']);

        DB::table('salon_memberships')
            ->where('salon_role', 'staff')
            ->update(['salon_role' => 'user']);
    }
};
