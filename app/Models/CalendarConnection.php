<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's personal ICS calendar feed credential (Phase 5). Holds the SHA-256
 * hash of the feed token (never the plaintext), so the subscribe URL is a
 * bearer secret that cannot be recovered from the database. `token_hash` null
 * means the user has no active feed (never generated, or revoked).
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $token_hash
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_client
 * @property int $fetch_count
 */
class CalendarConnection extends Model
{
    protected $fillable = [
        'last_used_at',
        'last_client',
        'fetch_count',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'fetch_count' => 'integer',
        ];
    }

    public function hasFeed(): bool
    {
        return filled($this->token_hash);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
