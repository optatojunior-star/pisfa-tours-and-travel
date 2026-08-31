<?php

namespace Tests\Feature\Content;

use App\Actions\Content\SavePost;
use App\Actions\Content\TransitionPost;
use App\Enums\AccountStatus;
use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    private function editor(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Ten things to know before a gorilla trek',
            'excerpt' => 'Permits, fitness, altitude, and what to pack for Bwindi.',
            'body' => str_repeat('Bwindi Impenetrable Forest is home to roughly half the world gorillas. ', 20),
            'tags' => ['Gorilla Trekking', 'gorilla trekking', 'Bwindi'],
        ], $overrides);
    }

    // ---- Public visibility, the property that matters --------------------

    public function test_only_published_posts_with_a_past_date_are_public(): void
    {
        $live = Post::factory()->published()->create(['title' => 'Live article']);
        $draft = Post::factory()->create(['title' => 'Draft article']);
        $scheduled = Post::factory()->scheduled()->create(['title' => 'Scheduled article']);
        $archived = Post::factory()->withStatus(PostStatus::Archived)->create(['title' => 'Archived article']);

        $response = $this->get(route('blog.index'))->assertOk();

        $response->assertSee($live->title);
        // A scheduling bug can only hide a post, never leak one: the public
        // scope requires the status *and* a date that has passed.
        $response->assertDontSee($draft->title);
        $response->assertDontSee($scheduled->title);
        $response->assertDontSee($archived->title);
    }

    public function test_a_scheduled_post_is_a_404_until_its_moment(): void
    {
        $post = Post::factory()->scheduled('2026-08-25 09:00:00')->create();

        $this->get(route('blog.show', $post->slug))->assertNotFound();

        $this->travelTo('2026-08-26 09:00:00');
        // Still scheduled: the sweep has not run, and the status is half the rule.
        $this->get(route('blog.show', $post->slug))->assertNotFound();

        app(TransitionPost::class)->releaseScheduled($post->fresh());

        $this->get(route('blog.show', $post->slug))->assertOk();
    }

    public function test_a_draft_is_a_404_even_with_its_slug(): void
    {
        $post = Post::factory()->create();

        $this->get(route('blog.show', $post->slug))->assertNotFound();
    }

    public function test_a_post_published_a_second_in_the_future_is_not_yet_visible(): void
    {
        Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->addSecond(),
            'title' => 'Not quite yet',
        ]);

        $this->get(route('blog.index'))->assertOk()->assertDontSee('Not quite yet');
    }

    // ---- The scheduling sweep --------------------------------------------

    public function test_the_sweep_publishes_only_what_is_due(): void
    {
        $due = Post::factory()->scheduled('2026-08-19 09:00:00')->create();
        $notYet = Post::factory()->scheduled('2026-09-01 09:00:00')->create();
        $draft = Post::factory()->create();

        $this->artisan('content:publish-scheduled')->assertSuccessful();

        $this->assertSame(PostStatus::Published, $due->fresh()->status);
        $this->assertSame(PostStatus::Scheduled, $notYet->fresh()->status);
        // A draft has no date and is never promoted by a sweep.
        $this->assertSame(PostStatus::Draft, $draft->fresh()->status);
    }

    public function test_the_sweep_is_safe_to_run_twice(): void
    {
        $post = Post::factory()->scheduled('2026-08-19 09:00:00')->create();

        $this->artisan('content:publish-scheduled')->assertSuccessful();
        $publishedAt = $post->fresh()->published_at;

        $this->travelTo(now()->addHour());
        $this->artisan('content:publish-scheduled')->assertSuccessful();

        // The date is the editor's decision, not the sweep's.
        $this->assertEquals($publishedAt, $post->fresh()->published_at);
    }

    // ---- The slug is a URL -----------------------------------------------

    public function test_a_slug_is_derived_and_unique(): void
    {
        $editor = $this->editor();
        $action = app(SavePost::class);

        $first = $action->create($editor, $this->payload());
        $second = $action->create($editor, $this->payload());

        $this->assertSame('ten-things-to-know-before-a-gorilla-trek', $first->slug);
        $this->assertSame('ten-things-to-know-before-a-gorilla-trek-2', $second->slug);
    }

    public function test_a_slug_is_frozen_once_the_post_has_been_public(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());
        app(TransitionPost::class)->publish($editor, $post);

        $original = $post->fresh()->slug;

        app(SavePost::class)->update($editor, $post->fresh(), $this->payload([
            'title' => 'A completely different headline',
            'slug' => 'a-completely-different-headline',
        ]));

        // Changing a live URL silently breaks every link anyone has shared.
        $this->assertSame($original, $post->fresh()->slug);
        $this->assertSame('A completely different headline', $post->fresh()->title);
    }

    public function test_an_unpublished_draft_may_still_take_a_new_slug(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());

        app(SavePost::class)->update($editor, $post, $this->payload(['slug' => 'a-better-url']));

        $this->assertSame('a-better-url', $post->fresh()->slug);
    }

    public function test_a_removed_posts_url_is_never_reused(): void
    {
        $editor = $this->editor();
        $first = app(SavePost::class)->create($editor, $this->payload());
        $first->delete();

        $second = app(SavePost::class)->create($editor, $this->payload());

        // Serving different content at a link people already hold would be
        // worse than a 404.
        $this->assertNotSame($first->slug, $second->slug);
    }

    // ---- Tags ------------------------------------------------------------

    public function test_tags_are_normalised_and_deduplicated(): void
    {
        $post = app(SavePost::class)->create($this->editor(), $this->payload());

        // "Gorilla Trekking" and "gorilla trekking" are one tag, not two.
        $this->assertSame(['bwindi', 'gorilla-trekking'], $post->fresh()->tagList());
    }

    public function test_filtering_by_tag_finds_the_post(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());
        app(TransitionPost::class)->publish($editor, $post);

        $other = Post::factory()->published()->create(['title' => 'Unrelated article']);

        $this->get(route('blog.index', ['tag' => 'Gorilla Trekking']))
            ->assertOk()
            ->assertSee($post->fresh()->title)
            ->assertDontSee($other->title);
    }

    public function test_a_tag_cannot_be_duplicated_on_one_post(): void
    {
        $post = Post::factory()->create();
        PostTag::query()->create(['post_id' => $post->getKey(), 'tag' => 'safari']);

        $this->expectException(QueryException::class);

        PostTag::query()->create(['post_id' => $post->getKey(), 'tag' => 'safari']);
    }

    // ---- Editorial lifecycle ---------------------------------------------

    public function test_a_future_date_schedules_rather_than_publishes(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());

        $scheduled = app(TransitionPost::class)->publish($editor, $post, '2026-09-01 08:00:00');

        // "Live on Friday" and "live now" must never be the same state.
        $this->assertSame(PostStatus::Scheduled, $scheduled->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'post.scheduled']);
    }

    public function test_publishing_with_no_date_goes_live_now(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());

        $published = app(TransitionPost::class)->publish($editor, $post);

        $this->assertSame(PostStatus::Published, $published->status);
        $this->assertTrue($published->isPublishedAt());
        $this->assertDatabaseHas('audit_logs', ['event' => 'post.published']);
    }

    public function test_unpublishing_keeps_the_original_date(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());
        app(TransitionPost::class)->publish($editor, $post);
        $firstPublished = $post->fresh()->published_at;

        app(TransitionPost::class)->unpublish($editor, $post->fresh());

        $fresh = $post->fresh();
        $this->assertSame(PostStatus::Draft, $fresh->status);
        // A post taken down and put back should not lose the day it first ran.
        $this->assertEquals($firstPublished, $fresh->published_at);
        $this->assertFalse($fresh->isPublishedAt());
    }

    public function test_an_archived_post_cannot_be_edited(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());
        app(TransitionPost::class)->archive($editor, $post);

        $this->expectException(ValidationException::class);

        app(SavePost::class)->update($editor, $post->fresh(), $this->payload(['title' => 'Quietly changed']));
    }

    public function test_an_archived_post_returns_through_draft(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());
        app(TransitionPost::class)->archive($editor, $post);

        // Reviewed before it is public again.
        $this->expectException(ValidationException::class);

        app(TransitionPost::class)->publish($editor, $post->fresh());
    }

    public function test_reading_time_is_computed_on_save(): void
    {
        $post = app(SavePost::class)->create($this->editor(), $this->payload([
            'body' => str_repeat('word ', 800),
        ]));

        // Roughly 200 words a minute, floored at one.
        $this->assertSame(4, $post->reading_minutes);
    }

    public function test_seo_falls_back_to_the_posts_own_words(): void
    {
        $post = app(SavePost::class)->create($this->editor(), $this->payload());

        $this->assertSame($post->title, $post->metaTitle());
        $this->assertSame($post->excerpt, $post->metaDescription());

        $withMeta = app(SavePost::class)->create($this->editor(), $this->payload([
            'title' => 'Another article about safaris',
            'meta_title' => 'Custom SEO title',
        ]));

        $this->assertSame('Custom SEO title', $withMeta->metaTitle());
    }

    // ---- Authorisation ---------------------------------------------------

    public function test_a_customer_cannot_write_or_publish(): void
    {
        $customer = $this->user(UserRole::Customer);

        $this->expectException(AuthorizationException::class);

        app(SavePost::class)->create($customer, $this->payload());
    }

    public function test_a_suspended_editor_cannot_publish(): void
    {
        $editor = $this->editor();
        $post = app(SavePost::class)->create($editor, $this->payload());

        $editor->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->expectException(AuthorizationException::class);

        app(TransitionPost::class)->publish($editor->fresh(), $post);
    }

    public function test_a_customer_cannot_reach_the_console(): void
    {
        $customer = $this->user(UserRole::Customer);
        $post = Post::factory()->create();

        $this->actingAs($customer)->get(route('admin.posts.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.posts.edit', $post))->assertForbidden();
    }

    // ---- HTTP ------------------------------------------------------------

    public function test_an_editor_writes_and_publishes_over_http(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('admin.posts.create'))->assertOk();

        $this->actingAs($editor)
            ->post(route('admin.posts.store'), $this->payload(['tags' => 'safari, bwindi']))
            ->assertRedirect();

        $post = Post::query()->sole();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame(['bwindi', 'safari'], $post->tagList());

        $this->actingAs($editor)
            ->post(route('admin.posts.publish', $post))
            ->assertRedirect();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->get(route('blog.show', $post->slug))->assertOk();
    }

    public function test_the_console_filters_by_status(): void
    {
        $editor = $this->editor();
        $draft = Post::factory()->create(['title' => 'Draft headline']);
        $live = Post::factory()->published()->create(['title' => 'Live headline']);

        $this->actingAs($editor)
            ->get(route('admin.posts.index', ['status' => PostStatus::Draft->value]))
            ->assertOk()
            ->assertSee($draft->title)
            ->assertDontSee($live->title);
    }

    public function test_an_invalid_slug_is_rejected(): void
    {
        $this->actingAs($this->editor())
            ->post(route('admin.posts.store'), $this->payload(['slug' => 'Not A Valid Slug!']))
            ->assertSessionHasErrors('slug');
    }

    public function test_the_blog_index_filters_by_category(): void
    {
        $category = PostCategory::factory()->create(['name' => 'Safari tips']);
        $inCategory = Post::factory()->published()->inCategory($category)->create(['title' => 'Packing for a safari']);
        $other = Post::factory()->published()->create(['title' => 'Something else entirely']);

        $this->get(route('blog.index', ['category' => $category->slug]))
            ->assertOk()
            ->assertSee($inCategory->title)
            ->assertDontSee($other->title);
    }

    public function test_an_empty_blog_says_so(): void
    {
        $this->get(route('blog.index'))->assertOk()->assertSee('No articles yet');
    }
}
