<?php

namespace App\Support;

use App\Models\Salon;
use Flux\Flux;
use Illuminate\Http\Request;

/**
 * Demo context, in one place. The demo is a VIEW-ONLY guest preview of one
 * canonical showcase salon on the static demo.{domain} host: no login, no
 * per-visitor salons. ResolveSalon maps the demo host onto the showcase
 * salon and installs a request-scoped viewer; every mutating Livewire
 * action on demo-visible pages guards through blocksWrite(), which turns
 * the write into a no-op with the standard "disabled in the demo" toast.
 * Real salons pass through untouched. Everything rides the same
 * salons.is_demo flag as always — no parallel detection.
 */
final class DemoMode
{
    /** The canonical showcase salon's fixed slug (an unroutable DB id —
     *  demo salons are never reachable as {slug} subdomains). */
    public const SHOWCASE_SLUG = 'demo-showcase';

    /** True when this request is on the static demo host. */
    public static function isDemoHost(Request $request): bool
    {
        return $request->getHost() === 'demo.'.config('app.domain');
    }

    /** The one fixed salon the demo shows, or null when not yet seeded. */
    public static function showcaseSalon(): ?Salon
    {
        return Salon::query()
            ->where('slug', self::SHOWCASE_SLUG)
            ->where('is_demo', true)
            ->where('active', true)
            ->first();
    }

    public static function blocksWrite(Salon $salon, ?string $note = null): bool
    {
        if (! $salon->is_demo) {
            return false;
        }

        Flux::toast(variant: 'danger', text: $note ?? __('Editing is disabled in the demo.'));

        return true;
    }
}
