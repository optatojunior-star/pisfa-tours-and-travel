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
use App\Notifications\CarHire\SelfDriveApplicationReviewedNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReviewSelfDriveApplication
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        CarHireSelfDriveApplication $application,
        SelfDriveApplicationStatus $nextStatus,
        ?string $reason = null,
        ?string $internalNotes = null,
    ): CarHireSelfDriveApplication {
        if (! in_array($nextStatus, [
            SelfDriveApplicationStatus::NeedsInformation,
            SelfDriveApplicationStatus::Approved,
            SelfDriveApplicationStatus::Rejected,
        ], true)) {
            $this->invalid('status', 'Select a valid review outcome.');
        }

        $reason = $this->nullableString($reason);
        $internalNotes = $this->nullableString($internalNotes);

        Validator::make([
            'reason' => $reason,
            'internal_notes' => $internalNotes,
        ], [
            'reason' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        if (in_array($nextStatus, [
            SelfDriveApplicationStatus::NeedsInformation,
            SelfDriveApplicationStatus::Rejected,
        ], true) && $reason === null) {
            $this->invalid('reason', 'Enter a customer-safe reason for this review outcome.');
        }

        return DB::transaction(function () use (
            $actor,
            $application,
            $nextStatus,
            $reason,
            $internalNotes,
        ): CarHireSelfDriveApplication {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $booking = CarHireBooking::query()
                ->with('customer')
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

            if ($lockedApplication->status === $nextStatus && $lockedApplication->reviewed_at !== null) {
                return $lockedApplication;
            }

            if ($lockedApplication->status !== SelfDriveApplicationStatus::Submitted) {
                $this->invalid('status', 'Only a submitted self-drive application can be reviewed.');
            }

            if ($booking->status !== CarHireBookingStatus::Pending
                || ! $booking->hold_expires_at->isFuture()
                || ! $booking->pickup_at->isFuture()) {
                $this->invalid('application', 'This booking is no longer open for self-drive review.');
            }

            if ($nextStatus === SelfDriveApplicationStatus::Approved) {
                $this->assertApplicationComplete($booking, $lockedApplication);
            }

            $oldStatus = $lockedApplication->status;
            $reviewedAt = now();

            $lockedApplication->forceFill([
                'status' => $nextStatus,
                'reviewed_by_user_id' => $lockedActor->getKey(),
                'reviewed_at' => $reviewedAt,
                'review_reason' => $reason,
                'internal_review_notes' => $internalNotes,
                'originals_verified_at' => null,
                'originals_verified_by_user_id' => null,
            ])->save();

            // Deliberately exclude encrypted identity/permit numbers and the
            // private internal note from both audit values and notifications.
            $this->auditLogger->record(
                event: 'car_hire.self_drive_reviewed',
                auditable: $lockedApplication,
                oldValues: ['status' => $oldStatus->value],
                newValues: [
                    'status' => $nextStatus->value,
                    'booking_reference' => $booking->reference,
                    'reviewed_by_user_id' => $lockedActor->getKey(),
                    'reviewed_at' => $reviewedAt->toIso8601String(),
                    'has_internal_review_notes' => $internalNotes !== null,
                ],
                context: ['customer_safe_reason' => $reason],
                user: $lockedActor,
            );

            $customer = $booking->customer;

            DB::afterCommit(function () use ($customer, $booking, $nextStatus, $reason): void {
                $customer->notify(new SelfDriveApplicationReviewedNotification(
                    bookingReference: $booking->reference,
                    vehicleName: $booking->vehicle_name_snapshot,
                    status: $nextStatus,
                    reason: $reason,
                ));
            });

            return $lockedApplication->fresh([
                'booking.customer',
                'documents',
                'reviewedBy',
                'originalsVerifiedBy',
            ]);
        }, 3);
    }

    private function assertApplicationComplete(
        CarHireBooking $booking,
        CarHireSelfDriveApplication $application,
    ): void {
        foreach ([
            'national_id_number',
            'driving_permit_number',
            'date_of_birth',
            'driving_permit_issuing_country',
            'driving_permit_expires_on',
            'declaration_accepted_at',
            'submitted_at',
        ] as $field) {
            if (! filled($application->getAttribute($field))) {
                $this->invalid('application', 'The submitted self-drive application is incomplete.');
            }
        }

        if ($application->driving_permit_expires_on->toDateString() < $booking->return_at->toDateString()) {
            $this->invalid('application', 'The driving permit must remain valid through the return date.');
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
                $this->invalid('documents', 'Both required identity documents must be present before approval.');
            }
        }
    }
}
