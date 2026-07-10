<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientNote>
 */
class ClientNoteFactory extends Factory
{
    protected $model = ClientNote::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'client_id' => Client::factory(),
            'author_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
