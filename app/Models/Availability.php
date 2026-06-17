<?php

namespace App\Models;

use App\Enums\AvailabilityKind;
use App\Models\Concerns\BelongsToSalon;
use Database\Factories\AvailabilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekly recurring availability window for a stylist: working hours or a
 * break. Times are minutes from midnight in the salon's timezone.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $user_id
 * @property int $weekday
 * @property AvailabilityKind $kind
 * @property int $start_minute
 * @property int $end_minute
 */
class Availability extends Model
{
    /** @use HasFactory<AvailabilityFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'user_id',
        'weekday',
        'kind',
        'start_minute',
        'end_minute',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'kind' => AvailabilityKind::class,
            'start_minute' => 'integer',
            'end_minute' => 'integer',
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
