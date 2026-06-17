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
            'ghl_location_id' => null,
            'ghl_token' => null,
            'allow_walkins' => true,
            'allow_same_day' => true,
            'max_advance_days' => 90,
            'min_notice_minutes' => 0,
            'feature_flags' => null,
        ];
    }

    public function forAgency(Agency $agency): static
    {
        return $this->state(fn () => ['agency_id' => $agency->id]);
    }
}
