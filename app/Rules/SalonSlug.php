<?php

namespace App\Rules;

use App\Support\ReservedSlugs;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a salon slug as a safe DNS subdomain label: lowercase letters,
 * digits and single internal hyphens only (no leading/trailing hyphen, no
 * consecutive hyphens), sane length, and not a reserved system subdomain.
 *
 * Uniqueness is enforced separately by the caller (it needs the table + a
 * self-ignore on edit); this rule covers format and the reserved blocklist.
 */
class SalonSlug implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute is required.');

            return;
        }

        if (strlen($value) < 2 || strlen($value) > 63) {
            $fail('The :attribute must be between 2 and 63 characters.');

            return;
        }

        // Lowercase alphanumerics with single internal hyphens only.
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            $fail('The :attribute may use only lowercase letters, numbers, and single hyphens (no leading, trailing, or repeated hyphens).');

            return;
        }

        if (ReservedSlugs::isReserved($value)) {
            $fail('The :attribute ":input" is reserved and cannot be used.');
        }
    }
}
