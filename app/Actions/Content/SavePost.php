<?php

namespace App\Actions\Content;

use App\Enums\AccountStatus;
use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Models\Post;
use App\Models\PostTag;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes a post and its tags.
 *
 * The slug is the public URL. It is derived from the title on creation and
 * **frozen once the post has been public**, because changing it silently breaks
 * every link anyone has shared and every search result pointing at it. Renaming
 * a live post's URL should be a deliberate act with a redirect behind it, not a
 * side effect of fixing a typo in the headline.
 */
class SavePost
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): Post
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $input): Post {
            $lockedActor = $this->lockedEditor($actor);

            $post = new Post;
            $post->forceFill(array_merge($input['post'], [
                'slug' => $this->uniqueSlug($input['post']['title']),
                'author_user_id' => $lockedActor->getKey(),
                'status' => PostStatus::Draft,
                'reading_minutes' => Post::estimateReadingMinutes($input['post']['body']),
            ]))->save();

            $this->syncTags($post, $input['tags']);

            $this->auditLogger->record(
                event: 'post.created',
                auditable: $post,
                newValues: ['slug' => $post->slug, 'title' => $post->title],
                user: $lockedActor,
            );

            return $post->fresh(['tags', 'category']);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Post $post, array $attributes): Post
    {
        $input = $this->validated($attributes, $post);

        return DB::transaction(function () use ($actor, $post, $input): Post {
            $lockedActor = $this->lockedEditor($actor);

            $locked = Post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'An archived post cannot be edited. Restore it to a draft first.',
                ]);
            }

            $changes = array_merge($input['post'], [
                'reading_minutes' => Post::estimateReadingMinutes($input['post']['body']),
            ]);

            // Only a post that has never been public may take a new slug.
            if ($locked->published_at === null && filled($input['slug'] ?? null)) {
                $changes['slug'] = $this->uniqueSlug((string) $input['slug'], $locked->getKey());
            }

            $locked->forceFill($changes)->save();

            $this->syncTags($locked, $input['tags']);

            $this->auditLogger->record(
                event: 'post.updated',
                auditable: $locked,
                newValues: ['slug' => $locked->slug, 'title' => $locked->title],
                user: $lockedActor,
            );

            return $locked->fresh(['tags', 'category']);
        }, 3);
    }

    /**
     * Replaces the tag set.
     *
     * @param  list<string>  $tags
     */
    private function syncTags(Post $post, array $tags): void
    {
        $post->tags()->delete();

        foreach ($tags as $tag) {
            PostTag::query()->create(['post_id' => $post->getKey(), 'tag' => $tag]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{post: array<string, mixed>, tags: list<string>, slug: string|null}
     */
    private function validated(array $attributes, ?Post $existing = null): array
    {
        $validated = Validator::make($attributes, [
            'title' => ['required', 'string', 'min:4', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'excerpt' => ['required', 'string', 'min:20', 'max:500'],
            'body' => ['required', 'string', 'min:50'],
            'post_category_id' => ['nullable', 'integer', 'exists:post_categories,id'],
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'is_featured' => ['nullable', 'boolean'],
            'tags' => ['nullable', 'array', 'max:12'],
            'tags.*' => ['nullable', 'string', 'max:60'],
        ])->validate();

        // Normalised and de-duplicated, so "Gorilla Trekking" and "gorilla
        // trekking" are one tag rather than two.
        $tags = collect($validated['tags'] ?? [])
            ->map(static fn (mixed $tag): string => PostTag::normalise((string) $tag))
            ->filter(static fn (string $tag): bool => $tag !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'post' => [
                'title' => trim((string) $validated['title']),
                'excerpt' => trim((string) $validated['excerpt']),
                'body' => (string) $validated['body'],
                'post_category_id' => $validated['post_category_id'] ?? null,
                'meta_title' => $this->nullable($validated['meta_title'] ?? null),
                'meta_description' => $this->nullable($validated['meta_description'] ?? null),
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
            ],
            'tags' => $tags,
            'slug' => $this->nullable($validated['slug'] ?? null),
        ];
    }

    /**
     * A unique slug, suffixed if the base is taken.
     *
     * Soft-deleted posts are counted: reusing the URL of a removed article
     * would silently serve different content at a link people already hold.
     */
    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'post';
        $slug = $base;
        $suffix = 1;

        while (Post::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
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

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Validation rule for a publication date, shared with the transition action. */
    public static function publishAtRule(): array
    {
        return ['nullable', 'date'];
    }

    public static function parseDate(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse(
            $value,
            (string) config('pisfa.business_timezone', 'Africa/Kampala'),
        )->utc();
    }

    /** @return array<int, string> */
    public static function statusRule(): array
    {
        return [Rule::enum(PostStatus::class)];
    }
}
