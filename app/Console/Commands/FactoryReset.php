<?php

namespace App\Console\Commands;

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Factory reset — DATA ONLY, schema untouched (never migrate:fresh; see
 * CLAUDE.md golden rule 10). Empties every application table in FK-safe
 * child-to-parent order via explicit deletes, then provisions the single
 * pristine account: the Bluejaypro agency with ONE agency owner
 * (abdullah@bluejaypro.com / password, printed at the end). Logging in
 * lands in the agency console with zero salons, ready to create the first
 * real one.
 *
 * Destructive to data by design, so it never runs by accident: it asks for
 * confirmation unless --force is passed, and it is only ever run by hand.
 * Repeatable: every run converges on the same pristine state.
 */
class FactoryReset extends Command
{
    protected $signature = 'app:factory-reset {--force : Skip the confirmation prompt}';

    protected $description = 'Wipe ALL application data (schema untouched) and leave a single agency owner account';

    private const OWNER_EMAIL = 'abdullah@bluejaypro.com';

    private const OWNER_PASSWORD = 'password';

    /**
     * Every application table, children before parents so plain deletes are
     * FK-safe on MySQL and SQLite alike. Framework state (sessions, jobs,
     * cache, resets, passkeys) is cleared too — pristine means pristine.
     * The migrations table is untouched: this is a DATA reset only.
     *
     * @var list<string>
     */
    private const TABLES = [
        // Booking graph (deepest children first).
        'booking_status_events',
        'booking_ghl_appointments',
        'booking_items',
        'bookings',
        'client_notes',
        'clients',
        // Availability + staffing.
        'time_off',
        'availabilities',
        'service_stylist',
        'services',
        'stylist_profiles',
        // Widgets, integrations, feeds.
        'widgets',
        'salon_ghl_connections',
        'webhook_events',
        'calendar_connections',
        // Tenancy.
        'agency_salon_assignments',
        'salon_memberships',
        'salons',
        'passkeys',
        'users',
        'agencies',
        // Framework state.
        'sessions',
        'password_reset_tokens',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('This wipes ALL application data (salons, users, bookings, clients, widgets, connections) and leaves one agency owner. Continue?')) {
            $this->components->info('Factory reset aborted — nothing was touched.');

            return self::SUCCESS;
        }

        $cleared = 0;
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $cleared += DB::table($table)->delete();
            }
        }

        $agency = Agency::create(['name' => 'Bluejaypro']);

        User::create([
            'name' => 'Abdullah',
            'email' => self::OWNER_EMAIL,
            'password' => self::OWNER_PASSWORD,
            'email_verified_at' => now(),
            'must_change_password' => false,
            'agency_id' => $agency->id,
            'agency_role' => AgencyRole::Owner,
        ]);

        $this->components->info('Factory reset complete: '.$cleared.' rows cleared, schema untouched. One agency owner remains — log in at app.'.config('app.domain').' and create the first salon from the agency console.');
        $this->table(
            ['Email', 'Password', 'Role'],
            [[self::OWNER_EMAIL, self::OWNER_PASSWORD, 'Agency owner (Bluejaypro)']],
        );

        return self::SUCCESS;
    }
}
