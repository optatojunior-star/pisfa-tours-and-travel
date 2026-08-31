<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reviews\ModerateReview;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModerateReviewRequest;
use App\Http\Requests\Admin\ReplyToReviewRequest;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Review::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? ReviewStatus::tryFrom($statusInput) : null;

        $query = Review::query()
            ->with(['customer:id,name,email', 'reviewable', 'moderatedBy:id,name'])
            ->latest('id');

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        if (filled($request->query('q'))) {
            $search = trim((string) $request->query('q'));
            $query->where(fn (Builder $nested): Builder => $nested
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('title', 'like', '%'.$search.'%')
                ->orWhere('body', 'like', '%'.$search.'%'));
        }

        return view('admin.reviews.index', [
            'reviews' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'search' => $request->query('q'),
            'counts' => [
                'pending' => Review::query()->where('status', ReviewStatus::Pending->value)->count(),
                'published' => Review::query()->where('status', ReviewStatus::Published->value)->count(),
                'unpublished' => Review::query()->where('status', ReviewStatus::Unpublished->value)->count(),
                'rejected' => Review::query()->where('status', ReviewStatus::Rejected->value)->count(),
            ],
        ]);
    }

    public function show(Review $review): View
    {
        $this->authorize('view', $review);

        return view('admin.reviews.show', [
            'review' => $review->load([
                'customer:id,name,email',
                'reviewable',
                'booking',
                'repliedBy:id,name',
                'moderationEvents.actor:id,name',
            ]),
        ]);
    }

    public function moderate(
        ModerateReviewRequest $request,
        Review $review,
        ModerateReview $action,
    ): RedirectResponse {
        $action->execute(
            $request->user(),
            $review,
            ReviewStatus::from($request->validated('status')),
            $request->validated('note'),
        );

        return back()->with('success', 'The review was moderated and the rating summary was recalculated.');
    }

    public function reply(
        ReplyToReviewRequest $request,
        Review $review,
        ModerateReview $action,
    ): RedirectResponse {
        $action->reply($request->user(), $review, $request->validated('reply_body'));

        return back()->with('success', 'Your reply was saved.');
    }
}
