<?php

namespace App\Support;

use App\Models\Salon;
use Illuminate\Support\Facades\Route;

/**
 * Which theme the current APP page renders under (the data-theme attribute
 * on <body> — body-scoped so wire:navigate carries it):
 *
 *   1. The AGENCY console renders the BRAND palette — the exact landing-
 *      page colours (the classic token set on white), so the operator
 *      console matches the public brand.
 *   2. A salon renders its picked app theme (Settings → Branding; Marble is
 *      the default). Classic = null = the base token set, exactly the
 *      original look — including the standing Lumen glass on its proof
 *      routes, so a Classic salon reproduces the pre-Marble app verbatim.
 *   3. Outside any salon/agency context — every auth screen (login,
 *      password reset, invite accept, 2FA challenge), the salon picker,
 *      account settings — the front door matches the public brand too.
 */
final class AppTheme
{
    public static function current(?Salon $salon): ?string
    {
        $route = (string) Route::currentRouteName();

        if (str_starts_with($route, 'agency.')) {
            return 'brand';
        }

        if ($salon !== null) {
            return ThemeRegistry::bodyTheme($salon->app_theme)
                ?? (LumenTheme::active() ? 'lumen' : null);
        }

        return 'brand';
    }
}
