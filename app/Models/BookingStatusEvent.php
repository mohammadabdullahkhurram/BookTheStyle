<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToSalon;
use Database\Factories\BookingStatusEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry in a booking's immutable status timeline.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $booking_id
 * @property BookingStatus|null $from_status
 * @property BookingStatus $to_status
 * @property string|null $note
 * @property int|null $actor_user_id
 * @property Carbon|null $created_at
 */
class BookingStatusEvent extends Model
{
    /** @use HasFactory<BookingStatusEventFactory> */
    use BelongsToSalon, HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'salon_id',
        'booking_id',
        'from_status',
        'to_status',
        'note',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => BookingStatus::class,
            'to_status' => BookingStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
