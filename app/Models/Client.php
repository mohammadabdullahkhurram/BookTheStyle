<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Carbon\CarbonImmutable;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A salon's client (SPEC §4). Salon-scoped. The profile fields (allergies,
 * formula notes, preferences) are all optional; allergies are safety-relevant
 * and surfaced prominently wherever the profile renders.
 *
 * @property int $id
 * @property int $salon_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $allergies
 * @property string|null $formula_notes
 * @property int|null $preferred_stylist_id
 * @property string|null $preferred_contact_method
 * @property CarbonImmutable|null $birthday
 * @property string|null $ghl_contact_id
 * @property string|null $ghl_pushed_hash
 * @property CarbonImmutable|null $ghl_pushed_at
 * @property CarbonImmutable|null $ghl_client_tagged_at
 * @property string|null $ghl_sync_status
 * @property string|null $ghl_sync_error
 * @property CarbonImmutable|null $updated_at
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToSalon, HasFactory;

    /** @var list<string> the offered preferred-contact options */
    public const CONTACT_METHODS = ['phone', 'text', 'email'];

    protected $fillable = [
        'salon_id',
        'name',
        'phone',
        'email',
        'allergies',
        'formula_notes',
        'preferred_stylist_id',
        'preferred_contact_method',
        'birthday',
        'ghl_contact_id',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'immutable_date',
        ];
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Staff notes, newest first.
     *
     * @return HasMany<ClientNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ClientNote::class)->latest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preferredStylist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_stylist_id');
    }
}
