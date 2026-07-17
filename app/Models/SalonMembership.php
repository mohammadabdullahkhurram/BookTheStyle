<?php

namespace App\Models;

use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Enums\StylistArrangement;
use Database\Factories\SalonMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Join of a user to a salon, carrying their role + staff type for that salon,
 * plus the GHL LOCATION USER a non-stylist staff member links to for identity
 * and attribution. Stylists' bookable-provider mapping lives separately on
 * StylistProfile::$ghl_user_id (a calendar team member; routes 6b pushes) —
 * this field never routes bookings.
 *
 * @property int $id
 * @property int $user_id
 * @property int $salon_id
 * @property SalonRole $salon_role
 * @property StaffType|null $staff_type
 * @property StylistArrangement $arrangement
 * @property string|null $ghl_location_user_id
 * @property bool $active
 */
class SalonMembership extends Model
{
    /** @use HasFactory<SalonMembershipFactory> */
    use HasFactory;

    /** Fresh instances mirror the column default (today's behavior). */
    protected $attributes = [
        'arrangement' => 'employee',
    ];

    protected $fillable = [
        'user_id',
        'salon_id',
        'salon_role',
        'staff_type',
        'arrangement',
        'ghl_location_user_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'salon_role' => SalonRole::class,
            'staff_type' => StaffType::class,
            'arrangement' => StylistArrangement::class,
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
}
