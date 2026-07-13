<?php

namespace App\Actions\Salons;

use App\Models\Salon;
use App\Support\WidgetBranding;
use Illuminate\Support\Facades\Storage;

/**
 * Update a salon's branding (salon settings → Branding): the brandable
 * accent, the widget's secondary/surface colours, the curated widget font,
 * and the uploaded logo. All stored additively in salons.branding JSON;
 * null/blank values clear back to the defaults. Replacing or removing the
 * logo deletes the previous file from the public disk.
 */
class UpdateBranding
{
    /**
     * @param  array{accent?: string|null, secondary?: string|null, surface?: string|null, font?: string|null, logo_path?: string|null, remove_logo?: bool}  $data
     */
    public function handle(Salon $salon, array $data): Salon
    {
        $branding = $salon->branding ?? [];

        foreach (['accent', 'secondary', 'surface'] as $key) {
            if (array_key_exists($key, $data)) {
                $branding[$key] = ($data[$key] ?? '') !== '' ? $data[$key] : null;
            }
        }

        if (array_key_exists('font', $data)) {
            $branding['font'] = isset(WidgetBranding::FONTS[$data['font'] ?? '']) ? $data['font'] : null;
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
