<?php

namespace App\Models\Concerns;

use App\Models\Review;
use App\Models\ReviewSummary;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A subject that customers can review: a tour package, a vehicle, an airport
 * transfer route.
 *
 * `publishedReviews` is the only relation a public page may render. The plain
 * `reviews` relation includes pending and rejected rows and belongs to the
 * admin console alone.
 */
trait HasReviews
{
    public function reviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable');
    }

    public function publishedReviews(): MorphMany
    {
        return $this->morphMany(Review::class, 'reviewable')
            ->published()
            ->latest('published_at')
            ->latest('id');
    }

    public function reviewSummary(): MorphOne
    {
        return $this->morphOne(ReviewSummary::class, 'reviewable');
    }
}
