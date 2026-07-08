<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Database\Factories\StylistProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lightweight per-(user, salon) stylist record: bio, plus the GHL team-member
 * (user) id this stylist maps to on the salon's master GHL calendar (Phase 6a;
 * 6b routes appointment pushes with it).
 *
 * @property int $id
 * @property int $salon_id
 * @property int $user_id
 * @property string|null $bio
 * @property string|null $ghl_user_id
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
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
