<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stylist's slice of a booking as mirrored to GoHighLevel: a booking
 * with services across N distinct stylists holds N of these rows, each
 * carrying that stylist's GHL appointment id and its own sync state. The
 * payload hash lets a re-push skip stylists whose appointment did not
 * change. These ids are 6c's echo-loop dedupe keys.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $booking_id
 * @property int $stylist_id
 * @property string|null $ghl_appointment_id
 * @property string|null $sync_status
 * @property string|null $sync_error
 * @property string|null $payload_hash
 * @property CarbonImmutable|null $last_synced_at
 */
class BookingGhlAppointment extends Model
{
    use BelongsToSalon;

    protected $fillable = [
        'salon_id',
        'booking_id',
        'stylist_id',
        'ghl_appointment_id',
        'sync_status',
        'sync_error',
        'payload_hash',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
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
    public function stylist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stylist_id');
    }
}
