<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\StoreDocument;
use App\Enums\AccountStatus;
use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function staff(): User
    {
        return User::factory()->create([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'two_factor_required' => false,
        ]);
    }

    public function test_storing_an_upload_writes_the_file_and_records_metadata(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();
        $file = UploadedFile::fake()->image('front.jpg', 900, 700);

        $document = app(StoreDocument::class)->execute(
            $staff,
            $vehicle,
            DocumentCategory::VehicleMedia,
            $file,
        );

        $this->assertSame(DocumentCategory::VehicleMedia, $document->category);
        $this->assertSame(DocumentVisibility::Public, $document->visibility);
        $this->assertSame('image/jpeg', $document->mime_type);
        $this->assertSame(900, $document->image_width);
        $this->assertSame(1, $document->version);
        $this->assertTrue($document->is_current);
        $this->assertFalse($document->is_generated);
        $this->assertSame($staff->getKey(), $document->uploaded_by_user_id);

        Storage::disk($document->disk)->assertExists($document->path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'document.uploaded']);
    }

    public function test_a_sensitive_category_always_lands_on_the_private_disk(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();

        $document = app(StoreDocument::class)->execute(
            $staff,
            $vehicle,
            DocumentCategory::IdentityDocument,
            UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 identity document'),
        );

        $this->assertSame(DocumentVisibility::Private, $document->visibility);
        $this->assertTrue($document->isPrivate());
        // A private document has no direct URL by design.
        $this->assertNull($document->url());
    }

    public function test_the_stored_filename_never_contains_the_uploaded_name(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();

        $document = app(StoreDocument::class)->execute(
            $staff,
            $vehicle,
            DocumentCategory::VehicleMedia,
            UploadedFile::fake()->image('../../etc/passwd.jpg', 400, 400),
        );

        $this->assertStringNotContainsString('passwd', $document->path);
        $this->assertStringNotContainsString('..', $document->path);
        $this->assertMatchesRegularExpression('#^vehicles/\d+/vehicle_media/[0-9a-f]{32}\.jpg$#', $document->path);
    }

    public function test_a_single_slot_category_replaces_and_purges_its_predecessor(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();
        $action = app(StoreDocument::class);

        $first = $action->execute(
            $staff,
            $vehicle,
            DocumentCategory::ApplicantPhoto,
            UploadedFile::fake()->image('one.jpg', 400, 400),
        );
        $firstPath = $first->path;

        $second = $action->execute(
            $staff,
            $vehicle,
            DocumentCategory::ApplicantPhoto,
            UploadedFile::fake()->image('two.jpg', 400, 400),
        );

        // ApplicantPhoto is not versioned, so the old file is destroyed.
        Storage::disk($first->disk)->assertMissing($firstPath);
        Storage::disk($second->disk)->assertExists($second->path);
        $this->assertSoftDeleted('documents', ['id' => $first->getKey()]);
        $this->assertTrue($second->fresh()->is_current);
    }

    public function test_a_versioned_category_keeps_the_superseded_revision_and_its_file(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();
        $action = app(StoreDocument::class);

        $first = $action->execute(
            $staff,
            $vehicle,
            DocumentCategory::InsuranceDocument,
            UploadedFile::fake()->createWithContent('cover-2025.pdf', '%PDF-1.4 policy 2025'),
        );

        $second = $action->execute(
            $staff,
            $vehicle,
            DocumentCategory::InsuranceDocument,
            UploadedFile::fake()->createWithContent('cover-2026.pdf', '%PDF-1.4 policy 2026'),
        );

        $this->assertSame(1, $first->fresh()->version);
        $this->assertSame(2, $second->version);
        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->is_current);

        // The superseded policy is still on file — it may have been relied on.
        Storage::disk($first->disk)->assertExists($first->path);
        $this->assertNotSoftDeleted('documents', ['id' => $first->getKey()]);
    }

    public function test_a_generated_category_cannot_be_uploaded(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();

        $this->expectException(ValidationException::class);

        app(StoreDocument::class)->execute(
            $staff,
            $vehicle,
            DocumentCategory::Invoice,
            UploadedFile::fake()->createWithContent('fake-invoice.pdf', '%PDF-1.4 forged'),
        );
    }

    public function test_a_rejected_upload_leaves_no_orphaned_file_or_row(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();

        try {
            app(StoreDocument::class)->execute(
                $staff,
                $vehicle,
                DocumentCategory::VehicleMedia,
                UploadedFile::fake()->createWithContent('evil.jpg', '<?php echo 1; ?>'),
            );
            $this->fail('The malicious upload should have been rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_deleting_a_document_soft_deletes_the_row_and_destroys_the_bytes(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();

        $document = app(StoreDocument::class)->execute(
            $staff,
            $vehicle,
            DocumentCategory::IdentityDocument,
            UploadedFile::fake()->createWithContent('id.pdf', '%PDF-1.4 personal identity data'),
        );
        $path = $document->path;
        $disk = $document->disk;

        app(DeleteDocument::class)->execute($staff, $document, 'Customer withdrew consent.');

        // The audit trail survives; the personal data does not.
        $this->assertSoftDeleted('documents', ['id' => $document->getKey()]);
        Storage::disk($disk)->assertMissing($path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'document.deleted']);
    }

    public function test_version_numbers_continue_past_deleted_revisions(): void
    {
        $staff = $this->staff();
        $vehicle = Vehicle::factory()->create();
        $action = app(StoreDocument::class);

        $first = $action->execute(
            $staff,
            $vehicle,
            DocumentCategory::InsuranceDocument,
            UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 a'),
        );
        app(DeleteDocument::class)->execute($staff, $first);

        $second = $action->execute(
            $staff,
            $vehicle,
            DocumentCategory::InsuranceDocument,
            UploadedFile::fake()->createWithContent('b.pdf', '%PDF-1.4 b'),
        );

        // Reusing version 1 would collide with the unique key and would also
        // make the history misleading.
        $this->assertSame(2, $second->version);
    }

    public function test_documents_are_scoped_to_their_owner(): void
    {
        $staff = $this->staff();
        $vehicleA = Vehicle::factory()->create();
        $vehicleB = Vehicle::factory()->create();
        $action = app(StoreDocument::class);

        $action->execute($staff, $vehicleA, DocumentCategory::VehicleMedia, UploadedFile::fake()->image('a.jpg', 400, 400));
        $action->execute($staff, $vehicleB, DocumentCategory::VehicleMedia, UploadedFile::fake()->image('b.jpg', 400, 400));

        $this->assertSame(1, Document::query()
            ->where('documentable_type', $vehicleA->getMorphClass())
            ->where('documentable_id', $vehicleA->getKey())
            ->count());
    }
}
