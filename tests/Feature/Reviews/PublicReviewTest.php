<?php

namespace Tests\Feature\Reviews;

use App\Actions\Reviews\ModerateReview;
use App\Enums\AccountStatus;
use App\Enums\ReviewStatus;
use App\Enums\TourPackageStatus;
use App\Enums\UserRole;
use App\Models\Review;
use App\Models\TourCategory;
use App\Models\TourPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leaving a review without an account.
 *
 * The review system was complete and unreachable: moderation queue, summary
 * table, public display, and a rule that you must have a completed booking made
 * from a registered account. Most PISFA customers arrange their trip over
 * WhatsApp and never sign up, so in practice nobody could leave a review at all.
 *
 * These tests cover the opening and, more importantly, that it stayed narrow:
 * publication still needs a moderator, and the obvious ways to abuse an open
 * form are closed.
 */
class PublicReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function publishedTour(): TourPackage
    {
        $category = TourCategory::factory()->create(['is_active' => true]);

        return TourPackage::factory()->create([
            'tour_category_id' => $category->getKey(),
            'status' => TourPackageStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'rating' => 5,
            'title' => 'Worth every early morning',
            'body' => 'The guide knew every trail and we saw far more than we expected to.',
            'guest_name' => 'Grace Nakato',
            'guest_email' => 'grace@example.com',
        ], $overrides);
    }

    public function test_a_visitor_with_no_account_can_leave_a_review(): void
    {
        $tour = $this->publishedTour();

        $this->post(route('tours.reviews.store', $tour), $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $review = Review::query()->firstOrFail();

        $this->assertSame(5, $review->rating);
        $this->assertSame('Grace Nakato', $review->guest_name);

        // No account, and none invented: a placeholder user would be counted as
        // a real customer by every report that counts them.
        $this->assertNull($review->customer_id);
        $this->assertNull($review->booking_id);
    }

    public function test_nothing_appears_on_the_site_until_a_moderator_reads_it(): void
    {
        $tour = $this->publishedTour();

        $this->post(route('tours.reviews.store', $tour), $this->payload([
            'title' => 'Absolutely superb',
        ]));

        $this->assertSame(ReviewStatus::Pending, Review::query()->firstOrFail()->status);

        $this->get(route('tours.show', $tour))
            ->assertOk()
            ->assertDontSee('Absolutely superb');
    }

    public function test_the_writer_is_told_it_is_waiting_rather_than_left_guessing(): void
    {
        $tour = $this->publishedTour();

        $this->followingRedirects()
            ->post(route('tours.reviews.store', $tour), $this->payload())
            ->assertOk()
            ->assertSee('Thank you.')
            ->assertSee('once somebody has read it');
    }

    public function test_the_form_is_on_a_tour_that_has_no_reviews_yet(): void
    {
        // The whole reviews block used to be wrapped in "if there are any", so a
        // new tour offered no way to leave the first one.
        $tour = $this->publishedTour();

        $this->get(route('tours.show', $tour))
            ->assertOk()
            ->assertSee('Been on this trip?')
            ->assertSee(route('tours.reviews.store', $tour), false);
    }

    public function test_one_review_per_email_on_the_same_tour(): void
    {
        $tour = $this->publishedTour();

        $this->post(route('tours.reviews.store', $tour), $this->payload());

        $this->post(route('tours.reviews.store', $tour), $this->payload([
            'title' => 'Second bite at it',
        ]))->assertSessionHasErrors('guest_email');

        $this->assertSame(1, Review::query()->count());
    }

    public function test_the_same_email_is_recognised_whatever_the_capitals(): void
    {
        $tour = $this->publishedTour();

        $this->post(route('tours.reviews.store', $tour), $this->payload());
        $this->post(route('tours.reviews.store', $tour), $this->payload([
            'guest_email' => 'GRACE@Example.COM',
        ]))->assertSessionHasErrors('guest_email');

        $this->assertSame(1, Review::query()->count());
    }

    public function test_the_same_person_may_review_a_different_tour(): void
    {
        $first = $this->publishedTour();
        $second = $this->publishedTour();

        $this->post(route('tours.reviews.store', $first), $this->payload());
        $this->post(route('tours.reviews.store', $second), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Review::query()->count());
    }

    public function test_a_draft_tour_cannot_be_reviewed_and_does_not_admit_it_exists(): void
    {
        $category = TourCategory::factory()->create(['is_active' => true]);
        $draft = TourPackage::factory()->create([
            'tour_category_id' => $category->getKey(),
            'status' => TourPackageStatus::Draft,
            'published_at' => null,
        ]);

        // 404 rather than 403: a draft should not confirm itself to somebody
        // guessing slugs.
        $this->post(route('tours.reviews.store', $draft), $this->payload())
            ->assertNotFound();

        $this->assertSame(0, Review::query()->count());
    }

    public function test_a_rating_outside_one_to_five_is_refused(): void
    {
        $tour = $this->publishedTour();

        $this->post(route('tours.reviews.store', $tour), $this->payload(['rating' => 9]))
            ->assertSessionHasErrors('rating');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_a_one_word_review_is_refused_with_a_reason(): void
    {
        $tour = $this->publishedTour();

        $this->post(route('tours.reviews.store', $tour), $this->payload(['body' => 'Good.']))
            ->assertSessionHasErrors('body');
    }

    public function test_a_signed_in_customer_keeps_their_identity_on_the_review(): void
    {
        $tour = $this->publishedTour();
        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($customer)
            ->post(route('tours.reviews.store', $tour), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame($customer->getKey(), Review::query()->firstOrFail()->customer_id);
    }

    /** The address is collected to reach the writer, never to publish. */
    public function test_a_published_review_never_shows_the_writers_email(): void
    {
        $tour = $this->publishedTour();
        $this->post(route('tours.reviews.store', $tour), $this->payload());

        $moderator = User::factory()->create([
            'role' => UserRole::Manager,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        app(ModerateReview::class)->execute(
            $moderator,
            Review::query()->firstOrFail(),
            ReviewStatus::Published,
        );

        $response = $this->get(route('tours.show', $tour))->assertOk();

        $response->assertSee('Worth every early morning');
        $response->assertDontSee('grace@example.com');

        // Shown as a first name and an initial, like every other review.
        $response->assertSee('Grace N.');
    }
}
