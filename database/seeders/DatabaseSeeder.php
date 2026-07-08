<?php

namespace Database\Seeders;

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Clean-slate dev seed: ONLY the agency and its agency-level accounts.
 *
 * Deliberately creates no salons and nothing under them (no memberships,
 * stylists, services, availability, bookings, clients, calendar or GHL data):
 * log in as the agency, see an empty salon picker, and create a brand-new
 * salon from the agency console to test the full flow end-to-end.
 *
 * Idempotent: safe to re-run (keyed by agency name / user email).
 * Tests never rely on this seeder — they build their own data via factories.
 *
 * Accounts (all with password "password")
 * ---------------------------------------
 *   agency@bookthestyle.test   Agency Owner (Bluejaypro)
 *   admin@bookthestyle.test    Agency Admin (Bluejaypro)
 *   user@bookthestyle.test     Agency User  (no salons assigned yet)
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const PASSWORD = 'password';

    public function run(): void
    {
        $agency = Agency::firstOrCreate(['name' => 'Bluejaypro']);

        $accounts = [
            ['agency@bookthestyle.test', 'Bluejay Owner', AgencyRole::Owner],
            ['admin@bookthestyle.test', 'Adira Admin', AgencyRole::Admin],
            ['user@bookthestyle.test', 'Uma Agency-User', AgencyRole::User],
        ];

        foreach ($accounts as [$email, $name, $role]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => self::PASSWORD,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'agency_id' => $agency->id,
                'agency_role' => $role,
            ]);
        }

        // $command is typed non-nullable but stays uninitialized when the
        // seeder runs outside artisan (programmatic runs), so isset() is the
        // correct runtime guard here.
        // @phpstan-ignore isset.property
        if (isset($this->command)) {
            $this->command->info('Seeded agency "Bluejaypro" with no salons — create one from the agency console.');
            $this->command->table(
                ['Email', 'Password', 'Role'],
                array_map(fn (array $account): array => [$account[0], self::PASSWORD, $account[2]->label()], $accounts),
            );
        }
    }
}
