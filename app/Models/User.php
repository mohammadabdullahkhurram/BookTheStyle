<?php

namespace App\Models;

use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
     * Password resets use the branded, queued notification (app-direct mail;
     * login-critical, so never routed through GHL).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
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
     * The user's personal ICS calendar feed credential (Phase 5), if any.
     *
     * @return HasOne<CalendarConnection, $this>
     */
    public function calendarConnection(): HasOne
    {
        return $this->hasOne(CalendarConnection::class);
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
     * Salons an agency_user has been explicitly assigned to (their access
     * scope). Empty for agency owners/admins, who reach every salon in their
     * agency without an assignment row.
     *
     * @return BelongsToMany<Salon, $this>
     */
    public function assignedSalons(): BelongsToMany
    {
        return $this->belongsToMany(Salon::class, 'agency_salon_assignments')
            ->withTimestamps();
    }

    /**
     * Whether the user is an agency owner or admin (near-full agency reach).
     */
    public function isAgencyOperator(): bool
    {
        return $this->agency_role?->isPrivileged() ?? false;
    }

    /**
     * Whether the user currently has reach into ANY salon: an active salon
     * membership, agency operator rights, or (for agency_users) at least one
     * assigned salon. Login diagnostics key off this — no reach plus existing
     * (inactive) memberships means the account was deactivated; no reach and
     * no memberships means access was never granted.
     */
    public function hasAnySalonAccess(): bool
    {
        if ($this->isAgencyOperator()) {
            return true;
        }

        if ($this->agency_role === AgencyRole::User && $this->assignedSalons()->exists()) {
            return true;
        }

        return $this->salonMemberships()->where('active', true)->exists();
    }

    /**
     * Self-service account deletion (SPEC §2 deletion rules): a SALON OWNER
     * may delete their own account; salon admins and staff may not — their
     * accounts are salon-managed. The agency owner never may (the singleton
     * operator account must not orphan the agency). Users with no salon
     * memberships (agency admins/users) keep self-deletion.
     */
    public function canDeleteOwnAccount(): bool
    {
        if ($this->agency_role === AgencyRole::Owner) {
            return false;
        }

        return ! $this->salonMemberships()
            ->where('salon_role', '!=', SalonRole::Owner->value)
            ->exists();
    }

    /**
     * Whether the user acts as an agency operator for the given salon: an
     * agency owner/admin in the salon's agency, or an agency_user assigned to
     * it. This is agency-side reach, distinct from a salon membership.
     */
    public function operatesSalon(Salon $salon): bool
    {
        if ($this->agency_id === null || $this->agency_id !== $salon->agency_id) {
            return false;
        }

        if ($this->isAgencyOperator()) {
            return true;
        }

        if ($this->agency_role === AgencyRole::User) {
            return $this->assignedSalons()->whereKey($salon->id)->exists();
        }

        return false;
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
     * The user's active membership for a salon when they are a stylist there,
     * or null. Used to gate "edit my own availability".
     */
    public function stylistMembershipFor(Salon $salon): ?SalonMembership
    {
        $membership = $this->membershipFor($salon);

        return $membership?->staff_type === StaffType::Stylist ? $membership : null;
    }

    /**
     * Whether the user may reach the given salon at all: an active salon
     * membership, or agency-side operator reach (owner/admin in the agency, or
     * an agency_user assigned to it). This is the gate ResolveSalon enforces.
     */
    public function belongsToSalon(Salon $salon): bool
    {
        return $this->operatesSalon($salon) || $this->membershipFor($salon) !== null;
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
