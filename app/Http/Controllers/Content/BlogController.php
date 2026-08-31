<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostTag;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public blog.
 *
 * Every query goes through `Post::published()`, which checks both the status
 * and the date. There is no path here that reads a draft or a scheduled post.
 */
class BlogController extends Controller
{
    public function index(Request $request): View
    {
        $query = Post::query()
            ->published()
            ->with(['category:id,name,slug', 'author:id,name', 'media'])
            ->latest('published_at');

        $categorySlug = $request->query('category');
        $category = is_string($categorySlug) && $categorySlug !== ''
            ? PostCategory::query()->active()->where('slug', $categorySlug)->first()
            : null;

        if ($category !== null) {
            $query->where('post_category_id', $category->getKey());
        }

        $tag = $request->query('tag');

        if (is_string($tag) && $tag !== '') {
            $query->tagged(PostTag::normalise($tag));
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        return view('blog.index', [
            'posts' => $query->paginate(9)->withQueryString(),
            'categories' => PostCategory::query()->active()->ordered()->get(),
            'category' => $category,
            'tag' => is_string($tag) ? $tag : null,
            'search' => $request->query('q'),
            'featured' => Post::query()
                ->published()
                ->featured()
                ->with('media')
                ->latest('published_at')
                ->limit(3)
                ->get(),
        ]);
    }

    public function show(string $post): View
    {
        $article = Post::query()
            ->published()
            ->where('slug', $post)
            ->with(['category:id,name,slug', 'author:id,name', 'tags', 'media'])
            ->firstOrFail();

        return view('blog.show', [
            'post' => $article,
            // Related by category, never including the article itself.
            'related' => Post::query()
                ->published()
                ->whereKeyNot($article->getKey())
                ->when(
                    $article->post_category_id !== null,
                    fn ($query) => $query->where('post_category_id', $article->post_category_id),
                )
                ->with('media')
                ->latest('published_at')
                ->limit(3)
                ->get(),
        ]);
    }
}
