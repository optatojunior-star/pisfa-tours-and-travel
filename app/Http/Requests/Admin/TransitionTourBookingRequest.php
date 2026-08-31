<?php

namespace App\Http\Requests\Admin;

use App\Enums\TourBookingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionTourBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('tourBooking');

        return $booking !== null && ($this->user()?->can('transition', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TourBookingStatus::class)],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->input('status') === TourBookingStatus::Cancelled->value)],
        ];
    }
}
