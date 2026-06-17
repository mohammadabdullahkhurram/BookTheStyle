<?php

namespace Database\Factories;

use App\Enums\AvailabilityKind;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Availability>
 */
class AvailabilityFactory extends Factory
{
    protected $model = Availability::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'user_id' => User::factory(),
            'weekday' => 0,
            'kind' => AvailabilityKind::Work,
            'start_minute' => 9 * 60,
            'end_minute' => 17 * 60,
        ];
    }

    public function break(): static
    {
        return $this->state(fn () => [
            'kind' => AvailabilityKind::Break,
            'start_minute' => 12 * 60,
            'end_minute' => 13 * 60,
        ]);
    }
}
