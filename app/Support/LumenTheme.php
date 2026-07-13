<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * The "lumen" light liquid-glass language. The app-wide rollout it was
 * proving for chose MARBLE instead, so lumen survives only as part of the
 * CLASSIC theme: a Classic salon's proof routes still render exactly as
 * they did pre-rollout (see AppTheme). Body-scoped so wire:navigate — which
 * swaps the body element — always carries the right theme. Light mode only.
 */
final class LumenTheme
{
    /** @var list<string> route names rendered in the lumen language (Classic salons) */
    public const ROUTES = [
        'salon.show',
        'salon.appointments.all',
    ];

    public static function active(): bool
    {
        return in_array(Route::currentRouteName(), self::ROUTES, true);
    }
}
