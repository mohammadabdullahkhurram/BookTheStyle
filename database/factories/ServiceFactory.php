<?php

namespace Database\Factories;

use App\Models\Salon;
use App\Models\Service;
use App\Support\ServicePalette;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => fake()->randomElement(['Cut & Style', 'Color', 'Blowout', 'Balayage', 'Treatment']),
            'duration_min' => fake()->randomElement([30, 45, 60, 90]),
            'color_key' => fake()->randomElement(ServicePalette::keys()),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
