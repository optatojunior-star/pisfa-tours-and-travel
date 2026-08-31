<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\CarHireDocumentType;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireSelfDriveApplication;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SaveSelfDriveApplication
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        User $customer,
        CarHireBooking $booking,
        array $attributes,
        bool $submit = false,
    ): CarHireSelfDriveApplication {
        $input = $this->validatedInput($attributes, $submit);

        return DB::transaction(function () use ($customer, $booking, $input, $submit): CarHireSelfDriveApplication {
            $lockedCustomer = User::query()->whereKey($customer->getKey())->lockForUpdate()->first();
            $lockedBooking = CarHireBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $application = CarHireSelfDriveApplication::query()
                ->where('car_hire_booking_id', $lockedBooking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCustomer === null
                || $lockedCustomer->status !== AccountStatus::Active
                || ! $lockedCustomer->hasRole(UserRole::Customer)
                || $lockedCustomer->email_verified_at === null
                || $lockedBooking->customer_id !== $lockedCustomer->getKey()) {
                throw new AuthorizationException;
            }

            if ($lockedBooking->hire_mode !== HireMode::SelfDrive
                || $lockedBooking->status !== CarHireBookingStatus::Pending
                || ! $lockedBooking->hold_expires_at->isFuture()) {
                $this->invalid('application', 'This self-drive application can no longer be changed.');
            }

            if (! $application->status->isCustomerEditable()) {
                $this->invalid('application', 'This application is currently under review or already closed.');
            }

            if ($submit) {
                $documentTypes = $lockedBooking->documents()
                    ->whereIn('document_type', [
                        CarHireDocumentType::NationalId->value,
                        CarHireDocumentType::DrivingPermit->value,
                    ])
                    ->toBase()
                    ->pluck('document_type')
                    ->all();

                foreach ([CarHireDocumentType::NationalId, CarHireDocumentType::DrivingPermit] as $requiredType) {
                    if (! in_array($requiredType->value, $documentTypes, true)) {
                        $this->invalid('documents', 'Upload both the National ID and driving permit before submission.');
                    }
                }

                if ($input['driving_permit_expires_on'] < $lockedBooking->return_at->toDateString()) {
                    $this->invalid('driving_permit_expires_on', 'The driving permit must remain valid through the vehicle return date.');
                }
            }

            $oldStatus = $application->status;
            $application->forceFill([
                'national_id_number' => $input['national_id_number'],
                'driving_permit_number' => $input['driving_permit_number'],
                'date_of_birth' => $input['date_of_birth'],
                'driving_permit_issuing_country' => $input['driving_permit_issuing_country'],
                'driving_permit_class' => $input['driving_permit_class'],
                'driving_permit_issued_on' => $input['driving_permit_issued_on'],
                'driving_permit_expires_on' => $input['driving_permit_expires_on'],
                'status' => $submit ? SelfDriveApplicationStatus::Submitted : $application->status,
                'declaration_accepted_at' => $submit ? now() : $application->declaration_accepted_at,
                'submitted_at' => $submit ? now() : $application->submitted_at,
                'reviewed_by_user_id' => null,
                'reviewed_at' => null,
                'review_reason' => null,
                'internal_review_notes' => null,
            ])->save();

            $this->auditLogger->record(
                event: $submit ? 'car_hire.self_drive_submitted' : 'car_hire.self_drive_saved',
                auditable: $application,
                oldValues: ['status' => $oldStatus->value],
                newValues: [
                    'status' => $application->status->value,
                    'booking_reference' => $lockedBooking->reference,
                    'document_types_complete' => $submit,
                    'declaration_accepted_at' => $application->declaration_accepted_at?->toIso8601String(),
                ],
                user: $lockedCustomer,
            );

            return $application->fresh(['booking', 'reviewedBy', 'originalsVerifiedBy']);
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function validatedInput(array $attributes, bool $submit): array
    {
        $required = $submit ? 'required' : 'nullable';
        $validated = Validator::make($attributes, [
            'national_id_number' => [$required, 'string', 'max:120'],
            'driving_permit_number' => [$required, 'string', 'max:120'],
            'date_of_birth' => [$required, 'date', 'before:today'],
            'driving_permit_issuing_country' => [$required, 'string', 'max:100'],
            'driving_permit_class' => ['nullable', 'string', 'max:40'],
            'driving_permit_issued_on' => ['nullable', 'date', 'before_or_equal:today'],
            'driving_permit_expires_on' => [$required, 'date', 'after:today'],
            'declaration_accepted' => [$submit ? 'required' : 'nullable', 'accepted'],
        ])->validate();

        foreach ([
            'national_id_number',
            'driving_permit_number',
            'driving_permit_issuing_country',
            'driving_permit_class',
            'driving_permit_issued_on',
            'driving_permit_expires_on',
            'date_of_birth',
        ] as $field) {
            $validated[$field] = $this->nullableString($validated[$field] ?? null);
        }

        if (filled($validated['driving_permit_issued_on'] ?? null)
            && filled($validated['driving_permit_expires_on'] ?? null)
            && $validated['driving_permit_expires_on'] <= $validated['driving_permit_issued_on']) {
            $this->invalid('driving_permit_expires_on', 'The permit expiry must be after its issue date.');
        }

        return $validated;
    }
}
