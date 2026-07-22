<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSalon;
use App\Support\Money;
use App\Support\ServicePalette;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A bookable service offered by a salon (SPEC §4). The price is display/
 * record only (integer cents; NULL = "price varies") — no payments anywhere.
 *
 * @property int $id
 * @property int $salon_id
 * @property string $name
 * @property int $duration_min
 * @property int|null $price_cents
 * @property string|null $color_key
 * @property bool $active
 * @property int $sort_order
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToSalon, HasFactory;

    protected $fillable = [
        'salon_id',
        'name',
        'duration_min',
        'price_cents',
        'color_key',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'duration_min' => 'integer',
            'price_cents' => 'integer',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The owner-controlled menu order: sort_order first (0 = never reordered,
     * so untouched menus keep their historical name ordering), name as the
     * stable tiebreak. Every service-LISTING surface uses this — widget
     * catalogue, admin list, booking pickers, filters.
     *
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * The display price in the given currency, or null when the price varies
     * / is not stated. Display only — never used to charge anything.
     */
    public function priceLabel(string $currency): ?string
    {
        return Money::format($this->price_cents, $currency);
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
