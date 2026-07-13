<?php

namespace Database\Factories;

use App\Models\Salon;
use App\Models\Widget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Widget>
 */
class WidgetFactory extends Factory
{
    protected $model = Widget::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => 'Booking widget',
            'public_id' => Widget::newPublicId(),
            'branding' => null,
            'theme' => 'marble',
        ];
    }
}
