<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * The "lumen" light liquid-glass language, currently proving itself on a
 * small set of screens before an app-wide rollout. A route on the list
 * renders with data-theme="lumen" on <body>, which activates the warm
 * gradient backdrop, glass chrome, widget stats, and glass overlays in
 * app.css. Scoped to <body> (not <html>) so wire:navigate — which swaps the
 * body element — always carries the right theme between lumen and plain
 * pages. Light mode only.
 */
final class LumenTheme
{
    /** @var list<string> route names rendered in the lumen language */
    public const ROUTES = [
        'login',
        'salon.show',
        'salon.appointments.all',
    ];

    public static function active(): bool
    {
        return in_array(Route::currentRouteName(), self::ROUTES, true);
    }
}
