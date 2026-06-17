<?php

namespace Database\Factories;

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'client_id' => Client::factory(),
            'status' => BookingStatus::Booked,
            'booked_by_type' => BookedByType::SalonAdmin,
            'booked_by_user_id' => null,
            'source' => BookingSource::InApp,
            'is_walkin' => false,
            'notes' => null,
        ];
    }
}
