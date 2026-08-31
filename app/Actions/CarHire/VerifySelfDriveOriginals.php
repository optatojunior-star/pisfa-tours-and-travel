<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\CarHireBookingStatus;
use App\Enums\CarHireDocumentType;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Models\CarHireBooking;
use App\Models\CarHireSelfDriveApplication;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class VerifySelfDriveOriginals
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        CarHireSelfDriveApplication $application,
    ): CarHireSelfDriveApplication {
        return DB::transaction(function () use ($actor, $application): CarHireSelfDriveApplication {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $booking = CarHireBooking::query()
                ->whereKey($application->car_hire_booking_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedApplication = CarHireSelfDriveApplication::query()
                ->whereKey($application->getKey())
                ->where('car_hire_booking_id', $booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->hire_mode !== HireMode::SelfDrive) {
                $this->invalid('application', 'This booking is not a self-drive request.');
            }

            if ($lockedApplication->originals_verified_at !== null) {
                return $lockedApplication;
            }

            if ($booking->status !== CarHireBookingStatus::Confirmed
                || ! $booking->return_at->isFuture()) {
                $this->invalid(
                    'application',
                    'Original documents can be verified only for an active, confirmed booking before its return time.',
                );
            }

            if ($lockedApplication->status !== SelfDriveApplicationStatus::Approved) {
                $this->invalid('application', 'Approve the self-drive application before verifying originals.');
            }

            foreach ([
                'national_id_number',
                'driving_permit_number',
                'date_of_birth',
                'driving_permit_issuing_country',
                'driving_permit_expires_on',
            ] as $field) {
                if (! filled($lockedApplication->getAttribute($field))) {
                    $this->invalid('application', 'The approved self-drive application is incomplete.');
                }
            }

            if ($lockedApplication->driving_permit_expires_on->toDateString() < $booking->return_at->toDateString()) {
                $this->invalid('application', 'The driving permit does not remain valid through the return date.');
            }

            $documentTypes = $booking->documents()
                ->whereIn('document_type', [
                    CarHireDocumentType::NationalId->value,
                    CarHireDocumentType::DrivingPermit->value,
                ])
                ->pluck('document_type')
                ->map(static fn (CarHireDocumentType|string $type): string => $type instanceof CarHireDocumentType
                    ? $type->value
                    : $type)
                ->all();

            foreach ([CarHireDocumentType::NationalId, CarHireDocumentType::DrivingPermit] as $requiredType) {
                if (! in_array($requiredType->value, $documentTypes, true)) {
                    $this->invalid('documents', 'Both required original documents must be available for verification.');
                }
            }

            $verifiedAt = now();

            $lockedApplication->forceFill([
                'originals_verified_at' => $verifiedAt,
                'originals_verified_by_user_id' => $lockedActor->getKey(),
            ])->save();

            // Never write identity numbers, permit numbers, document paths or
            // private review notes to the audit stream.
            $this->auditLogger->record(
                event: 'car_hire.self_drive_originals_verified',
                auditable: $lockedApplication,
                newValues: [
                    'booking_reference' => $booking->reference,
                    'verified_by_user_id' => $lockedActor->getKey(),
                    'verified_at' => $verifiedAt->toIso8601String(),
                    'required_document_types_verified' => [
                        CarHireDocumentType::NationalId->value,
                        CarHireDocumentType::DrivingPermit->value,
                    ],
                ],
                user: $lockedActor,
            );

            return $lockedApplication->fresh([
                'booking.customer',
                'documents',
                'reviewedBy',
                'originalsVerifiedBy',
            ]);
        }, 3);
    }
}
