<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A bookable service offered by a salon (SPEC §4). No price — scheduling only.
 *
 * @property int $id
 * @property int $salon_id
 * @property string $name
 * @property int $duration_min
 * @property string $color
 * @property bool $active
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'name',
        'duration_min',
        'color',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'duration_min' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * Stylists qualified to perform this service.
     *
     * @return BelongsToMany<User, $this, ServiceStylist>
     */
    public function stylists(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'service_stylist')
            ->using(ServiceStylist::class)
            ->withPivot('salon_id')
            ->withTimestamps();
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
