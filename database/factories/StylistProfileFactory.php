<?php

namespace Database\Factories;

use App\Models\Salon;
use App\Models\StylistProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StylistProfile>
 */
class StylistProfileFactory extends Factory
{
    protected $model = StylistProfile::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'user_id' => User::factory(),
            'bio' => fake()->sentence(),
        ];
    }
}
