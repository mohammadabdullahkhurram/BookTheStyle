<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A salon's client (SPEC §4). Salon-scoped; ghl_contact_id is reserved for
 * Phase 6.
 *
 * @property int $id
 * @property int $salon_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $ghl_contact_id
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'name',
        'phone',
        'email',
        'ghl_contact_id',
    ];

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
