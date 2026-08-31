<?php

namespace Tests\Feature\CarHire;

use App\Actions\CarHire\ReviewSelfDriveApplication;
use App\Actions\CarHire\TransitionCarHireBooking;
use App\Actions\CarHire\VerifySelfDriveOriginals;
use App\Enums\CarHireBookingStatus;
use App\Enums\CarHireDocumentType;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Models\AuditLog;
use App\Models\CarHireBooking;
use App\Models\CarHireDocument;
use App\Models\CarHireSelfDriveApplication;
use App\Notifications\CarHire\SelfDriveApplicationReviewedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class SelfDriveReviewTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    public function test_operations_can_request_information_with_customer_safe_reason_without_leaking_private_note(): void
    {
        [$booking, $application] = $this->submittedApplicationFixture();
        $actor = $this->operationsUser();
        $application = app(ReviewSelfDriveApplication::class)->execute(
            $actor,
            $application,
            SelfDriveApplicationStatus::NeedsInformation,
            'Please upload a clearer permit image.',
            'The internal fraud-screening note must remain private.',
        );

        $this->assertSame(SelfDriveApplicationStatus::NeedsInformation, $application->status);
        $this->assertSame('Please upload a clearer permit image.', $application->review_reason);
        $this->assertSame('The internal fraud-screening note must remain private.', $application->internal_review_notes);
        Notification::assertSentToTimes($booking->customer, SelfDriveApplicationReviewedNotification::class, 1);

        $audit = AuditLog::query()->where('event', 'car_hire.self_drive_reviewed')->sole();
        $encodedAudit = json_encode([
            $audit->old_values,
            $audit->new_values,
            $audit->context,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CM90000001AA0A', $encodedAudit);
        $this->assertStringNotContainsString('UG-DP-123456789', $encodedAudit);
        $this->assertStringNotContainsString('fraud-screening', $encodedAudit);
        $this->assertStringContainsString('clearer permit image', $encodedAudit);
    }

    public function test_approval_requires_complete_fields_required_documents_and_valid_permit(): void
    {
        [$booking, $application] = $this->submittedApplicationFixture(withDocuments: false);
        $actor = $this->operationsUser();

        try {
            app(ReviewSelfDriveApplication::class)->execute(
                $actor,
                $application,
                SelfDriveApplicationStatus::Approved,
            );
            $this->fail('An application without required documents was approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }

        $this->addRequiredDocuments($booking);
        $application->update([
            'driving_permit_expires_on' => $booking->return_at->subDay()->toDateString(),
        ]);
        try {
            app(ReviewSelfDriveApplication::class)->execute(
                $actor,
                $application->fresh(),
                SelfDriveApplicationStatus::Approved,
            );
            $this->fail('A permit expiring before return was approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('application', $exception->errors());
        }

        $application->update([
            'driving_permit_expires_on' => $booking->return_at->addDay()->toDateString(),
        ]);
        $approved = app(ReviewSelfDriveApplication::class)->execute(
            $actor,
            $application->fresh(),
            SelfDriveApplicationStatus::Approved,
        );
        $this->assertSame(SelfDriveApplicationStatus::Approved, $approved->status);
    }

    public function test_needs_information_can_be_edited_and_resubmitted_but_reviewed_states_are_locked(): void
    {
        [$booking, $application] = $this->submittedApplicationFixture();
        $actor = $this->operationsUser();
        $needsInfo = app(ReviewSelfDriveApplication::class)->execute(
            $actor,
            $application,
            SelfDriveApplicationStatus::NeedsInformation,
            'Please clarify the permit number.',
        );
        $this->assertTrue($needsInfo->isCustomerEditable());

        $needsInfo->update(['status' => SelfDriveApplicationStatus::Submitted]);
        $approved = app(ReviewSelfDriveApplication::class)->execute(
            $actor,
            $needsInfo->fresh(),
            SelfDriveApplicationStatus::Approved,
        );
        $this->assertFalse($approved->isCustomerEditable());

        try {
            app(ReviewSelfDriveApplication::class)->execute(
                $actor,
                $approved,
                SelfDriveApplicationStatus::Rejected,
                'A later reversal.',
            );
            $this->fail('An already approved application was reviewed again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }

    public function test_originals_are_verified_only_after_confirmation_and_are_idempotent(): void
    {
        [$booking, $application] = $this->submittedApplicationFixture();
        $actor = $this->operationsUser();
        $approved = app(ReviewSelfDriveApplication::class)->execute(
            $actor,
            $application,
            SelfDriveApplicationStatus::Approved,
        );

        try {
            app(VerifySelfDriveOriginals::class)->execute($actor, $approved);
            $this->fail('Originals were verified before booking confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('application', $exception->errors());
        }

        $this->contractFor($booking, accepted: true);
        app(TransitionCarHireBooking::class)->execute(
            $actor,
            $booking,
            CarHireBookingStatus::Confirmed,
        );
        $verified = app(VerifySelfDriveOriginals::class)->execute($actor, $approved->fresh());
        $replay = app(VerifySelfDriveOriginals::class)->execute($actor, $verified);

        $this->assertTrue($verified->is($replay));
        $this->assertNotNull($verified->originals_verified_at);
        $this->assertSame($actor->getKey(), $verified->originals_verified_by_user_id);
        $this->assertSame(1, AuditLog::query()
            ->where('event', 'car_hire.self_drive_originals_verified')->count());
    }

    /** @return array{0: CarHireBooking, 1: CarHireSelfDriveApplication} */
    private function submittedApplicationFixture(bool $withDocuments = true): array
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking(
            $this->customer(),
            $vehicle,
            $rate,
            mode: HireMode::SelfDrive,
        );
        $application = $booking->selfDriveApplication;
        $application->update([
            'status' => SelfDriveApplicationStatus::Submitted,
            'national_id_number' => 'CM90000001AA0A',
            'driving_permit_number' => 'UG-DP-123456789',
            'date_of_birth' => '1990-05-15',
            'driving_permit_issuing_country' => 'Uganda',
            'driving_permit_class' => 'B',
            'driving_permit_issued_on' => '2022-01-01',
            'driving_permit_expires_on' => $booking->return_at->addYear()->toDateString(),
            'declaration_accepted_at' => now(),
            'submitted_at' => now(),
        ]);

        if ($withDocuments) {
            $this->addRequiredDocuments($booking);
        }

        return [$booking, $application->fresh()];
    }

    private function addRequiredDocuments($booking): void
    {
        foreach ([CarHireDocumentType::NationalId, CarHireDocumentType::DrivingPermit] as $type) {
            CarHireDocument::factory()->for($booking, 'booking')->create([
                'document_type' => $type,
                'uploaded_by_user_id' => $booking->customer_id,
            ]);
        }
    }
}
