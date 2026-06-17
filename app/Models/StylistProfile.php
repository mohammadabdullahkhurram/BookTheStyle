<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Database\Factories\StylistProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lightweight per-(user, salon) stylist record. Phase 5 adds ics_feed_token,
 * Phase 6 adds ghl_calendar_id.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $user_id
 * @property string|null $bio
 */
class StylistProfile extends Model
{
    /** @use HasFactory<StylistProfileFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'user_id',
        'bio',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
