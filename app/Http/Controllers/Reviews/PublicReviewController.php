<?php

namespace App\Http\Controllers\Reviews;

use App\Actions\Reviews\SubmitPublicReview;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TourPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Leaving a review without an account.
 *
 * Reviews existed and were unreachable: they required a completed booking made
 * by a registered customer, and most PISFA customers arrange their trip over
 * WhatsApp and never sign up. The result was a review system with a moderation
 * queue, a summary table and a public display, which nobody could put anything
 * into.
 *
 * The subject is resolved by its public scope, so a review cannot be attached
 * to a draft tour or one that has been taken down — the same rule that decides
 * whether the page is visible at all decides whether it can be reviewed.
 */
class PublicReviewController extends Controller
{
    public function store(
        Request $request,
        TourPackage $tourPackage,
        SubmitPublicReview $action,
    ): RedirectResponse {
        // Published-only, and a 404 rather than a 403: a draft tour should not
        // confirm its own existence to somebody guessing slugs.
        abort_unless(
            TourPackage::query()->published()->whereKey($tourPackage->getKey())->exists(),
            404,
        );

        $action->execute(
            subject: $tourPackage,
            attributes: $request->only(['rating', 'title', 'body', 'guest_name', 'guest_email']),
            // A signed-in customer keeps their identity on the review rather
            // than being treated as a stranger on their own site.
            customer: $request->user()?->hasRole(UserRole::Customer) ? $request->user() : null,
        );

        // Back to the tour by name rather than back(). A form post with no
        // referer — a privacy extension, a stripped header — would otherwise
        // land the writer on the homepage with no sign their review arrived.
        return redirect()
            ->to(route('tours.show', $tourPackage).'#write-review-heading')
            ->with('review_submitted', true)
            ->with('success', 'Thank you. Your review has been sent to us and will appear once it has been read.');
    }
}
