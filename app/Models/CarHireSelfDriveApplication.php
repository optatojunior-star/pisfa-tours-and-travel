<?php

namespace App\Models;

use App\Enums\SelfDriveApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarHireSelfDriveApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'car_hire_booking_id',
        'status',
        'national_id_number',
        'driving_permit_number',
        'date_of_birth',
        'driving_permit_issuing_country',
        'driving_permit_class',
        'driving_permit_issued_on',
        'driving_permit_expires_on',
        'declaration_accepted_at',
        'submitted_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'review_reason',
        'internal_review_notes',
        'originals_verified_at',
        'originals_verified_by_user_id',
    ];

    protected $hidden = [
        'national_id_number',
        'driving_permit_number',
        'internal_review_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => SelfDriveApplicationStatus::class,
            'national_id_number' => 'encrypted',
            'driving_permit_number' => 'encrypted',
            'date_of_birth' => 'immutable_date',
            'driving_permit_issued_on' => 'immutable_date',
            'driving_permit_expires_on' => 'immutable_date',
            'declaration_accepted_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'originals_verified_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CarHireBooking::class, 'car_hire_booking_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function originalsVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'originals_verified_by_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(
            CarHireDocument::class,
            'car_hire_booking_id',
            'car_hire_booking_id',
        )->orderBy('document_type');
    }

    public function isCustomerEditable(): bool
    {
        return $this->status->isCustomerEditable();
    }

    public function maskedNationalIdNumber(): ?string
    {
        return $this->maskIdentifier($this->national_id_number);
    }

    public function maskedDrivingPermitNumber(): ?string
    {
        return $this->maskIdentifier($this->driving_permit_number);
    }

    private function maskIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) <= 4) {
            return '••••';
        }

        return '•••• '.mb_substr($value, -4);
    }
}
