<?php

namespace App\Actions\Reviews;

use App\Enums\AccountStatus;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\PropertyBookingStatus;
use App\Enums\ReviewModerationAction;
use App\Enums\ReviewStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\AirportTransferBooking;
use App\Models\CarHireBooking;
use App\Models\PropertyBooking;
use App\Models\Review;
use App\Models\ReviewModerationEvent;
use App\Models\TourBooking;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accepts a customer review for a booking they completed.
 *
 * Eligibility is proved by the booking, not asserted by the request: the
 * customer must own it and it must have reached a completed state. Reviews
 * always land in Pending — nothing a customer writes appears publicly without
 * a moderator.
 */
class SubmitReview
{
    // No summary rebuild here: a new review always lands Pending, and only a
    // published review counts towards a subject's average.
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(User $customer, Model $booking, array $attributes): Review
    {
        $validated = Validator::make($attributes, [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],
        ])->validate();

        if ($customer->status !== AccountStatus::Active || ! $customer->hasRole(UserRole::Customer)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($customer, $booking, $validated): Review {
            $lockedCustomer = User::query()
                ->whereKey($customer->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedBooking = $booking->newQuery()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEligible($lockedCustomer, $lockedBooking);

            $subject = $this->subjectFor($lockedBooking);

            try {
                $review = Review::query()->create([
                    'reference' => 'REV-'.Str::upper((string) Str::ulid()),
                    'customer_id' => $lockedCustomer->getKey(),
                    'booking_type' => $lockedBooking->getMorphClass(),
                    'booking_id' => $lockedBooking->getKey(),
                    'reviewable_type' => $subject->getMorphClass(),
                    'reviewable_id' => $subject->getKey(),
                    'rating' => (int) $validated['rating'],
                    'title' => trim($validated['title']),
                    'body' => trim($validated['body']),
                    // Never published on submission.
                    'status' => ReviewStatus::Pending,
                ]);
            } catch (QueryException) {
                // Unique (booking_type, booking_id) violated: this booking has
                // already been reviewed.
                throw ValidationException::withMessages([
                    'rating' => 'You have already reviewed this booking.',
                ]);
            }

            ReviewModerationEvent::query()->create([
                'review_id' => $review->getKey(),
                'actor_user_id' => $lockedCustomer->getKey(),
                'action' => ReviewModerationAction::Submitted,
                'to_status' => ReviewStatus::Pending,
            ]);

            $this->auditLogger->record(
                event: 'review.submitted',
                auditable: $review,
                newValues: [
                    'reference' => $review->reference,
                    'rating' => $review->rating,
                    'booking_type' => $review->booking_type,
                    'booking_id' => $review->booking_id,
                    'status' => ReviewStatus::Pending->value,
                ],
                user: $lockedCustomer,
            );

            return $review;
        }, 3);
    }

    /**
     * A customer may review only their own completed booking.
     */
    private function assertEligible(User $customer, Model $booking): void
    {
        if ((int) $booking->getAttribute('customer_id') !== $customer->getKey()) {
            throw new AuthorizationException;
        }

        if (! $this->isCompleted($booking)) {
            throw ValidationException::withMessages([
                'rating' => 'You can review this once the service has been completed.',
            ]);
        }
    }

    private function isCompleted(Model $booking): bool
    {
        return match (true) {
            $booking instanceof TourBooking => $booking->status === TourBookingStatus::Completed,
            $booking instanceof CarHireBooking => $booking->status === CarHireBookingStatus::Completed,
            $booking instanceof AirportTransferBooking => $booking->status === AirportTransferBookingStatus::Completed,
            // A stay is finished when the guest has checked out, not when the
            // dates have passed: somebody who left early still stayed.
            $booking instanceof PropertyBooking => $booking->status === PropertyBookingStatus::CheckedOut,
            default => false,
        };
    }

    /**
     * The publicly reviewed subject behind a booking. Many bookings of the same
     * tour or vehicle aggregate onto one subject.
     */
    private function subjectFor(Model $booking): Model
    {
        $subject = match (true) {
            $booking instanceof TourBooking => $booking->tourPackage,
            $booking instanceof CarHireBooking => $booking->vehicle,
            $booking instanceof AirportTransferBooking => $booking->airport,
            // The property, not the room type: guests review the place they
            // stayed, and many stays in different rooms aggregate onto it.
            $booking instanceof PropertyBooking => $booking->property,
            default => null,
        };

        if (! $subject instanceof Model) {
            throw ValidationException::withMessages([
                'rating' => 'This booking cannot be reviewed.',
            ]);
        }

        return $subject;
    }
}
