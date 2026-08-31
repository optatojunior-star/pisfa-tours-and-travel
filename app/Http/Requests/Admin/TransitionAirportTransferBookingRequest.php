<?php

namespace App\Http\Requests\Admin;

use App\Enums\AirportTransferBookingStatus;
use App\Models\AirportTransferBooking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionAirportTransferBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('airportTransferBooking');

        return $booking instanceof AirportTransferBooking
            && ($this->user()?->can('transition', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AirportTransferBookingStatus::class)],
            'reason' => [
                'nullable',
                'string',
                'min:5',
                'max:1000',
                Rule::requiredIf(in_array($this->input('status'), [
                    AirportTransferBookingStatus::Cancelled->value,
                    AirportTransferBookingStatus::Declined->value,
                ], true)),
            ],
        ];
    }
}
