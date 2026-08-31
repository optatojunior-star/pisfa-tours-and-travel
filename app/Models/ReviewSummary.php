<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Denormalised rating totals for a reviewable subject.
 *
 * Sum and count are stored rather than an average, so the mean stays exact,
 * recomputable, and free of any persisted float.
 *
 * @property int $reviews_count
 * @property int $rating_sum
 */
class ReviewSummary extends Model
{
    use HasFactory;

    protected $fillable = [
        'reviewable_type',
        'reviewable_id',
        'reviews_count',
        'rating_sum',
        'rating_1_count',
        'rating_2_count',
        'rating_3_count',
        'rating_4_count',
        'rating_5_count',
    ];

    protected function casts(): array
    {
        return [
            'reviews_count' => 'integer',
            'rating_sum' => 'integer',
            'rating_1_count' => 'integer',
            'rating_2_count' => 'integer',
            'rating_3_count' => 'integer',
            'rating_4_count' => 'integer',
            'rating_5_count' => 'integer',
        ];
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function hasReviews(): bool
    {
        return $this->reviews_count > 0;
    }

    /** Mean rating to one decimal place, or null when there are none. */
    public function average(): ?float
    {
        if ($this->reviews_count < 1) {
            return null;
        }

        return round($this->rating_sum / $this->reviews_count, 1);
    }

    public function formattedAverage(): string
    {
        $average = $this->average();

        return $average === null ? 'No reviews yet' : number_format($average, 1).' out of 5';
    }

    /** Whole-star count for a simple star row. */
    public function roundedStars(): int
    {
        $average = $this->average();

        return $average === null ? 0 : (int) round($average);
    }

    public function countFor(int $rating): int
    {
        return (int) ($this->{'rating_'.$rating.'_count'} ?? 0);
    }

    /** Share of reviews at a given rating, for the distribution bars. */
    public function percentageFor(int $rating): int
    {
        if ($this->reviews_count < 1) {
            return 0;
        }

        return (int) round($this->countFor($rating) / $this->reviews_count * 100);
    }
}
