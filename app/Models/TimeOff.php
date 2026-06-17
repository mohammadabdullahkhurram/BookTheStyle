<?php

namespace App\Models;

use App\Enums\TimeOffType;
use App\Models\Concerns\BelongsToSalon;
use Database\Factories\TimeOffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-off time-off block that overrides a stylist's weekly availability.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $user_id
 * @property TimeOffType $type
 * @property string|null $note
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
class TimeOff extends Model
{
    /** @use HasFactory<TimeOffFactory> */
    use BelongsToSalon, HasFactory;

    protected $table = 'time_off';

    protected $fillable = [
        'salon_id',
        'user_id',
        'type',
        'note',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TimeOffType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
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
