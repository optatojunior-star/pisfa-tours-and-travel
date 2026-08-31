<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentCategory;
use App\Services\Documents\FileInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FileInspectorTest extends TestCase
{
    use RefreshDatabase;

    private FileInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inspector = app(FileInspector::class);
    }

    public function test_it_accepts_a_genuine_image_and_reports_its_real_properties(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $result = $this->inspector->inspect($file, DocumentCategory::VehicleMedia);

        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertSame('jpg', $result['extension']);
        $this->assertSame(800, $result['width']);
        $this->assertSame(600, $result['height']);
        $this->assertSame(64, strlen($result['checksum']));
    }

    public function test_it_rejects_a_php_script_renamed_with_an_image_extension(): void
    {
        // The classic upload bypass: attacker-controlled filename and
        // Content-Type, but the bytes are a script.
        $file = UploadedFile::fake()->createWithContent(
            'avatar.jpg',
            "<?php echo shell_exec(\$_GET['c']); ?>",
        );

        $this->expectException(ValidationException::class);

        $this->inspector->inspect($file, DocumentCategory::VehicleMedia);
    }

    public function test_it_rejects_an_image_with_php_embedded_in_its_bytes(): void
    {
        // A polyglot: valid JPEG magic bytes followed by executable code.
        $file = UploadedFile::fake()->createWithContent(
            'polyglot.jpg',
            "\xFF\xD8\xFF\xE0".str_repeat("\x00", 32)."<?php system('id'); ?>",
        );

        $this->expectException(ValidationException::class);

        $this->inspector->inspect($file, DocumentCategory::VehicleMedia);
    }

    public function test_it_rejects_a_real_image_whose_extension_lies_about_it(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 400, 400);
        // A genuine PNG is produced, then renamed to claim it is a PDF.
        $renamed = new UploadedFile(
            $file->getRealPath(),
            'photo.pdf',
            'application/pdf',
            null,
            true,
        );

        $this->expectException(ValidationException::class);

        $this->inspector->inspect($renamed, DocumentCategory::VehicleMedia);
    }

    public function test_it_rejects_an_unsupported_type(): void
    {
        $file = UploadedFile::fake()->createWithContent('notes.txt', 'plain text content');

        $this->expectException(ValidationException::class);

        $this->inspector->inspect($file, DocumentCategory::ImportDocument);
    }

    public function test_it_rejects_an_empty_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('empty.pdf', '');

        $this->expectException(ValidationException::class);

        $this->inspector->inspect($file, DocumentCategory::ImportDocument);
    }

    public function test_it_rejects_an_image_below_the_minimum_dimensions(): void
    {
        config(['documents.images.minimum_width' => 200, 'documents.images.minimum_height' => 200]);
        $file = UploadedFile::fake()->image('tiny.jpg', 50, 50);

        $this->expectException(ValidationException::class);

        $this->inspector->inspect($file, DocumentCategory::VehicleMedia);
    }

    public function test_it_rejects_a_file_over_the_configured_size_limit(): void
    {
        config(['documents.images.maximum_kilobytes' => 10]);
        $file = UploadedFile::fake()->image('large.jpg', 2000, 2000)->size(500);

        $this->expectException(ValidationException::class);

        $this->inspector->inspect($file, DocumentCategory::VehicleMedia);
    }

    public function test_a_category_requiring_an_image_rejects_a_pdf(): void
    {
        $file = UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 minimal document body');

        $this->expectException(ValidationException::class);

        // ApplicantPhoto must be a photograph, not a scanned PDF.
        $this->inspector->inspect($file, DocumentCategory::ApplicantPhoto);
    }

    public function test_a_document_category_accepts_a_genuine_pdf(): void
    {
        $file = UploadedFile::fake()->createWithContent('bill.pdf', '%PDF-1.4 shipping bill of lading');

        $result = $this->inspector->inspect($file, DocumentCategory::ImportDocument);

        $this->assertSame('application/pdf', $result['mime']);
        $this->assertNull($result['width']);
    }

    public function test_generated_filenames_are_random_and_never_derived_from_input(): void
    {
        $first = $this->inspector->safeFilename('jpg');
        $second = $this->inspector->safeFilename('jpg');

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpg$/', $first);
    }
}
