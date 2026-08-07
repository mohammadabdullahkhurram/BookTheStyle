<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use Illuminate\Database\Migrations\Migrator;

class MigrationsUpToDate implements HealthCheck
{
    public function key(): string
    {
        return 'migrations';
    }

    public function label(): string
    {
        return __('Database migrations');
    }

    public function run(HealthContext $context): CheckResult
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return CheckResult::fail(__('The migrations table does not exist — the database was never migrated.'), __('Run the deploy sequence (php artisan migrate --force).'));
        }

        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $ran = $migrator->getRepository()->getRan();
        $pending = array_diff(array_keys($files), $ran);

        if ($pending !== []) {
            return CheckResult::fail(
                __(':count migration(s) have not run — the code expects columns the database does not have yet.', ['count' => count($pending)]),
                __('Run the full deploy sequence (docs/DEPLOY.md) — migrate --force is part of it.'),
            );
        }

        return CheckResult::pass(__('The database schema is up to date (:count migrations applied).', ['count' => count($ran)]));
    }
}
