<?php

namespace App\Http\Requests\Admin;

use App\Enums\CarHireBookingStatus;
use App\Models\CarHireBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionCarHireBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('carHireBooking');

        return $booking instanceof CarHireBooking && ($this->user()?->can('transition', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(CarHireBookingStatus::class)],
            'reason' => ['nullable', 'string', 'min:5', 'max:1000'],
        ];
    }
}
