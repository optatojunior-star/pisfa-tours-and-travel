<?php

namespace App\Http\Controllers\Reviews;

use App\Actions\Reviews\RecalculateReviewSummary;
use App\Actions\Reviews\SubmitReview;
use App\Enums\ReviewModerationAction;
use App\Enums\ReviewStatus;
use App\Enums\TourBookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\StoreReviewRequest;
use App\Models\Review;
use App\Models\ReviewModerationEvent;
use App\Models\TourBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    public function __construct(private readonly RecalculateReviewSummary $summaries) {}

    public function index(Request $request): View
    {
        return view('reviews.index', [
            'reviews' => Review::query()
                ->forCustomer($request->user())
                ->with(['reviewable', 'repliedBy:id,name'])
                ->latest('id')
                ->paginate(10),
            // Completed bookings not yet reviewed: what the customer can still
            // write about.
            'reviewable' => TourBooking::query()
                ->forCustomer($request->user())
                ->where('status', TourBookingStatus::Completed->value)
                ->whereDoesntHave('review')
                ->with('tourPackage:id,name')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function create(TourBooking $customerTourBooking): View
    {
        $this->authorize('create', Review::class);

        return view('reviews.create', ['booking' => $customerTourBooking]);
    }

    public function store(
        StoreReviewRequest $request,
        TourBooking $customerTourBooking,
        SubmitReview $action,
    ): RedirectResponse {
        $action->execute($request->user(), $customerTourBooking, $request->validated());

        return redirect()
            ->route('portal.reviews.index')
            ->with('success', 'Thank you. Your review has been sent for moderation and will appear once approved.');
    }

    public function edit(Review $customerReview): View
    {
        $this->authorize('update', $customerReview);

        return view('reviews.edit', ['review' => $customerReview]);
    }

    /**
     * An edit always returns the review to moderation. A published review must
     * not be silently rewritten after approval.
     */
    public function update(StoreReviewRequest $request, Review $customerReview): RedirectResponse
    {
        $this->authorize('update', $customerReview);

        $previous = $customerReview->status;

        $customerReview->forceFill([
            'rating' => (int) $request->validated('rating'),
            'title' => $request->validated('title'),
            'body' => $request->validated('body'),
            'status' => ReviewStatus::Pending,
            'published_at' => null,
        ])->save();

        ReviewModerationEvent::query()->create([
            'review_id' => $customerReview->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => ReviewModerationAction::Edited,
            'from_status' => $previous,
            'to_status' => ReviewStatus::Pending,
        ]);

        // The subject loses this review from its average until re-approved.
        if ($customerReview->reviewable !== null) {
            $this->summaries->execute($customerReview->reviewable);
        }

        return redirect()
            ->route('portal.reviews.index')
            ->with('success', 'Your review was updated and sent back for moderation.');
    }

    public function destroy(Request $request, Review $customerReview): RedirectResponse
    {
        $this->authorize('delete', $customerReview);

        $subject = $customerReview->reviewable;

        ReviewModerationEvent::query()->create([
            'review_id' => $customerReview->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => ReviewModerationAction::Deleted,
            'from_status' => $customerReview->status,
        ]);

        $customerReview->delete();

        if ($subject !== null) {
            $this->summaries->execute($subject);
        }

        return redirect()
            ->route('portal.reviews.index')
            ->with('success', 'Your review was withdrawn.');
    }
}
