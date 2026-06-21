<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use App\Support\ServicePalette;
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
 * @property string|null $color_key
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
        'color_key',
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
     * The service's curated colour triplet (bg/border/ink + solid dot), resolved
     * from its stored palette key. Calendar blocks and swatches colour by this.
     *
     * @return array{key: string, bg: string, border: string, ink: string, dot: string}
     */
    public function palette(): array
    {
        return ServicePalette::get($this->color_key);
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
            ->withPivot('salon_id', 'duration_override', 'buffer_override')
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
