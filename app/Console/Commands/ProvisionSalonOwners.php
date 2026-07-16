<?php

namespace App\Console\Commands;

use App\Actions\Staff\InviteStaff;
use App\Enums\SalonRole;
use App\Models\Salon;
use Illuminate\Console\Command;

/**
 * Backfill for salons that predate owner auto-provisioning: every salon must
 * have exactly one owner (the contact person). Reports ownerless salons and,
 * ONLY with --force, provisions each owner through the same invite path
 * salon creation uses (existing contact accounts are linked; new ones get a
 * temp password + the branded mails). Deliberate and explicit — never runs
 * from a migration or silently.
 */
class ProvisionSalonOwners extends Command
{
    protected $signature = 'salons:provision-owners {--force : Actually provision (default is a dry-run report)}';

    protected $description = 'Report salons without an owner; with --force, provision the contact person as owner';

    public function handle(InviteStaff $invites): int
    {
        $ownerless = Salon::query()
            ->whereDoesntHave('memberships', fn ($q) => $q
                ->where('salon_role', SalonRole::Owner->value)
                ->where('active', true))
            ->get();

        if ($ownerless->isEmpty()) {
            $this->components->info('Every salon has an active owner — nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['Salon', 'Slug', 'Contact person', 'Contact email'],
            $ownerless->map(fn (Salon $salon) => [
                $salon->name, $salon->slug, $salon->contact_name ?: '—', $salon->contact_email ?: '—',
            ])->all(),
        );

        if (! $this->option('force')) {
            $this->components->warn('Dry run — re-run with --force to provision the contact person as owner for each salon above.');

            return self::SUCCESS;
        }

        foreach ($ownerless as $salon) {
            if (blank($salon->contact_email) || blank($salon->contact_name)) {
                $this->components->error($salon->name.': no contact person on record — set one (agency console → edit salon), then re-run.');

                continue;
            }

            $result = $invites->provisionOwner($salon, [
                'name' => $salon->contact_name,
                'email' => $salon->contact_email,
            ]);

            $this->components->info($salon->name.': '.($result->existing
                ? $salon->contact_email.' (existing account) linked as owner.'
                : 'owner account created for '.$salon->contact_email.' — credentials emailed (and shown once here: '.$result->temporaryPassword.').'));
        }

        return self::SUCCESS;
    }
}
