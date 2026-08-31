<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A driver's licence, availability, and emergency contact.
 *
 * @property bool $is_available
 * @property string|null $licence_number
 * @property CarbonImmutable|null $licence_expires_at
 * @property CarbonImmutable|null $medical_expires_at
 * @property User|null $user
 */
class DriverProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'licence_number',
        'licence_class',
        'licence_expires_at',
        'medical_expires_at',
        'years_experience',
        'emergency_contact_name',
        'emergency_contact_phone',
        'is_available',
        'unavailable_reason',
        'internal_notes',
        'expiry_alert_sent_at',
    ];

    /**
     * The licence number identifies a person to a regulator. It is never part
     * of a serialised payload, and internal notes are equally internal.
     */
    protected $hidden = ['licence_number', 'internal_notes'];

    protected function casts(): array
    {
        return [
            // Encrypted at rest, like every other identity document reference.
            'licence_number' => 'encrypted',
            'licence_expires_at' => 'immutable_date',
            'medical_expires_at' => 'immutable_date',
            'years_experience' => 'integer',
            'is_available' => 'boolean',
            'expiry_alert_sent_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    public function licenceHasExpired(?DateTimeInterface $at = null): bool
    {
        return $this->licence_expires_at !== null
            && $this->licence_expires_at->endOfDay()->isBefore($at ?? now());
    }

    public function licenceExpiresWithin(int $days, ?DateTimeInterface $at = null): bool
    {
        if ($this->licence_expires_at === null) {
            return false;
        }

        $horizon = CarbonImmutable::parse($at ?? now())->addDays($days);

        return $this->licence_expires_at->endOfDay()->isBefore($horizon);
    }

    /**
     * Whether the driver may be given work right now.
     *
     * An expired licence disqualifies regardless of the availability flag: no
     * office toggle should be able to put an unlicensed driver on the road.
     */
    public function canBeAssigned(?DateTimeInterface $at = null): bool
    {
        return $this->is_available && ! $this->licenceHasExpired($at);
    }

    /** The last four characters only, so a screen never shows the whole number. */
    public function maskedLicence(): string
    {
        $number = (string) $this->licence_number;

        if ($number === '') {
            return 'Not recorded';
        }

        return str_repeat('•', max(0, mb_strlen($number) - 4)).mb_substr($number, -4);
    }
}
