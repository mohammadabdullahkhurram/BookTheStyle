<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingItem>
 */
class BookingItemFactory extends Factory
{
    protected $model = BookingItem::class;

    public function definition(): array
    {
        $start = now()->addDay()->setTime(10, 0);

        return [
            'salon_id' => Salon::factory(),
            'booking_id' => Booking::factory(),
            'service_id' => Service::factory(),
            'stylist_id' => User::factory(),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(60),
        ];
    }
}
