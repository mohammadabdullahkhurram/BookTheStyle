<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Carbon\CarbonImmutable;
use Database\Factories\BookingItemFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service within a visit, in one stylist's time block. starts_at/ends_at
 * are absolute instants stored in UTC (normalised by StoresUtcTimes).
 *
 * @property int $id
 * @property int $salon_id
 * @property int $booking_id
 * @property int $service_id
 * @property int $stylist_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class BookingItem extends Model
{
    /** @use HasFactory<BookingItemFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'booking_id',
        'service_id',
        'stylist_id',
        'starts_at',
        'ends_at',
    ];

    /**
     * @return Attribute<CarbonImmutable|null, mixed>
     */
    protected function startsAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?CarbonImmutable => $value !== null ? CarbonImmutable::parse($value, 'UTC') : null,
            set: fn (mixed $value): ?string => $value !== null ? CarbonImmutable::parse($value)->utc()->toDateTimeString() : null,
        );
    }

    /**
     * @return Attribute<CarbonImmutable|null, mixed>
     */
    protected function endsAt(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?CarbonImmutable => $value !== null ? CarbonImmutable::parse($value, 'UTC') : null,
            set: fn (mixed $value): ?string => $value !== null ? CarbonImmutable::parse($value)->utc()->toDateTimeString() : null,
        );
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function stylist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stylist_id');
    }
}
