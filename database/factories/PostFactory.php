<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(6);

        return [
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'excerpt' => fake()->paragraph(2),
            'body' => implode("\n\n", fake()->paragraphs(6)),
            'post_category_id' => null,
            'author_user_id' => null,
            'status' => PostStatus::Draft,
            'is_featured' => false,
            'reading_minutes' => 3,
        ];
    }

    public function inCategory(PostCategory $category): static
    {
        return $this->state(fn (): array => ['post_category_id' => $category->getKey()]);
    }

    public function by(User $author): static
    {
        return $this->state(fn (): array => ['author_user_id' => $author->getKey()]);
    }

    public function published(?string $at = null): static
    {
        return $this->state(fn (): array => [
            'status' => PostStatus::Published,
            'published_at' => $at ?? now()->subDay(),
        ]);
    }

    /** Scheduled for a moment that has not arrived. */
    public function scheduled(?string $at = null): static
    {
        return $this->state(fn (): array => [
            'status' => PostStatus::Scheduled,
            'published_at' => $at ?? now()->addDay(),
        ]);
    }

    public function withStatus(PostStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'published_at' => $status === PostStatus::Published ? now()->subDay() : null,
            'archived_at' => $status === PostStatus::Archived ? now() : null,
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (): array => ['is_featured' => true]);
    }
}
