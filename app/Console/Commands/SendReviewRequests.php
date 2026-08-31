<?php

namespace App\Console\Commands;

use App\Enums\TourBookingEventType;
use App\Enums\TourBookingStatus;
use App\Models\TourBooking;
use App\Models\TourBookingEvent;
use App\Notifications\Reviews\ReviewRequestNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Asks customers to review a completed booking, once each.
 *
 * Two passes, as elsewhere in the codebase: the first records a marker row under
 * the unique (booking, event_type) constraint, the second mails the markers that
 * are still unprocessed. A crash between the two leaves a retryable marker
 * rather than a lost or duplicated email.
 */
class SendReviewRequests extends Command
{
    protected $signature = 'reviews:send-requests';

    protected $description = 'Invite customers to review bookings that completed within the request window';

    public function handle(): int
    {
        $now = now();
        $delayHours = (int) config('reviews.requests.delay_hours', 24);
        $maxAgeDays = (int) config('reviews.requests.max_age_days', 30);
        $batchSize = (int) config('reviews.requests.batch_size', 100);

        // Old enough to have finished the trip, recent enough that asking is
        // still reasonable.
        $completedBefore = $now->copy()->subHours($delayHours);
        $completedAfter = $now->copy()->subDays($maxAgeDays);

        $markersCreated = 0;

        TourBooking::query()
            ->where('status', TourBookingStatus::Completed->value)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $completedBefore)
            ->where('completed_at', '>=', $completedAfter)
            ->whereNotNull('customer_id')
            // Someone who already reviewed does not need an invitation.
            ->whereDoesntHave('review')
            ->whereDoesntHave('events', fn (Builder $event) => $event
                ->where('event_type', TourBookingEventType::ReviewRequestSent->value))
            ->orderBy('id')
            ->select('id')
            ->chunkById($batchSize, function ($bookings) use (&$markersCreated, $completedBefore, $completedAfter): void {
                foreach ($bookings as $booking) {
                    $created = DB::transaction(function () use ($booking, $completedBefore, $completedAfter): bool {
                        $locked = TourBooking::query()
                            ->whereKey($booking->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($locked === null
                            || $locked->status !== TourBookingStatus::Completed
                            || $locked->customer_id === null
                            || $locked->completed_at === null
                            || $locked->completed_at->greaterThan($completedBefore)
                            || $locked->completed_at->lessThan($completedAfter)) {
                            return false;
                        }

                        return TourBookingEvent::query()->firstOrCreate(
                            [
                                'tour_booking_id' => $locked->getKey(),
                                'event_type' => TourBookingEventType::ReviewRequestSent->value,
                            ],
                            [
                                'payload' => [
                                    'schema_version' => 1,
                                    'booking_reference' => $locked->reference,
                                    'completed_at' => $locked->completed_at->toIso8601String(),
                                ],
                                'processed_at' => null,
                            ],
                        )->wasRecentlyCreated;
                    }, 3);

                    $markersCreated += $created ? 1 : 0;
                }
            });

        $dispatched = 0;
        $failures = 0;

        TourBookingEvent::query()
            ->where('event_type', TourBookingEventType::ReviewRequestSent->value)
            ->pending()
            ->orderBy('id')
            ->select('id')
            ->chunkById($batchSize, function ($events) use (&$dispatched, &$failures): void {
                foreach ($events as $event) {
                    try {
                        $queued = DB::transaction(function () use ($event): bool {
                            $lockedEvent = TourBookingEvent::query()
                                ->with(['booking.customer', 'booking.review'])
                                ->whereKey($event->getKey())
                                ->lockForUpdate()
                                ->first();

                            if ($lockedEvent === null || $lockedEvent->processed_at !== null) {
                                return false;
                            }

                            $booking = $lockedEvent->booking;
                            $customer = $booking?->customer;

                            // A booking that was reopened, or reviewed between
                            // the two passes, consumes the marker without mail.
                            if ($booking === null
                                || $customer === null
                                || $booking->status !== TourBookingStatus::Completed
                                || $booking->review !== null) {
                                $lockedEvent->forceFill(['processed_at' => now()])->save();

                                return false;
                            }

                            $customer->notify(new ReviewRequestNotification(
                                bookingReference: $booking->reference,
                                tourName: $booking->package_name_snapshot,
                                reviewUrl: route('portal.reviews.create', $booking),
                            ));

                            $lockedEvent->forceFill(['processed_at' => now()])->save();

                            return true;
                        }, 3);

                        $dispatched += $queued ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        // Leave the marker retryable rather than silently
                        // dropping the invitation.
                        TourBookingEvent::query()
                            ->whereKey($event->getKey())
                            ->where('event_type', TourBookingEventType::ReviewRequestSent->value)
                            ->update(['processed_at' => null, 'updated_at' => now()]);
                        report($exception);
                        $this->components->error("Review request {$event->getKey()} could not be queued and remains retryable.");
                    }
                }
            });

        $this->components->info(
            "Review requests: {$markersCreated} marker(s) created; {$dispatched} notification(s) queued; {$failures} failure(s).",
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
