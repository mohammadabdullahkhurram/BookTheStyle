<?php

namespace App\Models;

use App\Enums\StaffType;
use Database\Factories\SalonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $agency_id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property bool $active
 * @property array<string, mixed>|null $branding
 * @property string $legal_business_name
 * @property string $business_email
 * @property string $business_phone
 * @property string|null $website
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $city
 * @property string $region
 * @property string $postal_code
 * @property string $country
 * @property string $contact_name
 * @property string $contact_email
 * @property string $contact_phone
 * @property bool $allow_walkins
 * @property bool $allow_same_day
 * @property int $max_advance_days
 * @property int $min_notice_minutes
 * @property bool $auto_no_show
 * @property int $auto_no_show_grace_minutes
 * @property bool $auto_complete
 * @property array<string, mixed>|null $feature_flags
 */
class Salon extends Model
{
    /** @use HasFactory<SalonFactory> */
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'name',
        'slug',
        'timezone',
        'active',
        'branding',
        'legal_business_name',
        'business_email',
        'business_phone',
        'website',
        'address_line1',
        'address_line2',
        'city',
        'region',
        'postal_code',
        'country',
        'contact_name',
        'contact_email',
        'contact_phone',
        'allow_walkins',
        'allow_same_day',
        'max_advance_days',
        'min_notice_minutes',
        'auto_no_show',
        'auto_no_show_grace_minutes',
        'auto_complete',
        'feature_flags',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'branding' => 'array',
            'feature_flags' => 'array',
            'allow_walkins' => 'boolean',
            'allow_same_day' => 'boolean',
            'max_advance_days' => 'integer',
            'min_notice_minutes' => 'integer',
            'auto_no_show' => 'boolean',
            'auto_no_show_grace_minutes' => 'integer',
            'auto_complete' => 'boolean',
        ];
    }

    /**
     * Salons are addressed by their subdomain slug everywhere in the app — the
     * salon subdomain ({slug}.{app.domain}) and the agency console both bind and
     * generate URLs by slug rather than id. Slugs are globally unique.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
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
     * Agency users explicitly scoped to this salon (agency_owner/admin reach
     * every salon implicitly and are not listed here).
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignedAgencyUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'agency_salon_assignments')
            ->withTimestamps();
    }

    /**
     * The salon's GoHighLevel connection credentials (one-to-one). Optional —
     * a salon may have no row until it is connected. The token it holds is
     * encrypted at rest; see SalonGhlConnection.
     *
     * @return HasOne<SalonGhlConnection, $this>
     */
    public function ghlConnection(): HasOne
    {
        return $this->hasOne(SalonGhlConnection::class);
    }

    /**
     * Whether the salon has a complete GHL connection (location + token +
     * calendar). Without exposing the token.
     */
    public function ghlConnected(): bool
    {
        return $this->ghlConnection?->isConnected() ?? false;
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Active stylists in this salon (members with staff_type = stylist). These
     * are the users eligible for service assignment and availability.
     *
     * @return BelongsToMany<User, $this>
     */
    public function stylistUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'salon_memberships')
            ->wherePivot('staff_type', StaffType::Stylist->value)
            ->wherePivot('active', true)
            ->withPivot(['salon_role', 'staff_type', 'active'])
            ->withTimestamps();
    }

    /**
     * Whether a given feature flag is enabled for this salon.
     */
    public function hasFeature(string $flag): bool
    {
        return (bool) (($this->feature_flags ?? [])[$flag] ?? false);
    }

    /**
     * The per-salon accent override (a hex color) if branding sets one.
     */
    public function accentColor(): ?string
    {
        $accent = $this->branding['accent'] ?? null;

        return is_string($accent) && $accent !== '' ? $accent : null;
    }
}
