<?php

namespace App\Actions\Salons;

use App\Models\Salon;
use Illuminate\Support\Facades\Storage;

/**
 * Update a salon's branding (salon settings → Branding): the brand ACCENT
 * and the LOGO — the two salon-level brand controls. Widget-specific
 * colours/fonts live per widget in the Widgets area; any legacy widget
 * keys already stored in salons.branding are left untouched (they remain
 * the widgets' inherited defaults). Stored additively in salons.branding
 * JSON; blank clears back to the default. Replacing or removing the logo
 * deletes the previous file from the public disk.
 */
class UpdateBranding
{
    /**
     * @param  array{accent?: string|null, logo_path?: string|null, remove_logo?: bool}  $data
     */
    public function handle(Salon $salon, array $data): Salon
    {
        $branding = $salon->branding ?? [];

        if (array_key_exists('accent', $data)) {
            $branding['accent'] = ($data['accent'] ?? '') !== '' ? $data['accent'] : null;
        }

        $oldLogo = $branding['logo_path'] ?? null;

        if (($data['remove_logo'] ?? false) === true) {
            $branding['logo_path'] = null;
        } elseif (array_key_exists('logo_path', $data) && $data['logo_path'] !== null) {
            $branding['logo_path'] = $data['logo_path'];
        }

        // A replaced or removed logo leaves no orphan file behind.
        if (is_string($oldLogo) && $oldLogo !== ($branding['logo_path'] ?? null)) {
            Storage::disk('public')->delete($oldLogo);
        }

        $branding = array_filter($branding, fn ($v) => $v !== null);

        $salon->update([
            'branding' => $branding === [] ? null : $branding,
        ]);

        return $salon;
    }
}
