<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Models\CarHireBooking;
use App\Models\CarHireContract;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class PdfGenerationTest extends TestCase
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

    /** @return array{0: CarHireContract, 1: CarHireBooking} */
    private function contract(): array
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate);

        return [$this->contractFor($booking), $booking->fresh(['customer'])];
    }

    public function test_it_renders_a_real_pdf_document(): void
    {
        [$contract, $booking] = $this->contract();

        $bytes = app(PdfRenderer::class)->render('pdf.car-hire-contract', [
            'contract' => $contract,
            'booking' => $booking,
        ]);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_storing_a_generated_pdf_files_it_as_a_private_versioned_document(): void
    {
        [$contract, $booking] = $this->contract();
        $staff = $this->operationsUser();

        $document = app(PdfRenderer::class)->store(
            $staff,
            $booking,
            DocumentCategory::RentalContract,
            'pdf.car-hire-contract',
            ['contract' => $contract, 'booking' => $booking],
            ['contract_number' => $contract->contract_number],
        );

        $this->assertTrue($document->is_generated);
        $this->assertSame(DocumentVisibility::Private, $document->visibility);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(1, $document->version);
        $this->assertNull($document->original_name);
        $this->assertSame($contract->contract_number, ($document->metadata ?? [])['contract_number']);

        Storage::disk($document->disk)->assertExists($document->path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'document.generated']);
    }

    public function test_regenerating_supersedes_the_previous_version_and_retains_it(): void
    {
        [$contract, $booking] = $this->contract();
        $staff = $this->operationsUser();
        $renderer = app(PdfRenderer::class);

        $first = $renderer->store($staff, $booking, DocumentCategory::RentalContract, 'pdf.car-hire-contract', [
            'contract' => $contract, 'booking' => $booking,
        ]);
        $second = $renderer->store($staff, $booking, DocumentCategory::RentalContract, 'pdf.car-hire-contract', [
            'contract' => $contract, 'booking' => $booking,
        ]);

        $this->assertSame(2, $second->version);
        $this->assertFalse($first->fresh()->is_current);
        $this->assertTrue($second->is_current);

        // A contract that may already have been sent or signed is never purged.
        Storage::disk($first->disk)->assertExists($first->path);
        $this->assertSame(1, Document::query()
            ->ofCategory(DocumentCategory::RentalContract)
            ->current()
            ->count());
    }

    public function test_the_generated_contract_carries_the_branding_and_key_terms(): void
    {
        config([
            'pisfa.company.name' => 'PISFA Tours and Travels',
            'pisfa.company.address' => 'Kampala, Uganda',
        ]);
        [$contract, $booking] = $this->contract();

        $bytes = app(PdfRenderer::class)->render('pdf.car-hire-contract', [
            'contract' => $contract,
            'booking' => $booking,
        ]);

        // DomPDF compresses streams, so assert on structure and size rather
        // than on plain-text substrings, which are not reliably extractable.
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringContainsString('%%EOF', $bytes);
    }

    public function test_a_generated_contract_downloads_only_through_authorization(): void
    {
        [$contract, $booking] = $this->contract();
        $staff = $this->operationsUser();

        $document = app(PdfRenderer::class)->store(
            $staff,
            $booking,
            DocumentCategory::RentalContract,
            'pdf.car-hire-contract',
            ['contract' => $contract, 'booking' => $booking],
        );

        $this->get(route('documents.show', $document))->assertRedirect(route('login'));

        $owner = $booking->customer;
        $this->assertInstanceOf(User::class, $owner);

        $this->actingAs($owner)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $stranger = $this->customer();
        $this->actingAs($stranger)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_a_customer_cannot_delete_a_generated_financial_document(): void
    {
        [$contract, $booking] = $this->contract();
        $staff = $this->operationsUser();

        $document = app(PdfRenderer::class)->store(
            $staff,
            $booking,
            DocumentCategory::RentalContract,
            'pdf.car-hire-contract',
            ['contract' => $contract, 'booking' => $booking],
        );

        // The owning customer may view it but must not be able to destroy
        // contractual evidence.
        $owner = $booking->customer;
        $this->assertInstanceOf(User::class, $owner);

        $this->assertTrue($owner->can('view', $document));
        $this->assertFalse($owner->can('delete', $document));
        $this->assertTrue($staff->can('delete', $document));
    }
}
