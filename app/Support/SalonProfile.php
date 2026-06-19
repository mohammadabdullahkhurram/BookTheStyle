<?php

namespace App\Support;

/**
 * Shared definition of a salon's business + point-of-contact profile: the
 * validation rules, the normalised column attributes, and the country list for
 * the selector. Used by the agency create/edit screens and salon settings so
 * the three "Business details / Address / Primary contact" sections stay
 * identical everywhere.
 *
 * `name` is the business / trading name (the existing salon name = the GHL
 * sub-account name). `website` and `address_line2` are the only optional fields;
 * everything else is required. Phone/region/country are international-friendly
 * (no US-only assumptions). The GHL connection fields are stored separately and
 * are not part of this profile.
 */
class SalonProfile
{
    /** A lenient, international-friendly phone shape: digits + common separators. */
    private const PHONE = 'regex:/^[0-9+\-\s().]{7,32}$/';

    /**
     * Validation rules for the full profile (keyed by the form field name, which
     * matches the salon column). Required everywhere except website + line 2.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_business_name' => ['required', 'string', 'max:255'],
            'business_email' => ['required', 'email', 'max:255'],
            'business_phone' => ['required', 'string', 'max:32', self::PHONE],
            'website' => ['nullable', 'url', 'max:255'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:32'],
            'country' => ['required', 'string', 'max:100'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:32', self::PHONE],
        ];
    }

    /**
     * Map validated form input to salon column attributes. The two optional
     * fields collapse blank → null; required fields pass through.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public static function attributes(array $data): array
    {
        return [
            'name' => (string) ($data['name'] ?? ''),
            'legal_business_name' => (string) ($data['legal_business_name'] ?? ''),
            'business_email' => (string) ($data['business_email'] ?? ''),
            'business_phone' => (string) ($data['business_phone'] ?? ''),
            'website' => self::blankToNull($data['website'] ?? null),
            'address_line1' => (string) ($data['address_line1'] ?? ''),
            'address_line2' => self::blankToNull($data['address_line2'] ?? null),
            'city' => (string) ($data['city'] ?? ''),
            'region' => (string) ($data['region'] ?? ''),
            'postal_code' => (string) ($data['postal_code'] ?? ''),
            'country' => (string) ($data['country'] ?? ''),
            'contact_name' => (string) ($data['contact_name'] ?? ''),
            'contact_email' => (string) ($data['contact_email'] ?? ''),
            'contact_phone' => (string) ($data['contact_phone'] ?? ''),
        ];
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Countries for the selector. The column stores the chosen name as a plain
     * string, so any value round-trips even if it is not in this list.
     *
     * @return list<string>
     */
    public static function countries(): array
    {
        return [
            'Argentina', 'Australia', 'Austria', 'Bahrain', 'Bangladesh', 'Belgium', 'Brazil',
            'Bulgaria', 'Canada', 'Chile', 'China', 'Colombia', 'Croatia', 'Cyprus',
            'Czech Republic', 'Denmark', 'Egypt', 'Estonia', 'Finland', 'France', 'Germany',
            'Ghana', 'Greece', 'Hong Kong', 'Hungary', 'Iceland', 'India', 'Indonesia',
            'Ireland', 'Israel', 'Italy', 'Japan', 'Jordan', 'Kenya', 'Kuwait', 'Latvia',
            'Lebanon', 'Lithuania', 'Luxembourg', 'Malaysia', 'Malta', 'Mexico', 'Morocco',
            'Netherlands', 'New Zealand', 'Nigeria', 'Norway', 'Oman', 'Pakistan', 'Peru',
            'Philippines', 'Poland', 'Portugal', 'Qatar', 'Romania', 'Saudi Arabia',
            'Serbia', 'Singapore', 'Slovakia', 'Slovenia', 'South Africa', 'South Korea',
            'Spain', 'Sri Lanka', 'Sweden', 'Switzerland', 'Taiwan', 'Thailand', 'Tunisia',
            'Turkey', 'Ukraine', 'United Arab Emirates', 'United Kingdom', 'United States',
            'Uruguay', 'Vietnam',
        ];
    }
}
