<?php

namespace Database\Factories;

use App\Enums\TimeOffType;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeOff>
 */
class TimeOffFactory extends Factory
{
    protected $model = TimeOff::class;

    public function definition(): array
    {
        $start = now()->addDays(7)->startOfDay();

        return [
            'salon_id' => Salon::factory(),
            'user_id' => User::factory(),
            'type' => TimeOffType::Vacation,
            'note' => null,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(2)->endOfDay(),
        ];
    }
}
