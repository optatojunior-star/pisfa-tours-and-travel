<?php

namespace App\Http\Requests\Tours;

use Illuminate\Foundation\Http\FormRequest;

class CancelTourBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('customerTourBooking') ?? $this->route('tourBooking');

        return $booking !== null && ($this->user()?->can('cancel', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm_cancellation' => ['accepted'],
        ];
    }
}
