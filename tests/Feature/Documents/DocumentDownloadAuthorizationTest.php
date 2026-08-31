<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\StoreDocument;
use App\Enums\AccountStatus;
use App\Enums\DocumentCategory;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class DocumentDownloadAuthorizationTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Storage::fake('local');
        Storage::fake('public');
    }

    private function staffUser(): User
    {
        return User::factory()->create([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'two_factor_required' => false,
        ]);
    }

    private function documentOn(Vehicle $vehicle, DocumentCategory $category): Document
    {
        return app(StoreDocument::class)->execute(
            $this->staffUser(),
            $vehicle,
            $category,
            $category->requiresImage()
                ? UploadedFile::fake()->image('file.jpg', 400, 400)
                : UploadedFile::fake()->createWithContent('file.pdf', '%PDF-1.4 body'),
        );
    }

    public function test_a_guest_cannot_reach_the_authorized_download_route(): void
    {
        $document = $this->documentOn(Vehicle::factory()->create(), DocumentCategory::IdentityDocument);

        $this->get(route('documents.show', $document))->assertRedirect(route('login'));
    }

    public function test_staff_can_download_a_document_whose_owner_they_administer(): void
    {
        $vehicle = Vehicle::factory()->create();
        $document = $this->documentOn($vehicle, DocumentCategory::IdentityDocument);

        // VehiclePolicy grants staff `view`, so DocumentPolicy inherits it.
        $this->actingAs($this->staffUser())
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_customer_cannot_download_a_document_they_do_not_own(): void
    {
        $vehicle = Vehicle::factory()->create();
        $document = $this->documentOn($vehicle, DocumentCategory::IdentityDocument);
        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($customer)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_a_suspended_account_is_denied_even_with_the_right_role(): void
    {
        $vehicle = Vehicle::factory()->create();
        $document = $this->documentOn($vehicle, DocumentCategory::IdentityDocument);
        $suspended = User::factory()->create([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Suspended,
            'email_verified_at' => now(),
            'two_factor_required' => false,
        ]);

        // EnsureAccountIsActive logs the session out before authorization runs.
        $this->actingAs($suspended)
            ->get(route('documents.show', $document))
            ->assertRedirect(route('login'));
    }

    public function test_the_signed_route_requires_a_valid_signature(): void
    {
        $document = $this->documentOn(Vehicle::factory()->create(), DocumentCategory::IdentityDocument);

        $this->get(route('documents.signed', $document))->assertForbidden();

        $expired = URL::temporarySignedRoute(
            'documents.signed',
            now()->subMinute(),
            ['document' => $document->getKey()],
        );
        $this->get($expired)->assertForbidden();
    }

    public function test_a_valid_signed_link_streams_the_file_without_a_session(): void
    {
        $document = $this->documentOn(Vehicle::factory()->create(), DocumentCategory::IdentityDocument);

        $this->get($document->temporarySignedUrl())
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_private_download_is_never_cached_by_a_proxy(): void
    {
        $document = $this->documentOn(Vehicle::factory()->create(), DocumentCategory::IdentityDocument);

        $response = $this->actingAs($this->staffUser())
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff');

        // Symfony normalises and reorders Cache-Control directives, so assert
        // on the directives themselves rather than on their serialised order.
        $cacheControl = (string) $response->headers->get('cache-control');

        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }

        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_a_missing_underlying_file_returns_not_found_rather_than_a_server_error(): void
    {
        $document = $this->documentOn(Vehicle::factory()->create(), DocumentCategory::IdentityDocument);
        Storage::disk($document->disk)->delete($document->path);

        $this->actingAs($this->staffUser())
            ->get(route('documents.show', $document))
            ->assertNotFound();
    }

    public function test_the_signed_route_refuses_a_public_document(): void
    {
        $document = $this->documentOn(Vehicle::factory()->create(), DocumentCategory::VehicleMedia);

        // Public catalogue media has a direct URL; the signed route exists only
        // for private files and must not become a second path to everything.
        $this->get($document->temporarySignedUrl())->assertNotFound();
    }

    public function test_the_download_filename_never_echoes_the_uploaded_name(): void
    {
        $vehicle = Vehicle::factory()->create();
        $document = app(StoreDocument::class)->execute(
            $this->staffUser(),
            $vehicle,
            DocumentCategory::IdentityDocument,
            UploadedFile::fake()->createWithContent('"; rm -rf /; #.pdf', '%PDF-1.4 body'),
        );

        $response = $this->actingAs($this->staffUser())
            ->get(route('documents.show', $document))
            ->assertOk();

        $disposition = $response->headers->get('content-disposition');
        $this->assertStringNotContainsString('rm -rf', (string) $disposition);
        $this->assertStringContainsString('identity-document-'.$document->getKey().'.pdf', (string) $disposition);
    }
}
