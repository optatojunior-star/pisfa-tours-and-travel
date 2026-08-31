<?php

namespace App\Http\Requests\Admin;

use App\Enums\TourBookingStatus;
use App\Models\TourBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexTourBookingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', TourBooking::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TourBookingStatus::class)],
            'package_id' => ['nullable', 'integer', 'exists:tour_packages,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'assignment' => ['nullable', Rule::in(['assigned', 'unassigned'])],
            'period' => ['nullable', Rule::in(['upcoming', 'past', 'all'])],
        ];
    }
}
