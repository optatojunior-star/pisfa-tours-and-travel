<?php

namespace Tests\Feature\Drivers;

use App\Enums\AccountStatus;
use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DriverProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The driver's headshot.
 *
 * It exists for one moment — a customer in arrivals at Entebbe deciding whether
 * the man walking towards them is the one PISFA sent — and it is a staff
 * member's face, so where it is stored matters as much as whether it shows up.
 */
class DriverPhotographTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_a_driver_uploads_their_own_photograph(): void
    {
        [$driver, $profile] = $this->driverWithProfile();

        $this->actingAs($driver)
            ->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $document = $profile->fresh()->photograph();

        $this->assertNotNull($document);
        $this->assertSame(DocumentCategory::DriverPhoto, $document->category);
    }

    /**
     * A team photograph is marketing and lives on the public disk. A driver's
     * headshot is not: it is shown to one customer for one trip, and a public
     * URL would outlive both.
     */
    public function test_the_photograph_is_stored_privately_and_has_no_public_url(): void
    {
        [$driver, $profile] = $this->driverWithProfile();

        $this->actingAs($driver)->post(route('drivers.photograph.store'), [
            'images' => [$this->photograph()],
        ])->assertRedirect();

        $document = $profile->fresh()->photograph();

        $this->assertSame(DocumentVisibility::Private, $document->visibility);
        $this->assertNull($document->url(), 'A driver photograph must not have a direct public URL.');
        $this->assertStringContainsString('/signed', (string) $profile->fresh()->photographUrl());
    }

    /** One face, not an album: a replacement supersedes rather than accumulates. */
    public function test_a_second_upload_replaces_the_first(): void
    {
        [$driver, $profile] = $this->driverWithProfile();

        $this->actingAs($driver)->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]]);
        $first = $profile->fresh()->photograph();

        $this->actingAs($driver)->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]]);
        $second = $profile->fresh()->photograph();

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(1, $profile->fresh()->photographs()->count());
        Storage::disk($first->disk)->assertMissing($first->path);
    }

    public function test_a_driver_removes_their_photograph(): void
    {
        [$driver, $profile] = $this->driverWithProfile();

        $this->actingAs($driver)->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]]);
        $this->assertNotNull($profile->fresh()->photograph());

        $this->actingAs($driver)
            ->delete(route('drivers.photograph.destroy'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($profile->fresh()->photograph());
    }

    /**
     * There is no route parameter to tamper with — the profile comes from the
     * session — so this checks the only door that exists is closed to everyone
     * who is not a driver.
     */
    public function test_only_a_driver_may_post_a_photograph(): void
    {
        foreach ([UserRole::Customer, UserRole::Staff, UserRole::Manager] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'status' => AccountStatus::Active,
                'email_verified_at' => now(),
            ]);

            $this->actingAs($user)
                ->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]])
                ->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_sign_in_rather_than_uploading(): void
    {
        // Its own test on purpose: actingAs() persists for the rest of a test
        // method, so asserting the guest case after a loop of signed-in ones
        // would silently be testing the last user again.
        $this->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]])
            ->assertRedirect(route('login'));
    }

    public function test_a_driver_without_a_profile_is_told_so_rather_than_erroring(): void
    {
        $driver = User::factory()->create([
            'role' => UserRole::Driver,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($driver)
            ->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]])
            ->assertNotFound();
    }

    public function test_a_document_that_is_not_an_image_is_refused(): void
    {
        [$driver, $profile] = $this->driverWithProfile();

        $this->actingAs($driver)
            ->post(route('drivers.photograph.store'), [
                'images' => [UploadedFile::fake()->create('licence.pdf', 40, 'application/pdf')],
            ])
            ->assertSessionHasErrors('images.0');

        $this->assertNull($profile->fresh()->photograph());
    }

    /**
     * The authorized document route delegates to the owning record's policy.
     * DriverProfile has none, so DocumentPolicy falls back to administration —
     * which is what stops one customer pulling another's driver photograph by
     * incrementing a document id.
     */
    public function test_the_authorized_document_route_is_closed_to_customers(): void
    {
        [$driver, $profile] = $this->driverWithProfile();

        $this->actingAs($driver)->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]]);
        $document = $profile->fresh()->photograph();

        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($customer)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_an_unsigned_request_for_the_photograph_is_refused(): void
    {
        [$driver, $profile] = $this->driverWithProfile();

        $this->actingAs($driver)->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]]);
        $document = $profile->fresh()->photograph();

        // The signed route without a signature: the whole authorization is the
        // signature, so a bare URL must not serve the file.
        $this->get(route('documents.signed', $document))->assertForbidden();
    }

    public function test_the_signed_link_serves_the_photograph(): void
    {
        [$driver, $profile] = $this->driverWithProfile();

        $this->actingAs($driver)->post(route('drivers.photograph.store'), ['images' => [$this->photograph()]]);

        $this->get($profile->fresh()->photographUrl())->assertOk();
    }

    /** @return array{User, DriverProfile} */
    private function driverWithProfile(): array
    {
        $driver = User::factory()->create([
            'role' => UserRole::Driver,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700111222',
        ]);

        $profile = DriverProfile::query()->create([
            'user_id' => $driver->getKey(),
            'licence_number' => 'UG-DL-11223344',
            'licence_expires_at' => now()->addYear()->toDateString(),
            'is_available' => true,
        ]);

        return [$driver, $profile];
    }

    private function photograph(): UploadedFile
    {
        // Above the 200x200 minimum FileInspector enforces.
        return UploadedFile::fake()->image('headshot.jpg', 600, 600);
    }

    public function test_the_document_category_is_never_treated_as_public_media(): void
    {
        $this->assertSame(DocumentVisibility::Private, DocumentCategory::DriverPhoto->visibility());
        $this->assertTrue(DocumentCategory::DriverPhoto->requiresImage());
        $this->assertFalse(
            DocumentCategory::DriverPhoto->isCollection(),
            'A driver has one face; a gallery would leave old headshots on disk forever.',
        );
        $this->assertFalse(DocumentCategory::DriverPhoto->isGenerated());
        $this->assertContains(DocumentCategory::DriverPhoto, DocumentCategory::uploadableCases());
        $this->assertSame(0, Document::query()->count());
    }
}
