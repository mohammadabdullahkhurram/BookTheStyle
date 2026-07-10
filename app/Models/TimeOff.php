<?php

namespace App\Models;

use App\Enums\TimeOffType;
use App\Models\Concerns\BelongsToSalon;
use Carbon\CarbonImmutable;
use Database\Factories\TimeOffFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off date-specific entry that overrides a stylist's weekly schedule.
 * starts_at/ends_at are absolute instants stored in UTC. Two kinds:
 *
 * - KIND_OFF   — an unavailable stretch (classic time off).
 * - KIND_HOURS — the AVAILABLE hours for that date; when a date has any
 *                hours rows, they REPLACE the weekly windows for that date
 *                (the slot engine and the GHL date-specific override both
 *                use them as that day's schedule).
 *
 * @property int $id
 * @property int $salon_id
 * @property int $user_id
 * @property TimeOffType $type
 * @property string $kind
 * @property string|null $note
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class TimeOff extends Model
{
    /** @use HasFactory<TimeOffFactory> */
    use BelongsToSalon, HasFactory;

    public const KIND_OFF = 'off';

    public const KIND_HOURS = 'hours';

    protected $table = 'time_off';

    protected $fillable = [
        'salon_id',
        'user_id',
        'type',
        'kind',
        'note',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TimeOffType::class,
        ];
    }

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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
