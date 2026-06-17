<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingStatusEvent;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingStatusEvent>
 */
class BookingStatusEventFactory extends Factory
{
    protected $model = BookingStatusEvent::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'booking_id' => Booking::factory(),
            'from_status' => null,
            'to_status' => BookingStatus::Booked,
            'actor_user_id' => null,
        ];
    }
}
