<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains a salon-owned model to the currently resolved
 * salon. The active salon is bound in the container as `currentSalon` by the
 * ResolveSalon middleware. When no salon is active (console, agency-wide
 * queries, queue workers) the scope is a no-op — callers must then filter
 * explicitly (e.g. via the salon relationship or scopeForSalon()).
 *
 * This is the query-level half of tenant isolation; the request-level half is
 * the membership check in ResolveSalon. Together they prevent IDOR.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
class SalonScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! app()->bound('currentSalon')) {
            return;
        }

        $salon = app('currentSalon');

        if ($salon !== null) {
            $builder->where($model->getTable().'.salon_id', $salon->id);
        }
    }
}
