<?php

namespace App\Actions\Content;

use App\Enums\AccountStatus;
use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Models\Post;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Moves a post between editorial states.
 *
 * Publishing and scheduling are the same operation with a different date, which
 * is why they share a code path: the difference between "live now" and "live on
 * Friday" should not be two implementations that can drift.
 */
class TransitionPost
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** Publishes immediately, or schedules when a future date is given. */
    public function publish(User $actor, Post $post, ?string $publishAt = null): Post
    {
        Validator::make(
            ['publish_at' => $publishAt],
            ['publish_at' => ['nullable', 'date']],
        )->validate();

        $moment = $publishAt === null
            ? CarbonImmutable::now()
            : CarbonImmutable::parse(
                $publishAt,
                (string) config('pisfa.business_timezone', 'Africa/Kampala'),
            )->utc();

        return DB::transaction(function () use ($actor, $post, $moment): Post {
            $lockedActor = $this->lockedEditor($actor);

            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();

            if (blank($locked->excerpt) || blank($locked->body)) {
                throw ValidationException::withMessages([
                    'body' => 'A post needs a summary and a body before it goes live.',
                ]);
            }

            // A future date means Scheduled, not Published with a date nobody
            // checks. Keeping them distinct is what stops a scheduling bug
            // leaking a post early.
            $next = $moment->isFuture() ? PostStatus::Scheduled : PostStatus::Published;

            $this->assertTransition($locked, $next);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $next,
                'published_at' => $moment,
                'archived_at' => null,
            ])->save();

            $this->auditLogger->record(
                event: $next === PostStatus::Scheduled ? 'post.scheduled' : 'post.published',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value, 'published_at' => $moment->toIso8601String()],
                user: $lockedActor,
            );

            return $locked->fresh(['tags', 'category']);
        }, 3);
    }

    /** Takes a post back off the public site without deleting it. */
    public function unpublish(User $actor, Post $post): Post
    {
        return $this->moveTo($actor, $post, PostStatus::Draft, 'post.unpublished');
    }

    public function archive(User $actor, Post $post): Post
    {
        return $this->moveTo($actor, $post, PostStatus::Archived, 'post.archived');
    }

    /**
     * Promotes a scheduled post whose moment has arrived.
     *
     * Returns false when nothing was due, so the sweep can count honestly.
     */
    public function releaseScheduled(Post $post, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return DB::transaction(function () use ($post, $at): bool {
            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->first();

            if ($locked === null
                || $locked->status !== PostStatus::Scheduled
                || $locked->published_at === null
                || $locked->published_at->isAfter($at)) {
                return false;
            }

            $locked->forceFill(['status' => PostStatus::Published])->save();

            $this->auditLogger->record(
                event: 'post.published',
                auditable: $locked,
                oldValues: ['status' => PostStatus::Scheduled->value],
                newValues: [
                    'status' => PostStatus::Published->value,
                    'published_at' => $locked->published_at->toIso8601String(),
                    'released_by' => 'schedule',
                ],
            );

            return true;
        }, 3);
    }

    private function moveTo(User $actor, Post $post, PostStatus $next, string $event): Post
    {
        return DB::transaction(function () use ($actor, $post, $next, $event): Post {
            $lockedActor = $this->lockedEditor($actor);

            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            $this->assertTransition($locked, $next);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $next,
                'archived_at' => $next === PostStatus::Archived ? now() : null,
                // The date is kept: a post taken down and put back should not
                // lose the day it was first published.
            ])->save();

            $this->auditLogger->record(
                event: $event,
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value],
                user: $lockedActor,
            );

            return $locked->fresh(['tags', 'category']);
        }, 3);
    }

    private function assertTransition(Post $post, PostStatus $next): void
    {
        if ($post->status === $next) {
            return;
        }

        if (! $post->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$post->status->label()} post cannot become {$next->label()}.",
            ]);
        }
    }

    private function lockedEditor(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== AccountStatus::Active || ! $locked->hasAnyRole(
            UserRole::Staff,
            UserRole::Manager,
            UserRole::SuperAdmin,
        )) {
            throw new AuthorizationException;
        }

        return $locked;
    }
}
