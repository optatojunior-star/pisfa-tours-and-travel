<?php

namespace Tests\Feature\Reviews;

use App\Enums\ReviewStatus;
use App\Enums\TourBookingEventType;
use App\Enums\TourBookingStatus;
use App\Models\Review;
use App\Models\TourBooking;
use App\Models\TourBookingEvent;
use App\Models\User;
use App\Notifications\Reviews\ReviewRequestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class SendReviewRequestsTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    /** @return array{0: TourBooking, 1: User} */
    private function bookingCompletedDaysAgo(float $days, TourBookingStatus $status = TourBookingStatus::Completed): array
    {
        $customer = $this->customer();
        $booking = $this->persistedBooking(
            $customer,
            $this->bookableDeparture(),
            $status,
            1,
            ['completed_at' => now()->subHours((int) round($days * 24))],
        );

        return [$booking, $customer];
    }

    public function test_a_completed_booking_receives_one_invitation(): void
    {
        [$booking, $customer] = $this->bookingCompletedDaysAgo(3);

        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertSentTo(
            $customer,
            ReviewRequestNotification::class,
            fn (ReviewRequestNotification $notification): bool => $notification->bookingReference === $booking->reference,
        );

        $this->assertDatabaseHas('tour_booking_events', [
            'tour_booking_id' => $booking->getKey(),
            'event_type' => TourBookingEventType::ReviewRequestSent->value,
        ]);
    }

    public function test_a_second_sweep_never_asks_twice(): void
    {
        [, $customer] = $this->bookingCompletedDaysAgo(3);

        $this->artisan('reviews:send-requests')->assertSuccessful();
        $this->artisan('reviews:send-requests')->assertSuccessful();
        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertSentToTimes($customer, ReviewRequestNotification::class, 1);
        $this->assertSame(1, TourBookingEvent::query()
            ->where('event_type', TourBookingEventType::ReviewRequestSent->value)
            ->count());
    }

    public function test_a_booking_that_only_just_finished_is_left_alone(): void
    {
        // Inside the delay window: the trip has barely ended.
        [, $customer] = $this->bookingCompletedDaysAgo(0.25);

        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertNothingSentTo($customer);
        $this->assertDatabaseCount('tour_booking_events', 0);
    }

    public function test_a_booking_older_than_the_cutoff_is_never_asked(): void
    {
        // Beyond max_age_days. A first deployment against historic data must not
        // mail every customer who ever travelled with PISFA.
        [, $customer] = $this->bookingCompletedDaysAgo(120);

        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertNothingSentTo($customer);
        $this->assertDatabaseCount('tour_booking_events', 0);
    }

    public function test_an_uncompleted_booking_is_never_asked(): void
    {
        $customer = $this->customer();
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Confirmed);
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Cancelled);

        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertNothingSentTo($customer);
    }

    public function test_a_booking_already_reviewed_is_not_invited(): void
    {
        [$booking, $customer] = $this->bookingCompletedDaysAgo(3);

        Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->withStatus(ReviewStatus::Published)
            ->create();

        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertNothingSentTo($customer);
        $this->assertDatabaseCount('tour_booking_events', 0);
    }

    public function test_a_review_written_between_the_two_passes_consumes_the_marker_without_mail(): void
    {
        [$booking, $customer] = $this->bookingCompletedDaysAgo(3);

        // Simulate the first pass having run, then the customer reviewing
        // before the second pass got to the marker.
        TourBookingEvent::query()->create([
            'tour_booking_id' => $booking->getKey(),
            'event_type' => TourBookingEventType::ReviewRequestSent->value,
            'payload' => ['schema_version' => 1, 'booking_reference' => $booking->reference],
            'processed_at' => null,
        ]);

        Review::factory()
            ->forBooking($booking)
            ->about($booking->tourPackage)
            ->create();

        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertNothingSentTo($customer);
        // The marker is closed rather than left to retry forever.
        $this->assertNotNull(TourBookingEvent::query()
            ->where('event_type', TourBookingEventType::ReviewRequestSent->value)
            ->sole()
            ->processed_at);
    }

    public function test_the_invitation_links_to_the_customers_own_review_form(): void
    {
        [$booking, $customer] = $this->bookingCompletedDaysAgo(3);

        $this->artisan('reviews:send-requests')->assertSuccessful();

        Notification::assertSentTo(
            $customer,
            ReviewRequestNotification::class,
            fn (ReviewRequestNotification $notification): bool => $notification->reviewUrl
                === route('portal.reviews.create', $booking),
        );
    }
}
