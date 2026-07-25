<?php

namespace App\Support;

use App\Models\Salon;
use Flux\Flux;

/**
 * The demo's read-only showcase helper. Pages that stay VISIBLE in the demo
 * (business hours, staff & roles, branding — the "look how configurable this
 * is" story) guard their mutating Livewire actions through blocksWrite(): in
 * a demo salon the write becomes a no-op with the standard inline note as a
 * toast, so nothing ever dead-ends on a broken save. Real salons pass
 * through untouched. Rides the same salons.is_demo flag as every other demo
 * guard.
 */
final class DemoMode
{
    public static function blocksWrite(Salon $salon, ?string $note = null): bool
    {
        if (! $salon->is_demo) {
            return false;
        }

        Flux::toast(variant: 'danger', text: $note ?? __('Editing is disabled in the demo.'));

        return true;
    }
}
