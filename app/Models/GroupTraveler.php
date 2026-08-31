<?php

namespace App\Models;

use App\Enums\TourTravelerType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One name on a group manifest.
 *
 * `user_id` is nullable because most people on a forty-person list have no
 * account, and demanding one would make the list impossible to assemble.
 *
 * @property TourTravelerType $traveler_type
 * @property string $full_name
 * @property int $group_booking_id
 * @property int|null $user_id
 * @property string|null $identity_document
 * @property string|null $contact_email
 * @property CarbonImmutable|null $date_of_birth
 * @property GroupBooking|null $booking
 */
class GroupTraveler extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_booking_id',
        'user_id',
        'full_name',
        'traveler_type',
        'contact_phone',
        'contact_email',
        'identity_document',
        'date_of_birth',
        'nationality',
        'dietary_requirements',
        'accessibility_needs',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ];

    /**
     * Identity documents and dates of birth are never serialised.
     *
     * They are collected for park permits and border crossings, not for
     * anything that renders a traveller as JSON.
     */
    protected $hidden = [
        'identity_document',
        'date_of_birth',
    ];

    protected function casts(): array
    {
        return [
            'traveler_type' => TourTravelerType::class,
            'date_of_birth' => 'immutable_date',
        ];
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(set: static fn (mixed $v): ?string => filled($v)
            ? mb_strtolower(trim((string) $v))
            : null);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(GroupBooking::class, 'group_booking_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether anything has been recorded that the operations team must act on. */
    public function hasSpecialRequirements(): bool
    {
        return filled($this->dietary_requirements) || filled($this->accessibility_needs);
    }
}
