<?php

namespace App\Models;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use Database\Factories\SalonMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Join of a user to a salon, carrying their role + staff type for that salon.
 *
 * @property int $id
 * @property int $user_id
 * @property int $salon_id
 * @property SalonRole $salon_role
 * @property StaffType|null $staff_type
 * @property bool $active
 */
class SalonMembership extends Model
{
    /** @use HasFactory<SalonMembershipFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'salon_id',
        'salon_role',
        'staff_type',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'salon_role' => SalonRole::class,
            'staff_type' => StaffType::class,
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Salon, $this>
     */
    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function isStylist(): bool
    {
        return $this->staff_type === StaffType::Stylist;
    }

    public function isFrontDesk(): bool
    {
        return $this->staff_type === StaffType::FrontDesk;
    }
}
