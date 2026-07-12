<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * The "noir" deep-glass dark language, currently proving itself on a small
 * set of screens before an app-wide rollout. A route on the list renders
 * with data-theme="noir" on <body>, which activates the dark token override
 * layer and the glass chrome styles in app.css. Scoped to <body> (not
 * <html>) so wire:navigate — which swaps the body element — always carries
 * the right theme between noir and light pages.
 */
final class NoirTheme
{
    /** @var list<string> route names rendered in the noir language */
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
