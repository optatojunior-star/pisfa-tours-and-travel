<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\SavePost;
use App\Actions\Content\TransitionPost;
use App\Enums\DocumentCategory;
use App\Enums\PostStatus;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePostRequest;
use App\Models\Post;
use App\Models\PostCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PostController extends Controller
{
    use HandlesImageUploads;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Post::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? PostStatus::tryFrom($statusInput) : null;

        $query = Post::query()
            ->with(['category:id,name', 'author:id,name'])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('admin.posts.index', [
            'posts' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'counts' => [
                'draft' => Post::query()->where('status', PostStatus::Draft->value)->count(),
                'scheduled' => Post::query()->where('status', PostStatus::Scheduled->value)->count(),
                'published' => Post::query()->where('status', PostStatus::Published->value)->count(),
                'archived' => Post::query()->where('status', PostStatus::Archived->value)->count(),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Post::class);

        return view('admin.posts.create', [
            'post' => null,
            'categories' => PostCategory::query()->active()->ordered()->get(),
        ]);
    }

    public function store(SavePostRequest $request, SavePost $action): RedirectResponse
    {
        $post = $action->create($request->user(), $request->validated());
        $rejected = $this->storeUploadedImages($request, $post, DocumentCategory::BlogMedia);

        return $this->withRejectedImages(
            redirect()
                ->route('admin.posts.edit', $post)
                ->with('success', 'Draft saved. Publish it when you are ready.'),
            $rejected,
        );
    }

    public function edit(Post $post): View
    {
        $this->authorize('update', $post);

        return view('admin.posts.edit', [
            'post' => $post->load(['tags', 'category', 'media']),
            'categories' => PostCategory::query()->active()->ordered()->get(),
        ]);
    }

    public function update(SavePostRequest $request, Post $post, SavePost $action): RedirectResponse
    {
        $action->update($request->user(), $post, $request->validated());
        $rejected = $this->storeUploadedImages($request, $post, DocumentCategory::BlogMedia);

        return $this->withRejectedImages(
            redirect()
                ->route('admin.posts.edit', $post)
                ->with('success', 'The post was updated.'),
            $rejected,
        );
    }

    public function publish(Request $request, Post $post, TransitionPost $action): RedirectResponse
    {
        $this->authorize('publish', $post);

        $validated = $request->validate([
            'publish_at' => ['nullable', 'date'],
        ]);

        $updated = $action->publish($request->user(), $post, $validated['publish_at'] ?? null);

        return back()->with('success', $updated->status === PostStatus::Scheduled
            ? 'Scheduled. It goes live automatically at the time you set.'
            : 'Published. It is live now.');
    }

    public function unpublish(Request $request, Post $post, TransitionPost $action): RedirectResponse
    {
        $this->authorize('publish', $post);

        $action->unpublish($request->user(), $post);

        return back()->with('success', 'Taken off the site and returned to draft.');
    }

    public function archive(Request $request, Post $post, TransitionPost $action): RedirectResponse
    {
        $this->authorize('publish', $post);

        $action->archive($request->user(), $post);

        return back()->with('success', 'Archived.');
    }
}
