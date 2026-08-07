<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded health-check run for a salon — see the migration for the
 * shape. Deliberately NOT BelongsToSalon: runs are read on an agency-only
 * page and written by a console monitor, so every query filters salon_id
 * explicitly (HealthMonitor) instead of relying on tenant context.
 * Retention: rows prune after 90 days (shared hosting, bounded tables).
 *
 * @property int $id
 * @property int $salon_id
 * @property string $source
 * @property int $pass_count
 * @property int $warn_count
 * @property int $fail_count
 * @property array<string, array{label: string, status: string, message: string}> $results
 * @property list<array{key: string, label: string, message: string, was: string}>|null $regressions
 * @property CarbonImmutable $created_at
 */
class HealthCheckRun extends Model
{
    use MassPrunable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'regressions' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(90));
    }

    /** @return BelongsTo<Salon, $this> */
    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }
}
