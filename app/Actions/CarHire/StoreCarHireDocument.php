<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\CarHireDocumentType;
use App\Enums\HireMode;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireDocument;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StoreCarHireDocument
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $customer,
        CarHireBooking $booking,
        CarHireDocumentType $type,
        UploadedFile $file,
    ): CarHireDocument {
        $this->validateFile($file, $type);
        $disk = (string) config('car_hire.documents.disk', 'local');
        $extension = $this->canonicalExtension((string) $file->getMimeType());
        $path = 'car-hire/'.$booking->reference.'/'.Str::uuid().'.'.$extension;
        $contentHash = hash_file('sha256', $file->getRealPath());

        if (! is_string($contentHash) || ! Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => 'private'],
        )) {
            throw new RuntimeException('The private document could not be stored.');
        }

        $oldFile = null;

        try {
            $document = DB::transaction(function () use (
                $customer,
                $booking,
                $type,
                $file,
                $disk,
                $path,
                $contentHash,
                &$oldFile,
            ): CarHireDocument {
                $lockedCustomer = User::query()->whereKey($customer->getKey())->lockForUpdate()->first();
                $lockedBooking = CarHireBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
                $application = $lockedBooking->selfDriveApplication()->lockForUpdate()->first();

                if ($lockedCustomer === null
                    || $lockedCustomer->status !== AccountStatus::Active
                    || ! $lockedCustomer->hasRole(UserRole::Customer)
                    || $lockedBooking->customer_id !== $lockedCustomer->getKey()) {
                    throw new AuthorizationException;
                }

                if ($lockedBooking->hire_mode !== HireMode::SelfDrive
                    || $lockedBooking->status !== CarHireBookingStatus::Pending
                    || ! $lockedBooking->hold_expires_at->isFuture()
                    || $application === null
                    || ! $application->status->isCustomerEditable()) {
                    $this->invalid('document', 'Documents can no longer be changed for this application.');
                }

                $existing = CarHireDocument::query()
                    ->where('car_hire_booking_id', $lockedBooking->getKey())
                    ->where('document_type', $type->value)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $oldFile = ['disk' => $existing->disk, 'path' => $existing->path];
                }

                $document = CarHireDocument::query()->updateOrCreate(
                    [
                        'car_hire_booking_id' => $lockedBooking->getKey(),
                        'document_type' => $type->value,
                    ],
                    [
                        'disk' => $disk,
                        'path' => $path,
                        'original_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                        'mime_type' => (string) $file->getMimeType(),
                        'size_bytes' => (int) $file->getSize(),
                        'content_sha256' => $contentHash,
                        'uploaded_by_user_id' => $lockedCustomer->getKey(),
                    ],
                );

                $this->auditLogger->record(
                    event: $existing === null ? 'car_hire.document_uploaded' : 'car_hire.document_replaced',
                    auditable: $document,
                    newValues: [
                        'booking_reference' => $lockedBooking->reference,
                        'document_type' => $type->value,
                        'mime_type' => $document->mime_type,
                        'size_bytes' => $document->size_bytes,
                    ],
                    user: $lockedCustomer,
                );

                return $document;
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        if (is_array($oldFile) && ($oldFile['disk'] !== $disk || $oldFile['path'] !== $path)) {
            Storage::disk($oldFile['disk'])->delete($oldFile['path']);
        }

        return $document;
    }

    private function validateFile(UploadedFile $file, CarHireDocumentType $type): void
    {
        if (! $file->isValid() || $file->getSize() < 1) {
            $this->invalid('file', 'Select a valid non-empty document.');
        }

        $maximumBytes = (int) config('car_hire.documents.maximum_kilobytes', 5120) * 1024;

        if ($file->getSize() > $maximumBytes) {
            $this->invalid('file', 'The document exceeds the configured size limit.');
        }

        $mime = (string) $file->getMimeType();
        $allowedMimes = $type === CarHireDocumentType::ApplicantPhoto
            ? ['image/jpeg', 'image/png']
            : ['application/pdf', 'image/jpeg', 'image/png'];

        if (! in_array($mime, $allowedMimes, true)) {
            $this->invalid('file', 'Upload a genuine PDF, JPEG, or PNG document.');
        }

        $clientExtension = mb_strtolower((string) $file->getClientOriginalExtension());
        $allowedExtensions = match ($mime) {
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            default => [],
        };

        if (! in_array($clientExtension, $allowedExtensions, true)) {
            $this->invalid('file', 'The document extension does not match its detected content.');
        }
    }

    private function canonicalExtension(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new RuntimeException('Unsupported private document type.'),
        };
    }
}
