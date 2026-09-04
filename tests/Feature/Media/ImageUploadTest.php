<?php

namespace Tests\Feature\Media;

use App\Enums\AccountStatus;
use App\Enums\DocumentCategory;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\Document;
use App\Models\TourCategory;
use App\Models\TourPackage;
use App\Models\User;
use App\Models\VehicleListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploading a photograph from the machine you are sitting at.
 *
 * Every admin form used to have no file input at all — the storage layer, the
 * inspector and the actions all existed, and there was no way for a person to
 * reach any of it. These tests exist so that cannot quietly become true again.
 */
class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);
        Storage::fake('public');
        Storage::fake('local');
    }

    private function manager(): User
    {
        return User::factory()->create([
            'role' => UserRole::Manager,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    /** A real PNG, so FileInspector's magic-byte check sees what it expects. */
    private function image(string $name = 'prado.png', int $width = 900, int $height = 700): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    public function test_the_showroom_form_accepts_a_photograph_from_the_machine(): void
    {
        $listing = VehicleListing::factory()->create();

        $this->actingAs($this->manager())
            ->patch(route('admin.showroom.update', $listing), [
                'title' => $listing->title,
                'make' => $listing->make,
                'model' => $listing->model,
                'year' => $listing->year,
                'description' => str_repeat('A well maintained vehicle. ', 4),
                'asking_price' => '95000000',
                'currency' => 'UGX',
                'images' => [$this->image()],
            ])
            ->assertRedirect();

        $this->assertSame(1, $listing->media()->count(), 'The photograph was not attached.');

        $document = $listing->media()->first();

        $this->assertSame(DocumentCategory::VehicleMedia, $document->category);
        $this->assertSame(900, $document->image_width);
        $this->assertSame(700, $document->image_height);
        $this->assertNotNull($document->content_sha256, 'No checksum was recorded.');
    }

    public function test_several_photographs_upload_in_one_go(): void
    {
        $listing = VehicleListing::factory()->create();

        $this->actingAs($this->manager())
            ->patch(route('admin.showroom.update', $listing), [
                'title' => $listing->title,
                'make' => $listing->make,
                'model' => $listing->model,
                'year' => $listing->year,
                'description' => str_repeat('A well maintained vehicle. ', 4),
                'asking_price' => '95000000',
                'currency' => 'UGX',
                'images' => [$this->image('front.png'), $this->image('rear.png'), $this->image('interior.png')],
            ])
            ->assertRedirect();

        $this->assertSame(3, $listing->media()->count());

        // Sort order appends rather than reshuffling, so the first photograph
        // uploaded stays the cover.
        $this->assertSame([1, 2, 3], $listing->media()->orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_a_file_pretending_to_be_an_image_is_refused(): void
    {
        $listing = VehicleListing::factory()->create();

        // A PHP script renamed .png. The extension says image; the content does
        // not, and FileInspector reads the content.
        $disguised = UploadedFile::fake()->createWithContent('shell.png', '<?php system($_GET["c"]); ?>');

        $this->actingAs($this->manager())
            ->patch(route('admin.showroom.update', $listing), [
                'title' => $listing->title,
                'make' => $listing->make,
                'model' => $listing->model,
                'year' => $listing->year,
                'description' => str_repeat('A well maintained vehicle. ', 4),
                'asking_price' => '95000000',
                'currency' => 'UGX',
                'images' => [$disguised],
            ]);

        $this->assertSame(0, $listing->media()->count(), 'A disguised file was stored.');
    }

    public function test_the_media_library_accepts_an_upload(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.media.store'), ['images' => [$this->image('lodge.png')]])
            ->assertRedirect();

        $this->assertSame(1, Document::query()->where('category', DocumentCategory::BlogMedia->value)->count());
    }

    public function test_the_library_page_renders_and_shows_what_was_uploaded(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('admin.media.store'), ['images' => [$this->image('sipi-falls.png')]]);

        $this->actingAs($manager)
            ->get(route('admin.media.index'))
            ->assertOk()
            ->assertSee('sipi-falls.png');
    }

    public function test_the_upload_form_posts_multipart(): void
    {
        // Without enctype the browser sends field names and no file bodies, and
        // the upload silently does nothing — which is exactly how this looked
        // when it was broken.
        $listing = VehicleListing::factory()->create();

        $this->actingAs($this->manager())
            ->get(route('admin.showroom.edit', $listing))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('type="file"', false);
    }

    public function test_a_tour_package_accepts_photographs_from_the_machine(): void
    {
        $category = TourCategory::factory()->create(['is_active' => true]);
        $package = TourPackage::factory()->create();

        $this->actingAs($this->manager())
            ->patch(route('admin.tours.update', $package), [
                'category_id' => $category->id,
                'cancellation_cutoff_hours' => 48,
                'name' => $package->name,
                'destination' => $package->destination,
                'summary' => $package->summary,
                'description' => $package->description,
                'duration_days' => $package->duration_days,
                'base_price' => '1200000',
                'currency' => 'UGX',
                'min_travelers' => 1,
                'max_travelers' => 8,
                'images' => [$this->image('bwindi.png'), $this->image('gorilla.png')],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // Both the stored files and the display rows the public views read.
        $this->assertSame(2, $package->documents()->count(), 'The files were not stored.');
        $this->assertSame(2, $package->media()->count(), 'The display rows were not created.');

        // The first photograph becomes the cover, so a tour card has something
        // to show without anybody choosing.
        $this->assertSame(1, $package->media()->where('is_cover', true)->count());
    }

    public function test_the_tour_form_offers_a_file_input_not_a_url_box(): void
    {
        $package = TourPackage::factory()->create();

        $this->actingAs($this->manager())
            ->get(route('admin.tours.edit', $package))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('type="file"', false)
            // The URL repeater it replaced.
            ->assertDontSee('Image URL or path')
            ->assertDontSee('name="media[0][url]"', false);
    }

    public function test_a_customer_cannot_upload(): void
    {
        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($customer)
            ->post(route('admin.media.store'), ['images' => [$this->image()]])
            ->assertForbidden();

        $this->assertSame(0, Document::query()->count());
    }
}
