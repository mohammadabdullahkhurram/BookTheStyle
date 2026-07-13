<?php

namespace App\Support;

use App\Models\Salon;
use Illuminate\Support\Facades\Route;

/**
 * Which theme the current APP page renders under (the data-theme attribute
 * on <body> — body-scoped so wire:navigate carries it):
 *
 *   1. The AGENCY console always renders Glacier — the cross-salon admin
 *      area is visually distinct from every salon app.
 *   2. A salon that picked an app theme (Settings → Branding) renders it
 *      across its salon pages.
 *   3. Otherwise the standing Lumen proof-route list, then the plain look.
 */
final class AppTheme
{
    public static function current(?Salon $salon): ?string
    {
        $route = (string) Route::currentRouteName();

        if (str_starts_with($route, 'agency.')) {
            return 'glacier';
        }

        if ($salon !== null && ($theme = ThemeRegistry::bodyTheme($salon->app_theme)) !== null) {
            return $theme;
        }

        return LumenTheme::active() ? 'lumen' : null;
    }
}
