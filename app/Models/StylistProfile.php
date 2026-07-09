<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Carbon\CarbonImmutable;
use Database\Factories\StylistProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lightweight per-(user, salon) stylist record: bio, plus the GHL team-member
 * (user) id this stylist maps to on the salon's master GHL calendar (Phase 6a;
 * 6b routes appointment pushes with it), plus the Phase-6e availability
 * mirror: the GHL user availability schedule id this stylist's weekly hours +
 * time off are pushed to, and its sync bookkeeping.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $user_id
 * @property string|null $bio
 * @property string|null $ghl_user_id
 * @property string|null $ghl_schedule_id
 * @property string|null $ghl_availability_status
 * @property string|null $ghl_availability_error
 * @property string|null $ghl_availability_hash
 * @property CarbonImmutable|null $ghl_availability_synced_at
 */
class StylistProfile extends Model
{
    /** @use HasFactory<StylistProfileFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'user_id',
        'bio',
        'ghl_user_id',
        'ghl_schedule_id',
        'ghl_availability_status',
        'ghl_availability_error',
        'ghl_availability_hash',
        'ghl_availability_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'ghl_availability_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
