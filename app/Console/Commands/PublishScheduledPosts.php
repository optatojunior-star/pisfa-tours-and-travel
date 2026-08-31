<?php

namespace App\Console\Commands;

use App\Actions\Content\TransitionPost;
use App\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Promotes scheduled posts whose moment has arrived.
 *
 * The public scope requires Published *and* a date in the past, so a post is
 * never visible before this runs. A late sweep delays an article; it cannot
 * leak one.
 */
class PublishScheduledPosts extends Command
{
    protected $signature = 'content:publish-scheduled';

    protected $description = 'Publish scheduled blog posts whose publication time has passed';

    public function handle(TransitionPost $action): int
    {
        $now = CarbonImmutable::now();
        $published = 0;
        $failures = 0;

        Post::query()
            ->dueForPublication($now)
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($posts) use ($action, $now, &$published, &$failures): void {
                foreach ($posts as $post) {
                    try {
                        $published += $action->releaseScheduled($post, $now) ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->components->error(
                            "Post {$post->getKey()} could not be published and remains scheduled.",
                        );
                    }
                }
            });

        $this->components->info("Scheduled posts: {$published} published; {$failures} failure(s).");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
