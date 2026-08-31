<?php

namespace App\Actions\Reviews;

use App\Enums\AccountStatus;
use App\Enums\ReviewModerationAction;
use App\Enums\ReviewStatus;
use App\Enums\UserRole;
use App\Models\Review;
use App\Models\ReviewModerationEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Approves, unpublishes, or rejects a review, and records who did it.
 *
 * Every status change recomputes the subject's summary, so an average can never
 * drift out of step with what is actually published.
 */
class ModerateReview
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly RecalculateReviewSummary $summaries,
    ) {}

    public function execute(
        User $actor,
        Review $review,
        ReviewStatus $next,
        ?string $note = null,
    ): Review {
        $note = $note === null ? null : trim($note);

        Validator::make(
            ['note' => $note],
            ['note' => ['nullable', 'string', 'max:2000']],
        )->validate();

        $this->ensureModerator($actor);

        $review = DB::transaction(function () use ($actor, $review, $next, $note): Review {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureModerator($lockedActor);

            $locked = Review::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status->label()} review cannot become {$next->label()}.",
                ]);
            }

            // A rejection must tell the author why, or they cannot fix it.
            if ($next === ReviewStatus::Rejected && $note === null) {
                throw ValidationException::withMessages([
                    'note' => 'Give a reason so the customer can address it.',
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $next,
                'moderated_by_user_id' => $lockedActor->getKey(),
                'moderated_at' => now(),
                'moderation_note' => $note ?? $locked->moderation_note,
                'published_at' => $next === ReviewStatus::Published
                    ? ($locked->published_at ?? now())
                    : $locked->published_at,
            ])->save();

            ReviewModerationEvent::query()->create([
                'review_id' => $locked->getKey(),
                'actor_user_id' => $lockedActor->getKey(),
                'action' => $this->actionFor($next),
                'from_status' => $previous,
                'to_status' => $next,
                'note' => $note,
            ]);

            $this->auditLogger->record(
                event: 'review.'.$next->value,
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value, 'moderated_at' => now()->toIso8601String()],
                context: ['note_present' => $note !== null],
                user: $lockedActor,
            );

            return $locked;
        }, 3);

        // Outside the review transaction so a summary rebuild cannot hold the
        // review row, but still synchronous so the console reflects it at once.
        $subject = $review->reviewable;

        if ($subject !== null) {
            $this->summaries->execute($subject);
        }

        return $review->fresh(['customer', 'moderationEvents']);
    }

    /** Records a staff reply, shown beneath the review when published. */
    public function reply(User $actor, Review $review, string $body): Review
    {
        $body = trim($body);

        Validator::make(
            ['reply_body' => $body],
            ['reply_body' => ['required', 'string', 'min:5', 'max:2000']],
        )->validate();

        $this->ensureModerator($actor);

        return DB::transaction(function () use ($actor, $review, $body): Review {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureModerator($lockedActor);

            $locked = Review::query()->whereKey($review->getKey())->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'reply_body' => $body,
                'replied_by_user_id' => $lockedActor->getKey(),
                'replied_at' => now(),
            ])->save();

            ReviewModerationEvent::query()->create([
                'review_id' => $locked->getKey(),
                'actor_user_id' => $lockedActor->getKey(),
                'action' => ReviewModerationAction::Replied,
                'from_status' => $locked->status,
                'to_status' => $locked->status,
            ]);

            $this->auditLogger->record(
                event: 'review.replied',
                auditable: $locked,
                newValues: ['replied_at' => now()->toIso8601String()],
                user: $lockedActor,
            );

            return $locked->fresh(['customer', 'repliedBy', 'moderationEvents']);
        }, 3);
    }

    private function actionFor(ReviewStatus $status): ReviewModerationAction
    {
        return match ($status) {
            ReviewStatus::Published => ReviewModerationAction::Approved,
            ReviewStatus::Unpublished => ReviewModerationAction::Unpublished,
            ReviewStatus::Rejected => ReviewModerationAction::Rejected,
            ReviewStatus::Pending => ReviewModerationAction::Edited,
        };
    }

    private function ensureModerator(User $actor): void
    {
        if ($actor->status !== AccountStatus::Active || ! $actor->hasAnyRole(
            UserRole::Staff,
            UserRole::Manager,
            UserRole::SuperAdmin,
        )) {
            throw new AuthorizationException;
        }
    }
}
