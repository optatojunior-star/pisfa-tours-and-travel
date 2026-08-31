<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\UserRole;
use App\Models\CarHireDocument;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteCarHireDocument
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(User $customer, CarHireDocument $document): void
    {
        [$disk, $path] = DB::transaction(function () use ($customer, $document): array {
            $lockedCustomer = User::query()->whereKey($customer->getKey())->lockForUpdate()->first();
            $lockedDocument = CarHireDocument::query()
                ->with('booking.selfDriveApplication')
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $booking = $lockedDocument->booking;

            if ($lockedCustomer === null
                || $lockedCustomer->status !== AccountStatus::Active
                || ! $lockedCustomer->hasRole(UserRole::Customer)
                || $booking->customer_id !== $lockedCustomer->getKey()) {
                throw new AuthorizationException;
            }

            if ($booking->status !== CarHireBookingStatus::Pending
                || ! $booking->hold_expires_at->isFuture()
                || ! $booking->selfDriveApplication?->status->isCustomerEditable()) {
                $this->invalid('document', 'This document can no longer be removed.');
            }

            $metadata = [$lockedDocument->disk, $lockedDocument->path];

            $this->auditLogger->record(
                event: 'car_hire.document_deleted',
                auditable: $lockedDocument,
                oldValues: [
                    'booking_reference' => $booking->reference,
                    'document_type' => $lockedDocument->document_type->value,
                    'mime_type' => $lockedDocument->mime_type,
                    'size_bytes' => $lockedDocument->size_bytes,
                ],
                user: $lockedCustomer,
            );

            $lockedDocument->delete();

            return $metadata;
        }, 3);

        Storage::disk($disk)->delete($path);
    }
}
