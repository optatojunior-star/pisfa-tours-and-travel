<?php

namespace Tests\Feature\Content;

use App\Enums\AccountStatus;
use App\Enums\DocumentCategory;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\ServiceImage;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the site says about the company.
 *
 * Two things that could only be changed by editing code: who is on the about
 * page, and what picture each service shows. Both now belong to whoever runs
 * the business rather than to whoever last deployed.
 */
class TeamAndServiceImageTest extends TestCase
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

    private function image(string $name = 'portrait.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 800, 800);
    }

    // -------------------------------------------------------------- the team

    public function test_a_profile_is_created_with_a_photograph(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.team.store'), [
                'name' => 'Agnes Kirabo',
                'role_title' => 'Head of Safaris',
                'summary' => 'Fifteen years guiding the northern circuit.',
                'images' => [$this->image()],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $member = TeamMember::query()->firstOrFail();

        $this->assertSame('Agnes Kirabo', $member->name);
        $this->assertSame(1, $member->photographs()->count());
        $this->assertSame(DocumentCategory::TeamPhoto, $member->photographs()->first()?->category);
        $this->assertNotNull($member->photoUrl());
    }

    /** Saving is not publishing, so a half-written profile is never public. */
    public function test_a_new_profile_is_not_on_the_site_until_it_is_published(): void
    {
        $member = TeamMember::factory()->create(['name' => 'Agnes Kirabo']);

        $this->assertFalse($member->is_published);

        $this->get(route('about'))
            ->assertOk()
            ->assertDontSee('Agnes Kirabo');
    }

    public function test_a_published_profile_appears_on_the_about_page(): void
    {
        $manager = $this->manager();
        $member = TeamMember::factory()->create([
            'name' => 'Agnes Kirabo',
            'role_title' => 'Head of Safaris',
        ]);

        $this->actingAs($manager)
            ->patch(route('admin.team.update', $member), [
                'name' => $member->name,
                'role_title' => $member->role_title,
                'images' => [$this->image()],
            ]);

        $this->actingAs($manager)
            ->post(route('admin.team.publish', $member))
            ->assertRedirect();

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('Agnes Kirabo')
            ->assertSee('Head of Safaris');
    }

    public function test_publishing_without_a_photograph_is_refused(): void
    {
        // A team grid with one blank silhouette reads as broken, and the fix is
        // one the person publishing can do there and then.
        $member = TeamMember::factory()->create();

        $this->actingAs($this->manager())
            ->post(route('admin.team.publish', $member))
            ->assertSessionHasErrors('images');

        $this->assertFalse($member->fresh()?->is_published);
    }

    public function test_the_about_page_has_no_team_section_when_nobody_is_published(): void
    {
        // An empty heading is worse than no heading.
        $this->get(route('about'))
            ->assertOk()
            ->assertDontSee('The people you will be dealing with');
    }

    public function test_removing_somebody_takes_their_photograph_with_them(): void
    {
        $manager = $this->manager();
        $member = TeamMember::factory()->create();

        $this->actingAs($manager)->patch(route('admin.team.update', $member), [
            'name' => $member->name,
            'role_title' => $member->role_title,
            'images' => [$this->image()],
        ]);

        $this->assertSame(1, $member->photographs()->count());

        $this->actingAs($manager)
            ->delete(route('admin.team.destroy', $member))
            ->assertRedirect(route('admin.team.index'));

        $this->assertNull(TeamMember::query()->find($member->getKey()));
    }

    public function test_a_customer_cannot_reach_the_team_screens(): void
    {
        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($customer)->get(route('admin.team.index'))->assertForbidden();
    }

    // --------------------------------------------------------- service images

    public function test_a_service_can_be_given_a_picture_of_its_own(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.service-images.store'), [
                'service_key' => 'car-hire',
                'images' => [$this->image('prado.png')],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $service = ServiceImage::query()->where('service_key', 'car-hire')->firstOrFail();

        $this->assertNotNull($service->imageUrl());
        $this->assertArrayHasKey('car-hire', ServiceImage::urlsByServiceKey());
    }

    public function test_an_unknown_service_key_is_refused(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.service-images.store'), [
                'service_key' => 'not-a-service',
                'images' => [$this->image()],
            ])
            ->assertSessionHasErrors('service_key');

        $this->assertSame(0, ServiceImage::query()->count());
    }

    public function test_replacing_a_picture_does_not_leave_the_old_one_behind(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('admin.service-images.store'), [
            'service_key' => 'car-hire',
            'images' => [$this->image('first.png')],
        ]);

        $this->actingAs($manager)->post(route('admin.service-images.store'), [
            'service_key' => 'car-hire',
            'images' => [$this->image('second.png')],
        ]);

        $service = ServiceImage::query()->where('service_key', 'car-hire')->firstOrFail();

        // One service, one picture — otherwise every superseded upload stays on
        // the public disk forever with nothing pointing at it.
        $this->assertSame(1, $service->photographs()->count());
    }

    public function test_removing_a_picture_falls_back_to_the_icon_rather_than_a_gap(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('admin.service-images.store'), [
            'service_key' => 'car-hire',
            'images' => [$this->image()],
        ]);

        $service = ServiceImage::query()->where('service_key', 'car-hire')->firstOrFail();

        $this->actingAs($manager)
            ->delete(route('admin.service-images.destroy', $service))
            ->assertRedirect();

        $this->assertArrayNotHasKey('car-hire', ServiceImage::urlsByServiceKey());

        // And the homepage still renders every service.
        $this->get(route('home'))->assertOk()->assertSee('Car Hire');
    }

    public function test_the_home_page_shows_an_uploaded_service_picture(): void
    {
        $this->actingAs($this->manager())->post(route('admin.service-images.store'), [
            'service_key' => 'car-hire',
            'images' => [$this->image('prado.png')],
        ]);

        $url = ServiceImage::urlsByServiceKey()['car-hire'];

        $this->get(route('home'))->assertOk()->assertSee($url, false);
    }
}
