<?php

namespace Tests\Feature\Content;

use App\Enums\AccountStatus;
use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\Document;
use App\Models\MediaAlbum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The sliding gallery on the home page.
 *
 * Service pictures answer "what does car hire look like". These answer "is this
 * company worth trusting with my holiday", and there was nowhere to put one.
 */
class HomeGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // A manager is under the mandatory two-factor policy, which is covered
        // by its own tests. Leaving it armed here would redirect every request
        // to the 2FA setup screen and prove nothing about the gallery.
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_staff_upload_photographs_and_they_appear_on_the_home_page(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.gallery.store'), [
                'images' => [$this->photograph('nile.jpg'), $this->photograph('gate.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, MediaAlbum::homeGallery()->galleryImages()->count());

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Uganda, as our customers saw it');
    }

    /**
     * An empty section that announces its own emptiness is worse than no
     * section, and a fresh deployment has no photographs.
     */
    public function test_the_home_page_shows_no_gallery_section_until_something_is_uploaded(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('Uganda, as our customers saw it');
    }

    /** The public site must never create records just by being viewed. */
    public function test_viewing_the_home_page_does_not_create_the_album(): void
    {
        $this->get(route('home'))->assertOk();

        $this->assertSame(0, MediaAlbum::query()->where('slug', 'home-gallery')->count());
    }

    /**
     * Unlike a driver headshot, a gallery photograph is marketing: it belongs on
     * the public disk with a direct URL so it can be cached like any other
     * picture on the site.
     */
    public function test_gallery_photographs_are_public_with_a_direct_url(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.gallery.store'), ['images' => [$this->photograph()]]);

        $image = MediaAlbum::homeGallery()->galleryImages()->sole();

        $this->assertSame(DocumentVisibility::Public, $image->visibility);
        $this->assertNotNull($image->url());
    }

    /** A gallery accumulates. Uploading a second must not remove the first. */
    public function test_a_second_upload_adds_rather_than_replaces(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.gallery.store'), ['images' => [$this->photograph('one.jpg')]]);
        $this->actingAs($staff)->post(route('admin.gallery.store'), ['images' => [$this->photograph('two.jpg')]]);

        $this->assertSame(2, MediaAlbum::homeGallery()->galleryImages()->count());
    }

    public function test_photographs_slide_in_upload_order(): void
    {
        $staff = $this->staff();

        foreach (['first.jpg', 'second.jpg', 'third.jpg'] as $name) {
            $this->actingAs($staff)->post(route('admin.gallery.store'), ['images' => [$this->photograph($name)]]);
        }

        $sortOrders = MediaAlbum::homeGallery()->galleryImages()->pluck('sort_order')->all();

        // Ascending, and the relation orders by it — so adding a picture never
        // reshuffles the ones already arranged.
        $sorted = $sortOrders;
        sort($sorted);
        $this->assertSame($sorted, $sortOrders);
    }

    public function test_a_caption_is_saved_and_shown(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.gallery.store'), ['images' => [$this->photograph()]]);
        $image = MediaAlbum::homeGallery()->galleryImages()->sole();

        $this->actingAs($staff)
            ->patch(route('admin.gallery.caption', $image), ['caption' => 'Murchison Falls, on the Nile'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Murchison Falls, on the Nile', $image->fresh()->metadata['caption']);

        $this->get(route('home'))->assertOk()->assertSee('Murchison Falls, on the Nile');
    }

    /**
     * The case an array union quietly broke: writing over a caption that is
     * already there. The first caption on a picture worked, so the bug only
     * showed up on the second.
     */
    public function test_an_existing_caption_can_be_changed(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.gallery.store'), ['images' => [$this->photograph()]]);
        $image = MediaAlbum::homeGallery()->galleryImages()->sole();

        $this->actingAs($staff)->patch(route('admin.gallery.caption', $image), ['caption' => 'First wording']);
        $this->actingAs($staff)->patch(route('admin.gallery.caption', $image), ['caption' => 'Better wording']);

        $this->assertSame('Better wording', $image->fresh()->metadata['caption']);
    }

    public function test_a_blank_caption_clears_it(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.gallery.store'), ['images' => [$this->photograph()]]);
        $image = MediaAlbum::homeGallery()->galleryImages()->sole();

        $this->actingAs($staff)->patch(route('admin.gallery.caption', $image), ['caption' => 'Temporary']);
        $this->actingAs($staff)->patch(route('admin.gallery.caption', $image), ['caption' => '']);

        $this->assertArrayNotHasKey('caption', $image->fresh()->metadata ?? []);
    }

    public function test_staff_remove_a_photograph(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.gallery.store'), ['images' => [$this->photograph()]]);
        $image = MediaAlbum::homeGallery()->galleryImages()->sole();

        $this->actingAs($staff)
            ->delete(route('admin.gallery.destroy', $image))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, MediaAlbum::homeGallery()->galleryImages()->count());
    }

    /**
     * The caption and delete routes take a document id, so they must refuse a
     * document that is not a gallery photograph — otherwise they would be a way
     * to caption or destroy an invoice.
     */
    public function test_the_routes_refuse_a_document_from_another_category(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)->post(route('admin.media.store'), [
            'images' => [$this->photograph('library.jpg')],
            'category' => DocumentCategory::BlogMedia->value,
        ]);

        $foreign = Document::query()
            ->where('category', DocumentCategory::BlogMedia->value)
            ->sole();

        $this->actingAs($staff)->patch(route('admin.gallery.caption', $foreign), ['caption' => 'Nope'])->assertNotFound();
        $this->actingAs($staff)->delete(route('admin.gallery.destroy', $foreign))->assertNotFound();
    }

    public function test_customers_and_guests_cannot_touch_the_gallery(): void
    {
        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($customer)->get(route('admin.gallery.index'))->assertForbidden();
        $this->actingAs($customer)->post(route('admin.gallery.store'), ['images' => [$this->photograph()]])->assertForbidden();
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.gallery.index'))->assertRedirect(route('login'));
    }

    public function test_a_pdf_is_refused(): void
    {
        $this->actingAs($this->staff())
            ->post(route('admin.gallery.store'), [
                'images' => [UploadedFile::fake()->create('brochure.pdf', 40, 'application/pdf')],
            ])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, MediaAlbum::homeGallery()->galleryImages()->count());
    }

    private function staff(): User
    {
        return User::factory()->create([
            'role' => UserRole::Manager,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    private function photograph(string $name = 'gallery.jpg'): UploadedFile
    {
        // Comfortably above FileInspector's 200x200 minimum.
        return UploadedFile::fake()->image($name, 1600, 900);
    }
}
