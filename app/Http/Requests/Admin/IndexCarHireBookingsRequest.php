<?php

namespace App\Http\Requests\Admin;

use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Models\CarHireBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCarHireBookingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', CarHireBooking::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(CarHireBookingStatus::class)],
            'hire_mode' => ['nullable', Rule::enum(HireMode::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
