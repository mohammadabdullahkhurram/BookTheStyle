<?php

namespace App\Services\Health\Checks;

use App\Services\Health\CheckResult;
use App\Services\Health\HealthCheck;
use App\Services\Health\HealthContext;

class SalonBrandingSet implements HealthCheck
{
    public function key(): string
    {
        return 'branding';
    }

    public function label(): string
    {
        return __('Branding');
    }

    public function run(HealthContext $context): CheckResult
    {
        $branding = (array) ($context->salon->branding ?? []);
        $hasAccent = $context->salon->accentColor() !== null;
        $hasLogo = is_string($branding['logo_path'] ?? null);

        if (! $hasAccent && ! $hasLogo) {
            return CheckResult::warn(
                __('No logo or accent colour is set — everything works, but the app and widget show default styling instead of the salon\'s brand.'),
                __('Upload the logo and pick the accent under Settings → Branding (SOP part A, step 6).'),
            );
        }

        if (! $hasLogo || ! $hasAccent) {
            $message = ! $hasLogo
                ? __('Branding is partly set — the logo is still missing.')
                : __('Branding is partly set — the accent colour is still missing.');

            return CheckResult::warn($message, __('Finish it under Settings → Branding.'));
        }

        return CheckResult::pass(__('Logo and accent colour are set — the app and widget carry the salon\'s brand.'));
    }
}
