<?php

namespace App\Http\Requests\Admin;

use App\Models\Review;
use Illuminate\Foundation\Http\FormRequest;

class ReplyToReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $review instanceof Review
            && ($this->user()?->can('reply', $review) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reply_body' => ['required', 'string', 'min:5', 'max:2000'],
        ];
    }
}
