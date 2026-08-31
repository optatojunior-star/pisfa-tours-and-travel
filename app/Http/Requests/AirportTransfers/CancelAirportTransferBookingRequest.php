<?php

namespace App\Http\Requests\AirportTransfers;

use App\Models\AirportTransferBooking;
use Illuminate\Foundation\Http\FormRequest;

class CancelAirportTransferBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $booking = $this->route('customerAirportTransferBooking');

        return $booking instanceof AirportTransferBooking
            && ($this->user()?->can('cancel', $booking) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm_cancellation' => ['required', 'accepted'],
        ];
    }
}
