<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class StorageWritable implements HealthCheck
{
    public function key(): string
    {
        return 'storage';
    }

    public function label(): string
    {
        return __('Storage');
    }

    public function run(HealthContext $context): CheckResult
    {
        $paths = [storage_path('framework'), storage_path('logs'), storage_path('app')];
        $blocked = array_values(array_filter($paths, fn (string $path) => ! is_writable($path)));

        if ($blocked !== []) {
            return CheckResult::fail(
                __('These storage folders are not writable: :paths — uploads, logs, and caches will fail.', ['paths' => implode(', ', $blocked)]),
                __('Fix the folder permissions on the server (the web user must own storage/).'),
            );
        }

        return CheckResult::pass(__('Storage folders are writable (logs, caches, uploads).'));
    }
}
