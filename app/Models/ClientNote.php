<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Carbon\CarbonImmutable;
use Database\Factories\ClientNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A timestamped staff note on a client ("prefers cooler tones", "runs
 * late"). Salon-scoped; the author is kept for attribution and survives
 * staff removal as null.
 *
 * @property int $id
 * @property int $salon_id
 * @property int $client_id
 * @property int|null $author_id
 * @property string $body
 * @property CarbonImmutable $created_at
 */
class ClientNote extends Model
{
    /** @use HasFactory<ClientNoteFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'client_id',
        'author_id',
        'body',
    ];

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
