<?php

namespace App\Models;

use App\Enums\BookedByType;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToSalon;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client visit — one or more service items, each in its own stylist's block.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $client_id
 * @property BookingStatus $status
 * @property BookedByType $booked_by_type
 * @property int|null $booked_by_user_id
 * @property BookingSource $source
 * @property bool $is_walkin
 * @property string|null $notes
 * @property string|null $ghl_appointment_id
 * @property string|null $ghl_sync_status
 * @property string|null $ghl_sync_error
 * @property CarbonImmutable|null $last_synced_at
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'client_id',
        'status',
        'booked_by_type',
        'booked_by_user_id',
        'source',
        'is_walkin',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'booked_by_type' => BookedByType::class,
            'source' => BookingSource::class,
            'is_walkin' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<BookingItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_user_id');
    }

    /**
     * Per-stylist GHL appointment mirrors (one row per distinct stylist on
     * the booking).
     *
     * @return HasMany<BookingGhlAppointment, $this>
     */
    public function ghlAppointments(): HasMany
    {
        return $this->hasMany(BookingGhlAppointment::class);
    }

    /**
     * @return HasMany<BookingStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(BookingStatusEvent::class);
    }
}
