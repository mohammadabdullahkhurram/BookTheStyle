<?php

namespace App\Models\Concerns;

use App\Models\Salon;
use App\Models\Scopes\SalonScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to any salon-scoped model (services, bookings, clients, … in later
 * phases). It adds the `salon_id` relationship, the SalonScope global scope so
 * reads are automatically constrained to the active salon, and auto-fills
 * `salon_id` on create from the active salon.
 *
 * Models using this trait must have a `salon_id` column.
 */
trait BelongsToSalon
{
    public static function bootBelongsToSalon(): void
    {
        static::addGlobalScope(new SalonScope);

        static::creating(function ($model): void {
            if (empty($model->salon_id) && app()->bound('currentSalon') && app('currentSalon')) {
                $model->salon_id = app('currentSalon')->id;
            }
        });
    }

    /**
     * @return BelongsTo<Salon, $this>
     */
    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /**
     * Explicitly scope to a salon, bypassing the active-salon global scope.
     * Useful for agency-wide/cross-salon queries that opt out of the scope.
     */
    public function scopeForSalon(Builder $query, Salon|int $salon): Builder
    {
        $salonId = $salon instanceof Salon ? $salon->id : $salon;

        return $query->withoutGlobalScope(SalonScope::class)
            ->where($this->getTable().'.salon_id', $salonId);
    }
}
