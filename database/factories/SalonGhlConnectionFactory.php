<?php

namespace Database\Factories;

use App\Models\Salon;
use App\Models\SalonGhlConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalonGhlConnection>
 */
class SalonGhlConnectionFactory extends Factory
{
    protected $model = SalonGhlConnection::class;

    /**
     * A fully-connected salon by default (all three fields + connected_at).
     */
    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'location_id' => 'loc_'.fake()->bothify('??????####'),
            'private_integration_token' => 'pit-'.fake()->sha1(),
            'calendar_id' => 'cal_'.fake()->bothify('??????####'),
            'connected_at' => now(),
        ];
    }

    /**
     * An empty/unconnected connection row (no credentials yet).
     */
    public function unconnected(): static
    {
        return $this->state(fn () => [
            'location_id' => null,
            'private_integration_token' => null,
            'calendar_id' => null,
            'connected_at' => null,
        ]);
    }
}
