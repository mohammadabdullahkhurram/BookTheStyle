<?php

namespace App\Models;

use Database\Factories\SalonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $agency_id
 * @property string $name
 * @property string $timezone
 * @property array|null $branding
 * @property string|null $ghl_location_id
 * @property string|null $ghl_token
 * @property bool $allow_walkins
 * @property bool $allow_same_day
 * @property int $max_advance_days
 * @property int $min_notice_minutes
 * @property array|null $feature_flags
 */
class Salon extends Model
{
    /** @use HasFactory<SalonFactory> */
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'name',
        'timezone',
        'branding',
        'ghl_location_id',
        'ghl_token',
        'allow_walkins',
        'allow_same_day',
        'max_advance_days',
        'min_notice_minutes',
        'feature_flags',
    ];

    protected $hidden = [
        'ghl_token',
    ];

    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'feature_flags' => 'array',
            // Encrypt the GHL Private Integration Token at rest (Security §9).
            'ghl_token' => 'encrypted',
            'allow_walkins' => 'boolean',
            'allow_same_day' => 'boolean',
            'max_advance_days' => 'integer',
            'min_notice_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<SalonMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(SalonMembership::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'salon_memberships')
            ->withPivot(['salon_role', 'staff_type', 'active'])
            ->withTimestamps();
    }

    /**
     * Whether a given feature flag is enabled for this salon.
     */
    public function hasFeature(string $flag): bool
    {
        return (bool) ($this->feature_flags[$flag] ?? false);
    }
}
