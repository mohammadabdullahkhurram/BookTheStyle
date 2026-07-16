<?php

use App\Enums\AgencyRole;
use App\Enums\AvailabilityKind;
use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Data stranded on a value its enum no longer accepts 500s every page that
| touches it — and a remap migration that matches zero rows reports success
| identically to one that worked (the production outage). Two defenses:
| the corrective migration asserts its own outcome at run time, and this
| suite (a) proves the mapping against every known legacy shape and (b)
| keeps a standing scan of EVERY enum-backed column.
*/

/** Every enum-backed column in the schema → its accepted values. */
function enumBackedColumns(): array
{
    $values = fn (string $enum) => array_column($enum::cases(), 'value');

    return [
        ['salon_memberships', 'salon_role', $values(SalonRole::class), 'not-nullable'],
        ['salon_memberships', 'staff_type', $values(StaffType::class), 'nullable'],
        ['users', 'agency_role', $values(AgencyRole::class), 'nullable'],
        ['bookings', 'status', $values(BookingStatus::class), 'not-nullable'],
        ['bookings', 'source', $values(BookingSource::class), 'not-nullable'],
        ['bookings', 'booked_by_type', $values(BookedByType::class), 'not-nullable'],
        ['booking_status_events', 'from_status', $values(BookingStatus::class), 'nullable'],
        ['booking_status_events', 'to_status', $values(BookingStatus::class), 'not-nullable'],
        ['availabilities', 'kind', $values(AvailabilityKind::class), 'not-nullable'],
    ];
}

function assertNoStrandedEnumValues(): void
{
    foreach (enumBackedColumns() as [$table, $column, $valid, $nullability]) {
        $query = DB::table($table)->whereNotIn($column, $valid);

        if ($nullability === 'nullable') {
            $query->whereNotNull($column);
        }

        $stranded = $query->distinct()->pluck($column);

        expect($stranded->all())->toBe(
            [],
            "{$table}.{$column} holds value(s) its enum rejects: ".$stranded->implode(', ')
        );
    }
}

function runLegacyRoleFix(): void
{
    // The full corrective chain, in migration order: the stranded-value
    // sweep (000001), then the owner/manager/stylist taxonomy remap (000003).
    foreach ([
        'migrations/2026_07_27_000001_fix_legacy_enum_role_values.php',
        'migrations/2026_07_27_000003_remap_salon_roles_to_owner_manager_stylist.php',
    ] as $file) {
        $migration = require database_path($file);
        $migration->up();
    }
}

it('holds no value any enum rejects (standing scan)', function () {
    assertNoStrandedEnumValues();
});

it('maps every legacy role shape, splitting by staff type, and re-runs as a no-op', function () {
    $salon = Salon::factory()->create();

    // Raw inserts — the legacy shapes the enum casts would refuse to write.
    $rows = [
        // The production shape: old 'user' role, split by type.
        'user+stylist' => ['salon_role' => 'user', 'staff_type' => 'stylist'],
        'user+manager' => ['salon_role' => 'user', 'staff_type' => 'manager'],
        'user+front_desk' => ['salon_role' => 'user', 'staff_type' => 'front_desk'],
        'user+hyphen-front-desk' => ['salon_role' => 'user', 'staff_type' => 'front-desk'],
        'user+null' => ['salon_role' => 'user', 'staff_type' => null],
        // The value the first fix was rumoured to have targeted — mapped too.
        'member+stylist' => ['salon_role' => 'member', 'staff_type' => 'stylist'],
        // Unprefixed aliases.
        'owner-alias' => ['salon_role' => 'owner', 'staff_type' => null],
        'admin-alias' => ['salon_role' => 'admin', 'staff_type' => null],
        // Total garbage in both columns.
        'garbage' => ['salon_role' => 'wizard', 'staff_type' => 'apprentice'],
    ];

    $ids = [];
    foreach ($rows as $key => $attributes) {
        $ids[$key] = DB::table('salon_memberships')->insertGetId([
            'salon_id' => $salon->id,
            'user_id' => User::factory()->create()->id,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    $legacyAgencyRole = User::factory()->create();
    $garbageAgencyRole = User::factory()->create();
    DB::table('users')->where('id', $legacyAgencyRole->id)->update(['agency_role' => 'admin']);
    DB::table('users')->where('id', $garbageAgencyRole->id)->update(['agency_role' => 'archmage']);

    runLegacyRoleFix();

    $role = fn (string $key) => DB::table('salon_memberships')->where('id', $ids[$key])->value('salon_role');
    $type = fn (string $key) => DB::table('salon_memberships')->where('id', $ids[$key])->value('staff_type');

    expect($role('user+stylist'))->toBe('stylist');
    expect($role('user+manager'))->toBe('salon_manager');
    expect($role('user+front_desk'))->toBe('salon_manager');
    expect($role('user+hyphen-front-desk'))->toBe('salon_manager');
    expect($type('user+hyphen-front-desk'))->toBeNull();  // front-desk label died with the role
    expect($role('user+null'))->toBe('stylist');          // safe default: least privilege
    expect($type('user+null'))->toBe('stylist');          // stylist role ⇒ bookable
    expect($role('member+stylist'))->toBe('stylist');
    expect($role('owner-alias'))->toBe('salon_owner');
    expect($role('admin-alias'))->toBe('salon_manager');
    expect($role('garbage'))->toBe('stylist');            // unknown role + unknown type
    expect($type('garbage'))->toBe('stylist');            // → least privilege, bookable stylist

    expect(DB::table('users')->where('id', $legacyAgencyRole->id)->value('agency_role'))->toBe('agency_admin');
    expect(DB::table('users')->where('id', $garbageAgencyRole->id)->value('agency_role'))->toBeNull();

    // Nothing anywhere is left on a value an enum rejects…
    assertNoStrandedEnumValues();

    // …every row now loads through the enum casts without throwing…
    $salon->memberships()->get()->each(fn ($m) => $m->salon_role->label());

    // …and re-running the migration is a clean no-op.
    $before = DB::table('salon_memberships')->orderBy('id')->get();
    runLegacyRoleFix();
    expect(DB::table('salon_memberships')->orderBy('id')->get())->toEqual($before);
});
