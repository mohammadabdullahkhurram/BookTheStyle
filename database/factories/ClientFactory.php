<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('+1##########'),
            'email' => fake()->safeEmail(),
        ];
    }
}
