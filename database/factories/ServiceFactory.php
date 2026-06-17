<?php

namespace Database\Factories;

use App\Models\Salon;
use App\Models\Service;
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
            'color' => fake()->randomElement(['#1F6F6B', '#B7791F', '#2B6CB0', '#B23A2E']),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
