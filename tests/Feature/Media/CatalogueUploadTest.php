<?php

namespace Tests\Feature\Media;

use App\Enums\AccountStatus;
use App\Enums\DocumentCategory;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Property;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploading photographs on the rest of the catalogue.
 *
 * Tours and the showroom were covered first; vehicles, properties and the
 * journal were still asking for a URL, or offering nothing at all. Two of the
 * bugs guarded here are not about uploading: removing a field from a form made
 * saving destroy the data behind it, which is a far quieter failure than a
 * missing file input and cost real work before it was noticed.
 */
class CatalogueUploadTest extends TestCase
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
    private function image(string $name = 'front.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 900, 700);
    }

    // ---------------------------------------------------------------- vehicles

    public function test_a_fleet_vehicle_accepts_photographs_from_the_machine(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($this->manager())
            ->patch(route('admin.vehicles.update', $vehicle), $this->vehicleFields($vehicle) + [
                'images' => [$this->image('front.png'), $this->image('side.png')],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, $vehicle->photographs()->count(), 'The files were not stored.');
        $this->assertSame(2, $vehicle->media()->count(), 'The catalogue rows were not created.');
        $this->assertSame(1, $vehicle->media()->where('is_cover', true)->count());
    }

    /**
     * The bug this guards is the one that made tours unpublishable: a form
     * stopped sending a field, the action read the missing key as "cleared",
     * and saving anything at all destroyed work already done.
     */
    public function test_saving_a_vehicle_does_not_wipe_its_existing_photographs(): void
    {
        $vehicle = Vehicle::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager)
            ->patch(route('admin.vehicles.update', $vehicle), $this->vehicleFields($vehicle) + [
                'images' => [$this->image()],
            ]);

        $this->assertSame(1, $vehicle->media()->count());

        // A second save carrying no files at all.
        $this->actingAs($manager)
            ->patch(route('admin.vehicles.update', $vehicle), $this->vehicleFields($vehicle))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $vehicle->media()->count(), 'Saving the form deleted the photographs.');
    }

    public function test_saving_a_vehicle_does_not_wipe_its_description(): void
    {
        // The field is gone from the form. Text written before it was removed
        // must survive, because nothing on the screen could put it back.
        $vehicle = Vehicle::factory()->create(['description' => 'Serviced every 5,000km by the main dealer.']);

        $this->actingAs($this->manager())
            ->patch(route('admin.vehicles.update', $vehicle), $this->vehicleFields($vehicle))
            ->assertSessionHasNoErrors();

        $this->assertSame('Serviced every 5,000km by the main dealer.', $vehicle->fresh()?->description);
    }

    public function test_a_vehicle_photograph_can_be_removed(): void
    {
        $vehicle = Vehicle::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager)
            ->patch(route('admin.vehicles.update', $vehicle), $this->vehicleFields($vehicle) + [
                'images' => [$this->image('front.png'), $this->image('rear.png')],
            ]);

        $medium = $vehicle->media()->where('is_cover', true)->firstOrFail();

        $this->actingAs($manager)
            ->delete(route('admin.vehicles.media.destroy', [$vehicle, $medium]))
            ->assertRedirect();

        // Both halves go: the catalogue row and the stored file behind it.
        $this->assertSame(1, $vehicle->media()->count());
        $this->assertSame(1, $vehicle->photographs()->count(), 'The file was left behind.');

        // And the record is never left with pictures but no cover.
        $this->assertSame(1, $vehicle->media()->where('is_cover', true)->count());
    }

    public function test_a_photograph_belonging_to_another_vehicle_is_not_found(): void
    {
        $mine = Vehicle::factory()->create();
        $theirs = Vehicle::factory()->create();
        $manager = $this->manager();

        $this->actingAs($manager)
            ->patch(route('admin.vehicles.update', $theirs), $this->vehicleFields($theirs) + [
                'images' => [$this->image()],
            ]);

        $medium = $theirs->media()->firstOrFail();

        // 404, not 403: a foreign reference should not confirm it exists.
        $this->actingAs($manager)
            ->delete(route('admin.vehicles.media.destroy', [$mine, $medium]))
            ->assertNotFound();

        $this->assertSame(1, $theirs->media()->count());
    }

    public function test_the_vehicle_form_offers_a_file_input_not_a_url_box(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->actingAs($this->manager())
            ->get(route('admin.vehicles.edit', $vehicle))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('type="file"', false)
            ->assertDontSee('Image URL or path')
            // The long description box the summary replaced.
            ->assertDontSee('Full description');
    }

    public function test_editing_a_vehicle_does_not_change_its_public_address(): void
    {
        // Found by the deletion test above, which started 404ing on a vehicle
        // that plainly existed: saving with the slug box empty re-derived the
        // slug, so an edit to the mileage silently moved the public page and
        // broke every link to it.
        $vehicle = Vehicle::factory()->create(['slug' => 'the-white-prado']);

        $this->actingAs($this->manager())
            ->patch(route('admin.vehicles.update', $vehicle), $this->vehicleFields($vehicle))
            ->assertSessionHasNoErrors();

        $this->assertSame('the-white-prado', $vehicle->fresh()?->slug);
    }

    // -------------------------------------------------------------- properties

    public function test_a_property_accepts_photographs_from_the_machine(): void
    {
        $property = Property::factory()->create();

        $this->actingAs($this->manager())
            ->patch(route('admin.accommodation.update', $property), $this->propertyFields($property) + [
                'images' => [$this->image('lodge.png'), $this->image('room.png')],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(2, $property->media()->count());
        $this->assertSame(DocumentCategory::PropertyMedia, $property->media()->first()?->category);
    }

    public function test_a_property_saves_without_a_long_description(): void
    {
        // It was compulsory, at fifty characters minimum, which is why
        // properties were being left half-entered rather than finished.
        $property = Property::factory()->create();

        $this->actingAs($this->manager())
            ->patch(route('admin.accommodation.update', $property), $this->propertyFields($property))
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    public function test_saving_a_property_does_not_wipe_its_description(): void
    {
        $property = Property::factory()->create(['description' => 'A ten-room lodge on the crater rim.']);

        $this->actingAs($this->manager())
            ->patch(route('admin.accommodation.update', $property), $this->propertyFields($property))
            ->assertSessionHasNoErrors();

        $this->assertSame('A ten-room lodge on the crater rim.', $property->fresh()?->description);
    }

    public function test_the_property_form_offers_a_file_input(): void
    {
        $property = Property::factory()->create();

        $this->actingAs($this->manager())
            ->get(route('admin.accommodation.edit', $property))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('type="file"', false);
    }

    // ----------------------------------------------------------------- journal

    public function test_a_journal_post_accepts_a_cover_image(): void
    {
        $category = PostCategory::factory()->create();

        $this->actingAs($this->manager())
            ->post(route('admin.posts.store'), [
                'title' => 'Gorilla trekking in Bwindi',
                'excerpt' => 'What to expect on the trek, and what to carry with you.',
                'body' => str_repeat('The trek begins before dawn. ', 4),
                'post_category_id' => $category->id,
                'images' => [$this->image('bwindi.png')],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $post = Post::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $post->media()->count());

        // blog/index and blog/show have always rendered this. Until now there
        // was no field anywhere that could put a file behind it.
        $this->assertNotNull($post->coverUrl());
    }

    public function test_the_journal_form_offers_a_file_input(): void
    {
        $this->actingAs($this->manager())
            ->get(route('admin.posts.create'))
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('type="file"', false);
    }

    // ------------------------------------------------------------------ shared

    /**
     * Everything the vehicle form posts apart from the photographs.
     *
     * @return array<string, mixed>
     */
    private function vehicleFields(Vehicle $vehicle): array
    {
        return [
            'registration_plate' => $vehicle->registration_plate,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
            'color' => $vehicle->color,
            'condition' => $vehicle->condition,
            'vehicle_type' => $vehicle->vehicle_type,
            'fuel_type' => $vehicle->fuel_type,
            'transmission' => $vehicle->transmission,
            'seating_capacity' => $vehicle->seating_capacity,
            'luggage_capacity' => $vehicle->luggage_capacity,
            'summary' => $vehicle->summary,
            'catalogue_status' => $vehicle->catalogue_status->value,
            'operational_status' => $vehicle->operational_status->value,
        ];
    }

    /**
     * Everything the property form posts apart from the photographs.
     *
     * @return array<string, mixed>
     */
    private function propertyFields(Property $property): array
    {
        return [
            'name' => $property->name,
            'property_type' => $property->property_type->value,
            'region' => $property->region,
            'summary' => $property->summary,
            'check_in_from' => '14:00',
            'check_out_by' => '10:00',
            'cancellation_cutoff_hours' => 48,
        ];
    }
}
