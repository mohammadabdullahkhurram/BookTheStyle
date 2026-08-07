<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;
use Illuminate\Support\Facades\DB;

class DatabaseConnectivity implements HealthCheck
{
    public function key(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return __('Database');
    }

    public function run(HealthContext $context): CheckResult
    {
        try {
            DB::select('select 1');

            return CheckResult::pass(__('The database answers (:connection).', ['connection' => (string) config('database.default')]));
        } catch (\Throwable $e) {
            return CheckResult::fail(
                __('The database did not answer — nothing works without it.'),
                __('Check the DB credentials in the server .env and the database server itself.'),
            );
        }
    }
}
