<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SalonGhlConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-salon GoHighLevel connection credentials (one-to-one with a salon).
 *
 * The Private Integration Token is encrypted at rest via the `encrypted` cast
 * and hidden from array/JSON output. It is deliberately kept out of `$fillable`
 * so it can never be set by mass assignment — callers set it explicitly through
 * the UpdateGhlConnection action (which also leaves it unchanged on a blank
 * input). This model is reached only through `Salon::ghlConnection`, so it does
 * not need the active-salon global scope.
 *
 * @property int $id
 * @property int $salon_id
 * @property string|null $location_id
 * @property string|null $private_integration_token
 * @property string|null $calendar_id
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $last_verified_at
 */
class SalonGhlConnection extends Model
{
    /** @use HasFactory<SalonGhlConnectionFactory> */
    use HasFactory;

    /**
     * The token is intentionally excluded — it is set explicitly, never via
     * mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'location_id',
        'calendar_id',
        'connected_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'private_integration_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Ciphertext in the DB; decrypted transparently when read server-side.
            'private_integration_token' => 'encrypted',
            'connected_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Salon, $this>
     */
    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /**
     * Whether a usable token is stored (without exposing it).
     */
    public function hasToken(): bool
    {
        return filled($this->private_integration_token);
    }

    /**
     * A connection is "connected" only when all three pieces are present:
     * location id + token + master calendar id. Anything less is incomplete.
     */
    public function isConnected(): bool
    {
        return filled($this->location_id)
            && filled($this->calendar_id)
            && $this->hasToken();
    }

    /**
     * Status keyword for the UI: connected / incomplete / not connected.
     */
    public function status(): string
    {
        if ($this->isConnected()) {
            return 'connected';
        }

        if (filled($this->location_id) || filled($this->calendar_id) || $this->hasToken()) {
            return 'incomplete';
        }

        return 'not_connected';
    }
}
