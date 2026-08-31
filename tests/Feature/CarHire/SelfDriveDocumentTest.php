<?php

namespace Tests\Feature\CarHire;

use App\Actions\CarHire\DeleteCarHireDocument;
use App\Actions\CarHire\SaveSelfDriveApplication;
use App\Actions\CarHire\StoreCarHireDocument;
use App\Enums\CarHireBookingStatus;
use App\Enums\CarHireDocumentType;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Models\CarHireDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class SelfDriveDocumentTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-20 09:00:00');
        Storage::fake('local');
        config()->set('car_hire.documents.disk', 'local');
    }

    public function test_owner_upload_is_stored_privately_with_server_owned_metadata(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, mode: HireMode::SelfDrive);
        $file = UploadedFile::fake()->create('national-id.pdf', 64, 'application/pdf');

        $document = app(StoreCarHireDocument::class)->execute(
            $customer,
            $booking,
            CarHireDocumentType::NationalId,
            $file,
        );

        Storage::disk('local')->assertExists($document->path);
        $this->assertStringStartsWith("car-hire/{$booking->reference}/", $document->path);
        $this->assertSame('local', $document->disk);
        $this->assertSame(CarHireDocumentType::NationalId, $document->document_type);
        $this->assertSame($customer->getKey(), $document->uploaded_by_user_id);
        $this->assertSame(64 * 1024, $document->size_bytes);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $document->content_sha256);
        $this->assertArrayNotHasKey('disk', $document->toArray());
        $this->assertArrayNotHasKey('path', $document->toArray());
        $this->assertArrayNotHasKey('content_sha256', $document->toArray());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'car_hire.document_uploaded',
            'auditable_id' => $document->getKey(),
            'user_id' => $customer->getKey(),
        ]);
    }

    public function test_replacing_a_document_deletes_the_old_private_blob_after_commit(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, mode: HireMode::SelfDrive);
        $action = app(StoreCarHireDocument::class);
        $first = $action->execute(
            $customer,
            $booking,
            CarHireDocumentType::DrivingPermit,
            UploadedFile::fake()->create('permit.pdf', 32, 'application/pdf'),
        );
        $oldPath = $first->path;
        $replacement = $action->execute(
            $customer,
            $booking,
            CarHireDocumentType::DrivingPermit,
            UploadedFile::fake()->create('new-permit.pdf', 48, 'application/pdf'),
        );

        $this->assertSame($first->getKey(), $replacement->getKey());
        $this->assertNotSame($oldPath, $replacement->path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($replacement->path);
        $this->assertDatabaseCount('car_hire_documents', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'car_hire.document_replaced',
            'auditable_id' => $replacement->getKey(),
        ]);
    }

    public function test_non_owner_cannot_upload_and_temporary_blob_is_cleaned_up(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $owner = $this->customer();
        $booking = $this->persistedBooking($owner, $vehicle, $rate, mode: HireMode::SelfDrive);

        try {
            app(StoreCarHireDocument::class)->execute(
                $this->customer(),
                $booking,
                CarHireDocumentType::NationalId,
                UploadedFile::fake()->create('foreign-id.pdf', 20, 'application/pdf'),
            );
            $this->fail('A non-owner uploaded a private document.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('car_hire_documents', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_document_type_content_extension_size_and_editability_are_enforced(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, mode: HireMode::SelfDrive);
        config()->set('car_hire.documents.maximum_kilobytes', 512);

        $cases = [
            UploadedFile::fake()->create('identity.txt', 10, 'text/plain'),
            UploadedFile::fake()->create('identity.jpg', 10, 'application/pdf'),
            UploadedFile::fake()->create('identity.pdf', 513, 'application/pdf'),
        ];

        foreach ($cases as $file) {
            try {
                app(StoreCarHireDocument::class)->execute(
                    $customer,
                    $booking,
                    CarHireDocumentType::NationalId,
                    $file,
                );
                $this->fail('An invalid private document was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }

        $booking->update(['status' => CarHireBookingStatus::Confirmed]);
        try {
            app(StoreCarHireDocument::class)->execute(
                $customer,
                $booking->fresh(),
                CarHireDocumentType::NationalId,
                UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf'),
            );
            $this->fail('A document was changed after confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }

        $this->assertDatabaseCount('car_hire_documents', 0);
    }

    public function test_submission_requires_both_documents_and_permit_valid_through_return(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, mode: HireMode::SelfDrive);
        $payload = $this->applicationPayload($booking);

        try {
            app(SaveSelfDriveApplication::class)->execute($customer, $booking, $payload, true);
            $this->fail('An application without required documents was submitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        foreach ([CarHireDocumentType::NationalId, CarHireDocumentType::DrivingPermit] as $type) {
            CarHireDocument::factory()->for($booking, 'booking')->create([
                'document_type' => $type,
                'uploaded_by_user_id' => $customer->getKey(),
            ]);
        }
        $tooShort = array_merge($payload, [
            'driving_permit_expires_on' => $booking->return_at->subDay()->toDateString(),
        ]);

        try {
            app(SaveSelfDriveApplication::class)->execute($customer, $booking, $tooShort, true);
            $this->fail('A permit expiring before return was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'driving_permit_expires_on',
                $exception->errors(),
                json_encode($exception->errors(), JSON_THROW_ON_ERROR),
            );
        }

        $application = app(SaveSelfDriveApplication::class)->execute($customer, $booking, $payload, true);
        $this->assertSame(SelfDriveApplicationStatus::Submitted, $application->status);
        $this->assertNotNull($application->declaration_accepted_at);
        $this->assertNotNull($application->submitted_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'car_hire.self_drive_submitted',
            'auditable_id' => $application->getKey(),
        ]);
    }

    public function test_owner_can_delete_only_while_application_is_editable(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, mode: HireMode::SelfDrive);
        $document = app(StoreCarHireDocument::class)->execute(
            $customer,
            $booking,
            CarHireDocumentType::NationalId,
            UploadedFile::fake()->create('identity.pdf', 12, 'application/pdf'),
        );
        $path = $document->path;

        app(DeleteCarHireDocument::class)->execute($customer, $document);
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('car_hire_documents', ['id' => $document->getKey()]);

        $locked = CarHireDocument::factory()->for($booking, 'booking')->create([
            'document_type' => CarHireDocumentType::DrivingPermit,
            'uploaded_by_user_id' => $customer->getKey(),
        ]);
        $booking->selfDriveApplication->update(['status' => SelfDriveApplicationStatus::Submitted]);

        try {
            app(DeleteCarHireDocument::class)->execute($customer, $locked);
            $this->fail('A submitted application document was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
            $this->assertDatabaseHas('car_hire_documents', ['id' => $locked->getKey()]);
        }
    }

    /** @return array<string, mixed> */
    private function applicationPayload($booking): array
    {
        return [
            'national_id_number' => 'CM90000001AA0A',
            'driving_permit_number' => 'UG-DP-123456789',
            'date_of_birth' => '1990-05-15',
            'driving_permit_issuing_country' => 'Uganda',
            'driving_permit_class' => 'B',
            'driving_permit_issued_on' => '2022-01-01',
            'driving_permit_expires_on' => $booking->return_at->addYear()->toDateString(),
            'declaration_accepted' => '1',
        ];
    }
}
