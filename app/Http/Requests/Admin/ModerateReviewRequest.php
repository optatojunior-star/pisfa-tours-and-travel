<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReviewStatus;
use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $review instanceof Review
            && ($this->user()?->can('moderate', $review) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ReviewStatus::class)],
            'note' => [
                'nullable',
                'string',
                'min:5',
                'max:2000',
                // A rejection must be explained so the author can address it.
                Rule::requiredIf($this->input('status') === ReviewStatus::Rejected->value),
            ],
        ];
    }
}
