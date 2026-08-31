<?php

namespace App\Http\Requests\CarHire;

use Illuminate\Foundation\Http\FormRequest;

class CancelCarHireBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->booking();

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

    private function booking(): mixed
    {
        return $this->route('customerCarHireBooking')
            ?? $this->route('customerHireBooking')
            ?? $this->route('carHireBooking')
            ?? $this->route('hireBooking');
    }
}
