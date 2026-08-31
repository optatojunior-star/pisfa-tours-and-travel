<?php

namespace Tests\Feature\Reviews;

use App\Actions\Reviews\RecalculateReviewSummary;
use App\Enums\ReviewStatus;
use App\Enums\TourBookingStatus;
use App\Enums\TourPackageStatus;
use App\Enums\UserRole;
use App\Models\Review;
use App\Models\TourBooking;
use App\Models\TourPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class ReviewHttpTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    /** @return array{0: TourBooking, 1: User} */
    private function completedBooking(): array
    {
        $customer = $this->customer();
        $booking = $this->persistedBooking(
            $customer,
            $this->bookableDeparture(),
            TourBookingStatus::Completed,
        );

        return [$booking, $customer];
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'rating' => 5,
            'title' => 'Superb safari',
            'body' => 'The guide was knowledgeable and the vehicle was comfortable throughout the trip.',
        ], $overrides);
    }

    private function moderator(): User
    {
        // The admin routes sit behind 2fa.required, so a moderator used over
        // HTTP must not be waiting on enrolment.
        return $this->operationsUser(UserRole::Staff, ['two_factor_required' => false]);
    }

    // ---- Customer portal -------------------------------------------------

    public function test_a_customer_submits_a_review_over_http(): void
    {
        [$booking, $customer] = $this->completedBooking();

        $this->actingAs($customer)
            ->get(route('portal.reviews.create', $booking))
            ->assertOk()
            ->assertSee($booking->package_name_snapshot);

        $this->actingAs($customer)
            ->post(route('portal.reviews.store', $booking), $this->payload())
            ->assertRedirect(route('portal.reviews.index'))
            ->assertSessionHas('success');

        $review = Review::query()->sole();
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertSame($customer->getKey(), $review->customer_id);
    }

    public function test_a_guest_cannot_reach_the_review_portal(): void
    {
        [$booking] = $this->completedBooking();

        $this->get(route('portal.reviews.index'))->assertRedirect(route('login'));
        $this->post(route('portal.reviews.store', $booking), $this->payload())
            ->assertRedirect(route('login'));
    }

    public function test_a_customer_cannot_write_on_someone_elses_booking(): void
    {
        [$booking] = $this->completedBooking();
        $stranger = $this->customer();

        // The scoped binding hides a foreign booking entirely, so this is a 404
        // and not a 403: a stranger learns nothing about whose booking it is.
        $this->actingAs($stranger)
            ->get(route('portal.reviews.create', $booking))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->post(route('portal.reviews.store', $booking), $this->payload())
            ->assertNotFound();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_an_incomplete_booking_is_refused_over_http(): void
    {
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $this->bookableDeparture());

        $this->actingAs($customer)
            ->post(route('portal.reviews.store', $booking), $this->payload())
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_editing_a_published_review_returns_it_to_moderation(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $review = Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->withStatus(ReviewStatus::Published)
            ->create();

        $this->actingAs($customer)
            ->patch(route('portal.reviews.update', $review), $this->payload([
                'title' => 'Revised headline',
            ]))
            ->assertRedirect(route('portal.reviews.index'));

        $review->refresh();
        // A published review must not be silently rewritten after approval.
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertNull($review->published_at);
        $this->assertSame('Revised headline', $review->title);
    }

    public function test_a_customer_can_withdraw_their_own_review(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $review = Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->withStatus(ReviewStatus::Published)
            ->create();

        $this->actingAs($customer)
            ->delete(route('portal.reviews.destroy', $review))
            ->assertRedirect(route('portal.reviews.index'));

        $this->assertSoftDeleted('reviews', ['id' => $review->getKey()]);
        // The moderation trail survives the withdrawal.
        $this->assertDatabaseHas('review_moderation_events', [
            'review_id' => $review->getKey(),
            'action' => 'deleted',
        ]);
    }

    public function test_a_customer_cannot_open_or_edit_another_customers_review(): void
    {
        [$booking] = $this->completedBooking();
        $review = Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->create(['title' => 'The author wrote this']);
        $stranger = $this->customer();

        $this->actingAs($stranger)
            ->get(route('portal.reviews.edit', $review))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->patch(route('portal.reviews.update', $review), $this->payload())
            ->assertNotFound();

        $this->assertSame('The author wrote this', $review->fresh()->title);
    }

    // ---- Admin console ---------------------------------------------------

    public function test_a_moderator_publishes_a_review_over_http(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $review = Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->create();

        $this->actingAs($this->moderator())
            ->patch(route('admin.reviews.moderate', $review), [
                'status' => ReviewStatus::Published->value,
            ])
            ->assertRedirect();

        $this->assertSame(ReviewStatus::Published, $review->fresh()->status);
        $this->assertSame($customer->getKey(), $review->customer_id);
    }

    public function test_rejecting_over_http_requires_a_note(): void
    {
        [$booking] = $this->completedBooking();
        $review = Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->create();

        $this->actingAs($this->moderator())
            ->patch(route('admin.reviews.moderate', $review), [
                'status' => ReviewStatus::Rejected->value,
            ])
            ->assertSessionHasErrors('note');

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    public function test_a_customer_cannot_reach_the_moderation_console(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $review = Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->create();

        $this->actingAs($customer)->get(route('admin.reviews.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.reviews.show', $review))->assertForbidden();
        $this->actingAs($customer)
            ->patch(route('admin.reviews.moderate', $review), [
                'status' => ReviewStatus::Published->value,
            ])
            ->assertForbidden();

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }

    public function test_the_console_lists_and_filters_by_status(): void
    {
        [$booking] = $this->completedBooking();
        $pending = Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->create(['title' => 'Pending headline']);
        $published = Review::factory()
            ->about($booking->tourPackage)
            ->withStatus(ReviewStatus::Published)
            ->create(['title' => 'Published headline']);

        $this->actingAs($this->moderator())
            ->get(route('admin.reviews.index', ['status' => ReviewStatus::Pending->value]))
            ->assertOk()
            ->assertSee($pending->title)
            ->assertDontSee($published->title);
    }

    public function test_a_moderator_replies_over_http(): void
    {
        [$booking] = $this->completedBooking();
        $review = Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->withStatus(ReviewStatus::Published)
            ->create();

        $this->actingAs($this->moderator())
            ->post(route('admin.reviews.reply', $review), [
                'reply_body' => 'Thank you for travelling with PISFA.',
            ])
            ->assertRedirect();

        $this->assertTrue($review->fresh()->hasReply());
    }

    // ---- Public surface --------------------------------------------------

    public function test_a_public_tour_page_shows_only_published_reviews(): void
    {
        $package = $this->publishedTour();

        $published = Review::factory()
            ->about($package)
            ->rated(5)
            ->withStatus(ReviewStatus::Published)
            ->create(['title' => 'Unforgettable gorilla trek', 'body' => 'Everything ran on time.']);

        $hidden = [
            Review::factory()->about($package)->withStatus(ReviewStatus::Pending)
                ->create(['title' => 'Awaiting moderation headline', 'body' => 'Not yet judged.']),
            Review::factory()->about($package)->withStatus(ReviewStatus::Rejected)
                ->create(['title' => 'Rejected headline', 'body' => 'Abusive wording.']),
            Review::factory()->about($package)->withStatus(ReviewStatus::Unpublished)
                ->create(['title' => 'Unpublished headline', 'body' => 'Taken down.']),
        ];

        app(RecalculateReviewSummary::class)->execute($package);

        $response = $this->get(route('tours.show', $package))->assertOk();

        $response->assertSee($published->title);
        $response->assertSee('Traveller reviews');

        foreach ($hidden as $review) {
            // A visitor must never see a review that is not published.
            $response->assertDontSee($review->title);
            $response->assertDontSee($review->body);
        }
    }

    public function test_a_public_tour_page_never_publishes_a_full_surname(): void
    {
        $package = $this->publishedTour();
        $customer = $this->customer(['name' => 'Grace Nakato']);

        Review::factory()
            ->about($package)
            ->withStatus(ReviewStatus::Published)
            ->create(['customer_id' => $customer->getKey()]);

        app(RecalculateReviewSummary::class)->execute($package);

        $this->get(route('tours.show', $package))
            ->assertOk()
            ->assertSee('Grace N.')
            ->assertDontSee('Nakato');
    }

    public function test_a_tour_with_no_published_reviews_shows_no_ratings_block(): void
    {
        $package = $this->publishedTour();

        Review::factory()->about($package)->withStatus(ReviewStatus::Pending)->create();
        app(RecalculateReviewSummary::class)->execute($package);

        $this->get(route('tours.show', $package))
            ->assertOk()
            ->assertDontSee('Traveller reviews');
    }

    public function test_a_moderator_reply_is_only_public_once_the_review_is(): void
    {
        $package = $this->publishedTour();
        $reply = 'We are glad the trek went well.';

        $review = Review::factory()
            ->about($package)
            ->withStatus(ReviewStatus::Pending)
            ->create(['reply_body' => $reply]);

        app(RecalculateReviewSummary::class)->execute($package);

        $this->get(route('tours.show', $package))->assertOk()->assertDontSee($reply);

        $review->forceFill(['status' => ReviewStatus::Published, 'published_at' => now()])->save();
        app(RecalculateReviewSummary::class)->execute($package->fresh());

        $this->get(route('tours.show', $package))->assertOk()->assertSee($reply);
    }

    public function test_a_moderation_note_is_never_rendered_publicly(): void
    {
        $package = $this->publishedTour();
        $note = 'Contains a competitor phone number.';

        Review::factory()
            ->about($package)
            ->withStatus(ReviewStatus::Published)
            ->create(['moderation_note' => $note]);

        app(RecalculateReviewSummary::class)->execute($package);

        $this->get(route('tours.show', $package))->assertOk()->assertDontSee($note);
    }

    public function test_the_average_shown_publicly_matches_the_published_ratings(): void
    {
        $package = $this->publishedTour();

        foreach ([5, 4, 4, 3] as $rating) {
            Review::factory()->about($package)->rated($rating)->withStatus(ReviewStatus::Published)->create();
        }
        // Ignored by the average.
        Review::factory()->about($package)->rated(1)->withStatus(ReviewStatus::Pending)->create();

        app(RecalculateReviewSummary::class)->execute($package);

        $this->get(route('tours.show', $package))
            ->assertOk()
            ->assertSee('4.0')
            ->assertSee('4 reviews');
    }

    public function test_an_unpublished_tour_is_not_reachable_even_with_reviews(): void
    {
        $package = TourPackage::factory()->create(['status' => TourPackageStatus::Draft]);

        Review::factory()->about($package)->withStatus(ReviewStatus::Published)->create();

        $this->get(route('tours.show', $package))->assertNotFound();
    }
}
