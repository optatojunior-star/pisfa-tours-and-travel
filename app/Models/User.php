<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property UserRole $role
 * @property AccountStatus $status
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property DriverProfile|null $driverProfile
 * @property LoyaltyAccount|null $loyaltyAccount
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'status',
        'preferred_language',
        'preferred_currency',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

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
            'role' => UserRole::class,
            'status' => AccountStatus::class,
            'must_change_password' => 'boolean',
            'two_factor_required' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasRole(UserRole|string $role): bool
    {
        $role = is_string($role) ? UserRole::tryFrom($role) : $role;

        return $role !== null && $this->role === $role;
    }

    public function hasAnyRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function isActive(): bool
    {
        return $this->status === AccountStatus::Active;
    }

    public function canAccessAdministration(): bool
    {
        return $this->isActive() && $this->role->canAccessAdministration();
    }

    public function requiresTwoFactorAuthentication(): bool
    {
        $configuredRoles = config('security.two_factor.required_roles', []);

        return (bool) $this->getAttribute('two_factor_required') ||
            in_array($this->role->value, $configuredRoles, true);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by_user_id');
    }

    public function staffInvitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class, 'invited_by_user_id');
    }

    public function tourBookings(): HasMany
    {
        return $this->hasMany(TourBooking::class, 'customer_id');
    }

    public function createdTourPackages(): HasMany
    {
        return $this->hasMany(TourPackage::class, 'created_by_user_id');
    }

    public function updatedTourPackages(): HasMany
    {
        return $this->hasMany(TourPackage::class, 'updated_by_user_id');
    }

    public function assignedTourBookings(): HasMany
    {
        return $this->hasMany(TourBooking::class, 'assigned_driver_user_id');
    }

    public function tourAssignmentsAsDriver(): HasMany
    {
        return $this->hasMany(TourAssignment::class, 'driver_user_id');
    }

    public function tourAssignmentsMade(): HasMany
    {
        return $this->hasMany(TourAssignment::class, 'assigned_by_user_id');
    }

    public function tourUnassignmentsMade(): HasMany
    {
        return $this->hasMany(TourAssignment::class, 'unassigned_by_user_id');
    }

    public function cancelledTourBookings(): HasMany
    {
        return $this->hasMany(TourBooking::class, 'cancelled_by_user_id');
    }

    public function carHireBookings(): HasMany
    {
        return $this->hasMany(CarHireBooking::class, 'customer_id');
    }

    public function createdVehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'created_by_user_id');
    }

    public function updatedVehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'updated_by_user_id');
    }

    public function assignedCarHireBookings(): HasMany
    {
        return $this->hasMany(CarHireBooking::class, 'assigned_driver_user_id');
    }

    public function carHireAssignmentsAsDriver(): HasMany
    {
        return $this->hasMany(CarHireDriverAssignment::class, 'driver_user_id');
    }

    public function carHireAssignmentsMade(): HasMany
    {
        return $this->hasMany(CarHireDriverAssignment::class, 'assigned_by_user_id');
    }

    public function carHireUnassignmentsMade(): HasMany
    {
        return $this->hasMany(CarHireDriverAssignment::class, 'unassigned_by_user_id');
    }

    public function cancelledCarHireBookings(): HasMany
    {
        return $this->hasMany(CarHireBooking::class, 'cancelled_by_user_id');
    }

    public function airportTransferBookings(): HasMany
    {
        return $this->hasMany(AirportTransferBooking::class, 'customer_id');
    }

    public function assignedAirportTransferBookings(): HasMany
    {
        return $this->hasMany(AirportTransferBooking::class, 'assigned_driver_user_id');
    }

    public function airportTransferAssignmentsAsDriver(): HasMany
    {
        return $this->hasMany(AirportTransferAssignment::class, 'driver_user_id');
    }

    public function airportTransferAssignmentsMade(): HasMany
    {
        return $this->hasMany(AirportTransferAssignment::class, 'assigned_by_user_id');
    }

    public function airportTransferUnassignmentsMade(): HasMany
    {
        return $this->hasMany(AirportTransferAssignment::class, 'unassigned_by_user_id');
    }

    public function cancelledAirportTransferBookings(): HasMany
    {
        return $this->hasMany(AirportTransferBooking::class, 'cancelled_by_user_id');
    }

    /** @return HasOne<DriverProfile, $this> */
    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    /** @return HasOne<LoyaltyAccount, $this> */
    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(LoyaltyAccount::class);
    }

    public function referralReceived(): HasOne
    {
        return $this->hasOne(Referral::class, 'referred_user_id');
    }
}
