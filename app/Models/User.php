<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AgencyRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $agency_id
 * @property string $name
 * @property string $email
 * @property AgencyRole|null $agency_role
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $must_change_password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'agency_id', 'agency_role', 'must_change_password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'agency_role' => AgencyRole::class,
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * The agency this user is staff of (if any).
     *
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return HasMany<SalonMembership, $this>
     */
    public function salonMemberships(): HasMany
    {
        return $this->hasMany(SalonMembership::class);
    }

    /**
     * Salons this user belongs to via membership.
     *
     * @return BelongsToMany<Salon, $this>
     */
    public function salons(): BelongsToMany
    {
        return $this->belongsToMany(Salon::class, 'salon_memberships')
            ->withPivot(['salon_role', 'staff_type', 'active'])
            ->withTimestamps();
    }

    /**
     * The user's active membership for a salon, or null if none.
     */
    public function membershipFor(int|Salon $salon): ?SalonMembership
    {
        $salonId = $salon instanceof Salon ? $salon->id : $salon;

        return $this->salonMemberships()
            ->where('salon_id', $salonId)
            ->where('active', true)
            ->first();
    }

    /**
     * Whether the user has an active membership for the given salon. Agency
     * owners/admins implicitly reach any salon within their own agency.
     */
    public function belongsToSalon(Salon $salon): bool
    {
        if ($this->agency_id === $salon->agency_id && $this->agency_role?->isPrivileged()) {
            return true;
        }

        return $this->membershipFor($salon) !== null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
