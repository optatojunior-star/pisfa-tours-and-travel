<?php

namespace App\Actions\Reviews;

use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\ReviewSummary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a subject's rating totals from the reviews themselves.
 *
 * Deliberately a full recount rather than an incremental adjustment. An
 * increment has to be perfectly paired with every status change, edit, and
 * soft delete, and one missed pairing silently corrupts the average forever.
 * Recounting is cheap at this scale and is always right.
 */
class RecalculateReviewSummary
{
    public function execute(Model $subject): ReviewSummary
    {
        return DB::transaction(function () use ($subject): ReviewSummary {
            $summary = ReviewSummary::query()->firstOrCreate([
                'reviewable_type' => $subject->getMorphClass(),
                'reviewable_id' => $subject->getKey(),
            ]);

            $locked = ReviewSummary::query()
                ->whereKey($summary->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Only published reviews count. Soft-deleted rows are excluded by
            // the default scope, so a withdrawn review stops contributing.
            $rows = Review::query()
                ->forSubject($subject)
                ->where('status', ReviewStatus::Published->value)
                ->selectRaw('rating, COUNT(*) as tally')
                ->groupBy('rating')
                ->pluck('tally', 'rating');

            $counts = [];
            $total = 0;
            $sum = 0;

            for ($rating = 1; $rating <= 5; $rating++) {
                $tally = (int) ($rows[$rating] ?? 0);
                $counts['rating_'.$rating.'_count'] = $tally;
                $total += $tally;
                $sum += $tally * $rating;
            }

            $locked->forceFill(array_merge($counts, [
                'reviews_count' => $total,
                'rating_sum' => $sum,
            ]))->save();

            return $locked;
        }, 3);
    }
}
