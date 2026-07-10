<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Salon>
 */
class SalonFactory extends Factory
{
    protected $model = Salon::class;

    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => fake()->company().' Salon',
            // A unique, DNS-safe subdomain label. Tests that need a specific
            // subdomain pass an explicit slug (e.g. ['slug' => 'demo']).
            'slug' => fake()->unique()->slug(2, false).'-'.fake()->unique()->numberBetween(1000, 9_999_999),
            'timezone' => 'America/New_York',
            'branding' => null,
            // Business + contact profile (international-friendly values).
            'legal_business_name' => fake()->company().' LLC',
            'business_email' => fake()->unique()->companyEmail(),
            'business_phone' => fake()->numerify('+1 ###-###-####'),
            'website' => 'https://'.fake()->domainName(),
            'address_line1' => fake()->streetAddress(),
            'address_line2' => null,
            'city' => fake()->city(),
            'region' => fake()->randomElement(['California', 'New York', 'Texas', 'Ontario', 'Bavaria', 'Queensland']),
            'postal_code' => fake()->postcode(),
            'country' => 'United States',
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => fake()->numerify('+1 ###-###-####'),
            'allow_walkins' => true,
            'allow_same_day' => true,
            'max_advance_days' => 90,
            'min_notice_minutes' => 0,
            // Mirror the migration defaults (auto-no-show is opt-in).
            'auto_no_show' => false,
            'auto_no_show_grace_minutes' => 15,
            'auto_complete' => true,
            'feature_flags' => null,
        ];
    }

    public function forAgency(Agency $agency): static
    {
        return $this->state(fn () => ['agency_id' => $agency->id]);
    }
}
