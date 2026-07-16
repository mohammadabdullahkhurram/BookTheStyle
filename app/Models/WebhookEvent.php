<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inbound GHL webhook log (SPEC §4): the raw payload + a body hash for
 * replay dedupe, and the processing outcome — including 'review' for events
 * that could not be applied cleanly (never silently dropped). salon_id is
 * nullable so even unresolvable payloads leave an audit trail; reads must
 * scope by salon explicitly (this table backs a no-session system endpoint,
 * so it does not use the active-salon global scope).
 *
 * @property int $id
 * @property int|null $salon_id
 * @property string|null $event_type
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string $status
 * @property string|null $note
 * @property CarbonImmutable|null $processed_at
 */
class WebhookEvent extends Model
{
    use Prunable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CREATED_BOOKING = 'created_booking';

    public const STATUS_CREATED_CLIENT = 'created_client';

    public const STATUS_IGNORED_UNTAGGED = 'ignored_untagged';

    public const STATUS_IGNORED_ECHO = 'ignored_echo';

    public const STATUS_IGNORED_STALE = 'ignored_stale';

    public const STATUS_IGNORED_REPLAY = 'ignored_replay';

    public const STATUS_REVIEW = 'review';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'salon_id',
        'event_type',
        'payload',
        'payload_hash',
        'status',
        'note',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Salon, $this>
     */
    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function conclude(string $status, ?string $note = null): void
    {
        $this->forceFill([
            'status' => $status,
            'note' => $note === null ? null : mb_substr($note, 0, 500),
            'processed_at' => now(),
        ])->save();
    }

    /**
     * Retention: the daily model:prune schedule removes rows older than
     * config('ghl.webhook_retention_days'). Safe because every runtime read
     * is short-window or current-row — replay dedupe looks back ONE hour,
     * jobs conclude the row they were dispatched for, reconcile creates
     * fresh rows, and echo-loop state lives on bookings/clients columns.
     * PENDING rows are kept regardless of age: a still-queued job may not
     * have processed them yet, and an unprocessed event must never vanish.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', '!=', self::STATUS_PENDING)
            ->where('created_at', '<', now()->subDays((int) config('ghl.webhook_retention_days')));
    }
}
