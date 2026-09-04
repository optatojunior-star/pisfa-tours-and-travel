<?php

namespace App\Actions\Reviews;

use App\Enums\ReviewModerationAction;
use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\ReviewModerationEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A review from somebody who is not signed in.
 *
 * SubmitReview proves eligibility from a completed booking, which is the right
 * rule for a verified review and is also why nobody could leave one: most PISFA
 * customers arrange their trip over WhatsApp and never make an account.
 *
 * This accepts a review without that proof, and is honest about the difference.
 * Nothing is relaxed about publication — a public review lands Pending like
 * every other, and a moderator is still the only route to the public page. What
 * it does not do is claim the writer was a customer, because it cannot know.
 *
 * The protections that replace booking eligibility:
 *
 *  - the subject must be a published, publicly visible record, so a review
 *    cannot be attached to a draft tour or one that was taken down;
 *  - one review per email address per subject, enforced before the write, so
 *    the obvious way to flood a page with five-star reviews does not work;
 *  - the route is throttled, and the whole thing still requires a moderator.
 */
class SubmitPublicReview
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  Model  $subject  the tour, vehicle or property being reviewed
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Model $subject, array $attributes, ?User $customer = null): Review
    {
        $validated = Validator::make($attributes, [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'body' => ['required', 'string', 'min:20', 'max:5000'],
            'guest_name' => ['required', 'string', 'min:2', 'max:120'],
            'guest_email' => ['required', 'email:rfc', 'max:190'],
        ], [
            'body.min' => 'Tell us a little more — a couple of sentences helps other travellers.',
            'guest_name.required' => 'Please give the name you would like shown with your review.',
            'guest_email.required' => 'We need an email so we can reach you if there is a problem.',
        ])->validate();

        $email = Str::lower(trim($validated['guest_email']));

        return DB::transaction(function () use ($subject, $validated, $email, $customer): Review {
            // Locked before the duplicate check, so two submissions racing each
            // other cannot both find nothing and both insert.
            $existing = Review::query()
                ->where('reviewable_type', $subject->getMorphClass())
                ->where('reviewable_id', $subject->getKey())
                ->where('guest_email', $email)
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw ValidationException::withMessages([
                    'guest_email' => 'You have already left a review here. Get in touch if you would like to change it.',
                ]);
            }

            $review = Review::query()->create([
                'reference' => 'REV-'.Str::upper((string) Str::ulid()),
                // Linked to an account only when one is genuinely signed in.
                // Never invented: a placeholder user would be counted as a real
                // customer by every report that counts them.
                'customer_id' => $customer?->getKey(),
                'guest_name' => trim($validated['guest_name']),
                'guest_email' => $email,
                // No booking. The columns are nullable rather than filled with
                // something meaningless, so "was this a verified stay" stays a
                // question the data can answer.
                'booking_type' => null,
                'booking_id' => null,
                'reviewable_type' => $subject->getMorphClass(),
                'reviewable_id' => $subject->getKey(),
                'rating' => (int) $validated['rating'],
                'title' => trim($validated['title']),
                'body' => trim($validated['body']),
                'status' => ReviewStatus::Pending,
            ]);

            ReviewModerationEvent::query()->create([
                'review_id' => $review->getKey(),
                'actor_user_id' => $customer?->getKey(),
                'action' => ReviewModerationAction::Submitted,
                'to_status' => ReviewStatus::Pending,
            ]);

            $this->auditLogger->record(
                event: 'review.submitted_publicly',
                auditable: $review,
                newValues: [
                    'reference' => $review->reference,
                    'rating' => $review->rating,
                    'reviewable_type' => $review->reviewable_type,
                    'reviewable_id' => $review->reviewable_id,
                    'status' => ReviewStatus::Pending->value,
                    // The address itself is not written to the audit trail.
                    'from_guest' => true,
                ],
                user: $customer,
            );

            return $review;
        }, 3);
    }
}
