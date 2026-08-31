<?php

namespace App\Http\Requests\Admin;

use App\Enums\SelfDriveApplicationStatus;
use App\Models\CarHireBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewSelfDriveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('carHireBooking');

        return $booking instanceof CarHireBooking && ($this->user()?->can('reviewSelfDriveApplication', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                SelfDriveApplicationStatus::NeedsInformation->value,
                SelfDriveApplicationStatus::Approved->value,
                SelfDriveApplicationStatus::Rejected->value,
            ])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'internal_review_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
