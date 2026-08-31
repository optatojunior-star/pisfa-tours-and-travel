<?php

namespace Tests\Feature\Reviews;

use App\Actions\Reviews\ModerateReview;
use App\Actions\Reviews\RecalculateReviewSummary;
use App\Actions\Reviews\SubmitReview;
use App\Enums\ReviewModerationAction;
use App\Enums\ReviewStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\Review;
use App\Models\ReviewSummary;
use App\Models\TourBooking;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class ReviewModerationTest extends TestCase
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

    // ---- Submission and eligibility --------------------------------------

    public function test_a_completed_booking_can_be_reviewed_and_starts_pending(): void
    {
        [$booking, $customer] = $this->completedBooking();

        $review = app(SubmitReview::class)->execute($customer, $booking, $this->payload());

        // Nothing a customer writes is public without a moderator.
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertFalse($review->isPublic());
        $this->assertNull($review->published_at);
        $this->assertSame($booking->tour_package_id, $review->reviewable_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'review.submitted']);
        $this->assertDatabaseHas('review_moderation_events', [
            'review_id' => $review->getKey(),
            'action' => ReviewModerationAction::Submitted->value,
        ]);
    }

    public function test_an_incomplete_booking_cannot_be_reviewed(): void
    {
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $this->bookableDeparture());

        $this->expectException(ValidationException::class);

        app(SubmitReview::class)->execute($customer, $booking, $this->payload());
    }

    public function test_a_customer_cannot_review_someone_elses_booking(): void
    {
        [$booking] = $this->completedBooking();
        $stranger = $this->customer();

        $this->expectException(AuthorizationException::class);

        app(SubmitReview::class)->execute($stranger, $booking, $this->payload());
    }

    public function test_a_booking_can_only_be_reviewed_once(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $action = app(SubmitReview::class);

        $action->execute($customer, $booking, $this->payload());

        $this->expectException(ValidationException::class);

        $action->execute($customer, $booking, $this->payload(['title' => 'Second attempt']));
    }

    public function test_a_rating_outside_one_to_five_is_rejected(): void
    {
        [$booking, $customer] = $this->completedBooking();

        $rejected = 0;

        foreach ([0, 6, -1] as $rating) {
            try {
                app(SubmitReview::class)->execute($customer, $booking, $this->payload(['rating' => $rating]));
                $this->fail("A rating of {$rating} should have been rejected.");
            } catch (ValidationException) {
                $rejected++;
            }
        }

        $this->assertSame(3, $rejected);
        $this->assertDatabaseCount('reviews', 0);
    }

    // ---- Moderation ------------------------------------------------------

    public function test_approving_publishes_the_review_and_records_history(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $staff = $this->operationsUser();
        $review = app(SubmitReview::class)->execute($customer, $booking, $this->payload());

        app(ModerateReview::class)->execute($staff, $review, ReviewStatus::Published);

        $review->refresh();
        $this->assertSame(ReviewStatus::Published, $review->status);
        $this->assertTrue($review->isPublic());
        $this->assertNotNull($review->published_at);
        $this->assertSame($staff->getKey(), $review->moderated_by_user_id);
        $this->assertDatabaseHas('review_moderation_events', [
            'review_id' => $review->getKey(),
            'action' => ReviewModerationAction::Approved->value,
        ]);
    }

    public function test_rejecting_requires_a_reason_the_author_can_act_on(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $staff = $this->operationsUser();
        $review = app(SubmitReview::class)->execute($customer, $booking, $this->payload());

        $this->expectException(ValidationException::class);

        app(ModerateReview::class)->execute($staff, $review, ReviewStatus::Rejected);
    }

    public function test_an_invalid_moderation_transition_is_refused(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $staff = $this->operationsUser();
        $review = app(SubmitReview::class)->execute($customer, $booking, $this->payload());

        // Pending cannot go straight to Unpublished.
        $this->expectException(ValidationException::class);

        app(ModerateReview::class)->execute($staff, $review, ReviewStatus::Unpublished);
    }

    public function test_a_customer_cannot_moderate(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $review = app(SubmitReview::class)->execute($customer, $booking, $this->payload());

        $this->expectException(AuthorizationException::class);

        app(ModerateReview::class)->execute($customer, $review, ReviewStatus::Published);
    }

    public function test_staff_can_reply_to_a_review(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $staff = $this->operationsUser();
        $review = app(SubmitReview::class)->execute($customer, $booking, $this->payload());

        app(ModerateReview::class)->reply($staff, $review, 'Thank you for travelling with PISFA.');

        $review->refresh();
        $this->assertTrue($review->hasReply());
        $this->assertSame($staff->getKey(), $review->replied_by_user_id);
        $this->assertDatabaseHas('review_moderation_events', [
            'review_id' => $review->getKey(),
            'action' => ReviewModerationAction::Replied->value,
        ]);
    }

    // ---- Summaries -------------------------------------------------------

    public function test_only_published_reviews_move_the_average(): void
    {
        $package = $this->publishedTour();

        Review::factory()->about($package)->rated(5)->withStatus(ReviewStatus::Published)->create();
        Review::factory()->about($package)->rated(3)->withStatus(ReviewStatus::Published)->create();
        // These must not count.
        Review::factory()->about($package)->rated(1)->withStatus(ReviewStatus::Pending)->create();
        Review::factory()->about($package)->rated(1)->withStatus(ReviewStatus::Rejected)->create();
        Review::factory()->about($package)->rated(1)->withStatus(ReviewStatus::Unpublished)->create();

        $summary = app(RecalculateReviewSummary::class)->execute($package);

        $this->assertSame(2, $summary->reviews_count);
        $this->assertSame(8, $summary->rating_sum);
        $this->assertSame(4.0, $summary->average());
        $this->assertSame(1, $summary->countFor(5));
        $this->assertSame(1, $summary->countFor(3));
        $this->assertSame(0, $summary->countFor(1));
    }

    public function test_unpublishing_removes_a_review_from_the_average(): void
    {
        $package = $this->publishedTour();
        $staff = $this->operationsUser();

        $keep = Review::factory()->about($package)->rated(5)->withStatus(ReviewStatus::Published)->create();
        $drop = Review::factory()->about($package)->rated(1)->withStatus(ReviewStatus::Published)->create();

        app(RecalculateReviewSummary::class)->execute($package);
        $this->assertSame(3.0, ReviewSummary::query()->sole()->average());

        app(ModerateReview::class)->execute($staff, $drop, ReviewStatus::Unpublished);

        $summary = ReviewSummary::query()->sole()->fresh();
        $this->assertSame(1, $summary->reviews_count);
        $this->assertSame(5.0, $summary->average());
        $this->assertSame(ReviewStatus::Published, $keep->fresh()->status);
    }

    public function test_a_subject_with_no_reviews_reports_no_average(): void
    {
        $package = $this->publishedTour();

        $summary = app(RecalculateReviewSummary::class)->execute($package);

        $this->assertSame(0, $summary->reviews_count);
        $this->assertNull($summary->average());
        $this->assertSame('No reviews yet', $summary->formattedAverage());
        $this->assertSame(0, $summary->percentageFor(5));
    }

    public function test_the_average_is_exact_and_recomputable(): void
    {
        $package = $this->publishedTour();

        foreach ([5, 4, 4, 3] as $rating) {
            Review::factory()->about($package)->rated($rating)->withStatus(ReviewStatus::Published)->create();
        }

        $summary = app(RecalculateReviewSummary::class)->execute($package);

        // 16 / 4 = exactly 4.0, from integer sum and count. No stored float.
        $this->assertSame(16, $summary->rating_sum);
        $this->assertSame(4, $summary->reviews_count);
        $this->assertSame(4.0, $summary->average());

        // Recomputing is stable.
        $again = app(RecalculateReviewSummary::class)->execute($package);
        $this->assertSame(16, $again->rating_sum);
        $this->assertSame(4, $again->reviews_count);
    }

    public function test_the_author_name_is_abbreviated_for_public_display(): void
    {
        $customer = $this->customer(['name' => 'Grace Nakato']);
        $review = Review::factory()->create(['customer_id' => $customer->getKey()]);

        // A public page must not publish a full surname.
        $this->assertSame('Grace N.', $review->fresh()->authorName());
    }

    public function test_only_operations_roles_may_moderate(): void
    {
        [$booking, $customer] = $this->completedBooking();
        $review = app(SubmitReview::class)->execute($customer, $booking, $this->payload());

        foreach ([UserRole::Driver, UserRole::Customer] as $role) {
            try {
                app(ModerateReview::class)->execute(
                    $this->user($role, ['two_factor_required' => false]),
                    $review,
                    ReviewStatus::Published,
                );
                $this->fail($role->value.' must not be able to moderate.');
            } catch (AuthorizationException) {
                // expected
            }
        }

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
    }
}
