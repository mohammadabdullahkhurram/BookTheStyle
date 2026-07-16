<?php

namespace Database\Factories;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalonMembership>
 */
class SalonMembershipFactory extends Factory
{
    protected $model = SalonMembership::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'salon_id' => Salon::factory(),
            'salon_role' => SalonRole::Stylist,
            'staff_type' => StaffType::Stylist,
            'active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => [
            'salon_role' => SalonRole::Owner,
            'staff_type' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'salon_role' => SalonRole::Manager,
            'staff_type' => null,
        ]);
    }

    public function stylist(): static
    {
        return $this->state(fn () => [
            'salon_role' => SalonRole::Stylist,
            'staff_type' => StaffType::Stylist,
        ]);
    }

    public function frontDesk(): static
    {
        // Front desk holds the ADMIN role (type is functional only).
        return $this->state(fn () => [
            'salon_role' => SalonRole::Manager,
            'staff_type' => null,
        ]);
    }

    public function manager(): static
    {
        // Managers hold the ADMIN role (type is functional only).
        return $this->state(fn () => [
            'salon_role' => SalonRole::Manager,
            'staff_type' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
